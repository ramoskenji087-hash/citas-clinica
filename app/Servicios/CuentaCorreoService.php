<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Validacion\Validador;
use InvalidArgumentException;
use Throwable;

/**
 * Activación de cuentas y recuperación de contraseña por correo.
 *
 * - Cada enlace es de un solo uso y tiene vigencia: 48 horas para activar la cuenta y 1 hora para recuperarla.
 * - En la base solo se guarda el hash SHA-256 del token; el token en claro viaja únicamente en el correo.
 * - Al crear un enlace nuevo se anulan los anteriores del mismo tipo.
 * - Al definir la contraseña se anulan todos los enlaces pendientes de la cuenta.
 */
final class CuentaCorreoService
{
    /** Vigencia de cada tipo de enlace. */
    private const VIGENCIA = [
        'activacion'   => '+48 hours',
        'recuperacion' => '+1 hour',
    ];

    /** Tiempo mínimo entre dos correos de recuperación de la misma cuenta. */
    private const ESPERA_RECUPERACION = '-2 minutes';

    /**
     * Crea un token nuevo y anula los anteriores del mismo tipo.
     * Devuelve el token en claro: solo debe enviarse por correo.
     */
    public static function crearToken(int $idUsuario, string $tipo): string
    {
        if (!isset(self::VIGENCIA[$tipo])) {
            throw new InvalidArgumentException('Tipo de enlace no válido.');
        }

        $pdo = Conexion::obtener();
        $token = bin2hex(random_bytes(32));

        $anular = $pdo->prepare(
            'UPDATE token_usuario SET usado = TRUE
              WHERE id_usuario = :usuario AND tipo = :tipo AND usado = FALSE'
        );
        $anular->execute([':usuario' => $idUsuario, ':tipo' => $tipo]);

        $guardar = $pdo->prepare(
            'INSERT INTO token_usuario (id_usuario, tipo, token_hash, expira)
             VALUES (:usuario, :tipo, :hash, :expira)'
        );
        $guardar->execute([
            ':usuario' => $idUsuario,
            ':tipo'    => $tipo,
            ':hash'    => hash('sha256', $token),
            ':expira'  => date('Y-m-d H:i:s', strtotime(self::VIGENCIA[$tipo])),
        ]);

        return $token;
    }

    /**
     * Datos de la cuenta a la que pertenece un enlace vigente, o null si el enlace no existe,
     * ya se usó, venció o la cuenta está desactivada.
     *
     * @return array{id_token: int, tipo: string, id_usuario: int, correo: string, nombres: string, activo: int}|null
     */
    public static function validarToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $consulta = Conexion::obtener()->prepare(
            'SELECT t.id_token, t.tipo, u.id_usuario, u.correo, u.nombres, u.activo::int AS activo
               FROM token_usuario t
               JOIN usuario u ON u.id_usuario = t.id_usuario
              WHERE t.token_hash = :hash
                AND t.usado = FALSE
                AND t.expira > :ahora'
        );
        $consulta->execute([
            ':hash'  => hash('sha256', $token),
            ':ahora' => date('Y-m-d H:i:s'),
        ]);
        $fila = $consulta->fetch();

        if ($fila === false || (int) $fila['activo'] !== 1) {
            return null;
        }

        return $fila;
    }

    /**
     * Define la contraseña con un enlace vigente y anula todos los enlaces pendientes de la cuenta.
     * Si la contraseña no cumple las reglas, el enlace sigue sirviendo para intentarlo otra vez.
     *
     * @throws ReglaException si el enlace no sirve o la contraseña no cumple las reglas
     */
    public static function definirClave(string $token, string $clave): void
    {
        $datos = self::validarToken($token);

        if ($datos === null) {
            throw new ReglaException('El enlace no es válido o ya venció. Solicite uno nuevo.');
        }

        $error = Validador::clave($clave);
        if ($error !== null) {
            throw new ReglaException($error);
        }

        $idUsuario = (int) $datos['id_usuario'];
        $pdo = Conexion::obtener();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('UPDATE usuario SET password_hash = :hash WHERE id_usuario = :usuario')
                ->execute([':hash' => password_hash($clave, PASSWORD_DEFAULT), ':usuario' => $idUsuario]);

            $pdo->prepare('UPDATE token_usuario SET usado = TRUE WHERE id_usuario = :usuario AND usado = FALSE')
                ->execute([':usuario' => $idUsuario]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Envía el enlace para activar la cuenta y definir la contraseña (o para definirla de nuevo). */
    public static function enviarActivacion(int $idUsuario): void
    {
        $usuario = AdministracionUsuariosService::obtener($idUsuario);

        if ($usuario === null) {
            throw new ReglaException('La cuenta no existe.');
        }

        $nombre = (string) $usuario['nombres'];
        $enlace = self::enlace(self::crearToken($idUsuario, 'activacion'));

        CorreoService::enviar(
            (string) $usuario['correo'],
            $nombre,
            'Active su cuenta en Clínica Vida Salud',
            self::html(
                'Bienvenido(a), ' . $nombre . '.',
                'Se creó una cuenta para usted en el sistema de citas de la clínica. Para activarla, elija su contraseña con el botón de abajo. El enlace vence en 48 horas.',
                $enlace,
                'Elegir mi contraseña'
            ),
            "Hola, $nombre. Para activar su cuenta en Clínica Vida Salud, elija su contraseña en este enlace (vence en 48 horas):\n$enlace"
        );
    }

    /**
     * Envía el enlace para recuperar la contraseña. No revela si el correo existe:
     * si la cuenta no existe, está inactiva o ya recibió un correo hace poco, no hace nada.
     */
    public static function enviarRecuperacion(string $correo): void
    {
        $pdo = Conexion::obtener();

        $consulta = $pdo->prepare(
            'SELECT id_usuario, nombres, correo
               FROM usuario
              WHERE lower(correo) = lower(:correo) AND activo = TRUE'
        );
        $consulta->execute([':correo' => $correo]);
        $usuario = $consulta->fetch();

        if ($usuario === false) {
            return;
        }

        $idUsuario = (int) $usuario['id_usuario'];

        $reciente = $pdo->prepare(
            "SELECT 1 FROM token_usuario
              WHERE id_usuario = :usuario AND tipo = 'recuperacion' AND fecha_creacion > :desde"
        );
        $reciente->execute([
            ':usuario' => $idUsuario,
            ':desde'   => date('Y-m-d H:i:s', strtotime(self::ESPERA_RECUPERACION)),
        ]);

        if ($reciente->fetchColumn() !== false) {
            return;
        }

        $nombre = (string) $usuario['nombres'];
        $enlace = self::enlace(self::crearToken($idUsuario, 'recuperacion'));

        CorreoService::enviar(
            (string) $usuario['correo'],
            $nombre,
            'Restablecer su contraseña en Clínica Vida Salud',
            self::html(
                'Restablecer su contraseña',
                'Recibimos una solicitud para cambiar la contraseña de su cuenta. Si fue usted, elija una nueva con el botón de abajo. El enlace vence en 1 hora. Si no lo solicitó, ignore este correo: su contraseña no cambiará.',
                $enlace,
                'Elegir nueva contraseña'
            ),
            "Hola, $nombre. Para elegir una nueva contraseña en Clínica Vida Salud, use este enlace (vence en 1 hora). Si no lo solicitó, ignore este correo:\n$enlace"
        );
    }

    /** Dirección pública de la página para definir la contraseña. */
    private static function enlace(string $token): string
    {
        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

        return $config['url_base'] . '/definir_clave.php?token=' . $token;
    }

    /** Correo en HTML sencillo, con el texto escapado. */
    private static function html(string $titulo, string $texto, string $enlace, string $boton): string
    {
        $titulo = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $texto = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
        $enlaceEscapado = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');
        $boton = htmlspecialchars($boton, ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:Arial,sans-serif;color:#1F2D3A;max-width:560px">'
            . '<h2 style="color:#0E5A6E">' . $titulo . '</h2>'
            . '<p>' . $texto . '</p>'
            . '<p><a href="' . $enlaceEscapado . '" style="background:#0E5A6E;color:#ffffff;padding:12px 18px;'
            . 'border-radius:6px;text-decoration:none;display:inline-block">' . $boton . '</a></p>'
            . '<p>Si el botón no funciona, copie este enlace en su navegador:<br>' . $enlaceEscapado . '</p>'
            . '</div>';
    }
}
