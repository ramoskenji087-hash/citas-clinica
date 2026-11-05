<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Validacion\Validador;

/**
 * Registro propio del paciente (sección 3.4 del documento: registro e inicio de sesión).
 *
 * Hay dos casos:
 * - Persona nueva: se crea su ficha de paciente y su cuenta.
 * - Ficha creada antes por Recepción, sin cuenta: se vincula la cuenta a esa ficha, pero solo si el
 *   correo coincide con el que la clínica tiene registrado.
 *
 * La cuenta se crea sin contraseña. La persona la elige con el enlace que le enviamos por correo.
 */
final class RegistroPacienteService
{
    /**
     * Revisa los datos antes de registrar. Devuelve los errores por campo.
     *
     * @param array<string, mixed> $datos
     * @return array<string, string>
     */
    public static function validar(array $datos): array
    {
        $errores = Validador::paciente($datos);

        if ((string) $datos['correo'] === '') {
            $errores['correo'] = 'El correo electrónico es obligatorio: allí le enviaremos el enlace para elegir su contraseña.';
        }

        if (preg_match('/^[A-Za-z0-9._-]{3,30}$/', (string) $datos['nombre_usuario']) !== 1) {
            $errores['nombre_usuario'] = 'El nombre de usuario debe tener de 3 a 30 caracteres: letras, números, punto, guion o guion bajo.';
        }

        return $errores;
    }

    /**
     * Registra al paciente y crea su cuenta, sin contraseña. No envía el correo: eso lo hace la página
     * después de este paso, para que las pruebas no dependan de internet.
     *
     * @param array<string, mixed> $datos
     * @return array{id_usuario: int, id_paciente: int}
     * @throws ReglaException si el usuario, el correo o la ficha no permiten registrar
     */
    public static function registrar(array $datos): array
    {
        $pdo = Conexion::obtener();
        $correo = (string) $datos['correo'];

        $usuarioExiste = $pdo->prepare('SELECT 1 FROM usuario WHERE lower(nombre_usuario) = lower(:nombre)');
        $usuarioExiste->execute([':nombre' => (string) $datos['nombre_usuario']]);
        if ($usuarioExiste->fetchColumn() !== false) {
            throw new ReglaException('Ese nombre de usuario ya existe. Elija otro.');
        }

        $correoExiste = $pdo->prepare('SELECT 1 FROM usuario WHERE lower(correo) = lower(:correo)');
        $correoExiste->execute([':correo' => $correo]);
        if ($correoExiste->fetchColumn() !== false) {
            throw new ReglaException('Ya existe una cuenta con ese correo. Si ya se registró, use "¿Olvidó su contraseña?".');
        }

        $ficha = $pdo->prepare(
            'SELECT p.id_paciente, p.correo,
                    (SELECT u.id_usuario FROM usuario u WHERE u.id_paciente = p.id_paciente) AS id_usuario
               FROM paciente p
              WHERE p.ci = :ci'
        );
        $ficha->execute([':ci' => (string) $datos['ci']]);
        $fila = $ficha->fetch();

        if ($fila !== false && $fila['id_usuario'] !== null) {
            throw new ReglaException('Ya existe una cuenta para esa CI. Si ya se registró, use "¿Olvidó su contraseña?".');
        }

        if ($fila !== false) {
            // Ficha creada por Recepción: solo se vincula si el correo coincide con el registrado.
            if (strtolower((string) $fila['correo']) !== strtolower($correo)) {
                throw new ReglaException('Los datos no coinciden con su ficha en la clínica. Acuda a Recepción.');
            }
            $idPaciente = (int) $fila['id_paciente'];
        } else {
            $idPaciente = PacienteService::registrar($datos);
        }

        $idUsuario = AdministracionUsuariosService::crear([
            'nombre_usuario' => (string) $datos['nombre_usuario'],
            'correo' => $correo,
            'nombres' => (string) $datos['nombres'],
            'apellidos' => (string) $datos['apellidos'],
            'rol' => Roles::PACIENTE,
            'id_medico' => 0,
            'id_paciente' => $idPaciente,
            'puede_marcar_atendida' => false,
            'puede_ver_motivo' => false,
        ]);

        return ['id_usuario' => $idUsuario, 'id_paciente' => $idPaciente];
    }
}
