<?php
declare(strict_types=1);

namespace App\Seguridad;

/**
 * Control de sesión y de acceso (RF01, RN12, sección 7).
 *
 * - La cookie de sesión es HttpOnly (JavaScript no puede leerla) y SameSite=Strict
 *   (el navegador no la envía desde otros sitios, lo que ayuda contra CSRF).
 * - El ID de sesión cambia al iniciar sesión (session_regenerate_id), para que
 *   nadie pueda fijar una sesión ajena antes de que el usuario entre.
 * - Cada formulario POST lleva un token CSRF que se compara con hash_equals.
 *
 * Llame a iniciar() antes de cualquier salida de la página.
 */
final class Sesion
{
    private const NOMBRE_SESION = 'citas_clinica_sid';

    /** Inicia la sesión con la configuración segura. */
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::NOMBRE_SESION);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false, // cambiar a true cuando el sistema use HTTPS
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    /**
     * Guarda los datos del usuario después de comprobar su contraseña.
     * El ID de sesión se regenera aquí para evitar la fijación de sesión.
     */
    public static function iniciarUsuario(array $usuario): void
    {
        session_regenerate_id(true);
        unset($_SESSION['csrf']);

        $_SESSION['usuario'] = [
            'id'          => (int) $usuario['id_usuario'],
            'nombre'      => $usuario['nombres'] . ' ' . $usuario['apellidos'],
            'id_rol'      => (int) $usuario['id_rol'],
            // Vínculos con la ficha del médico o del paciente. Las páginas los usan
            // para limitar los datos: el paciente solo ve sus propias citas.
            'id_medico'   => $usuario['id_medico'] !== null ? (int) $usuario['id_medico'] : null,
            'id_paciente' => $usuario['id_paciente'] !== null ? (int) $usuario['id_paciente'] : null,
        ];
    }

    public static function estaAutenticado(): bool
    {
        return isset($_SESSION['usuario']);
    }

    /** Datos del usuario de la sesión, o null si nadie ha iniciado sesión. */
    public static function usuarioActual(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    /** Si no hay sesión, envía a la pantalla de acceso (P9). */
    public static function requerirSesion(): void
    {
        if (!self::estaAutenticado()) {
            header('Location: login.php?motivo=sesion');
            exit;
        }
    }

    /**
     * Permite la página solo a los roles indicados (RN12).
     * Si la sesión no tiene uno de esos roles, responde 403 con un mensaje genérico.
     *
     * @param int[] $roles Roles permitidos, por ejemplo [Roles::ADMINISTRADOR].
     */
    public static function requerirRol(array $roles): void
    {
        self::requerirSesion();

        if (!in_array((int) $_SESSION['usuario']['id_rol'], $roles, true)) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso denegado</title></head>'
                . '<body><p>No tiene permiso para ver esta página.</p>'
                . '<p><a href="inicio.php">Volver al inicio</a></p></body></html>';
            exit;
        }
    }

    /** Cierra la sesión y borra la cookie del navegador. */
    public static function cerrar(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $parametros['path'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }

        session_destroy();
    }

    /** Token que cada formulario POST debe enviar en un campo oculto. */
    public static function tokenCsrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    /** Compara el token recibido con el de la sesión, en tiempo constante. */
    public static function verificarCsrf(mixed $recibido): bool
    {
        $esperado = $_SESSION['csrf'] ?? '';

        return is_string($recibido) && $esperado !== '' && hash_equals($esperado, $recibido);
    }
}
