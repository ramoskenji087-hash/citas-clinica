<?php
declare(strict_types=1);

namespace App\Validacion;

/**
 * Validación de las cuentas de usuario (RF01, RN12).
 *
 * Reglas de vínculo, iguales a los CHECK de la tabla usuario:
 * - El rol Médico (3) debe estar vinculado a una ficha de médico.
 * - El rol Paciente (4) debe estar vinculado a una ficha de paciente.
 * - Solo el rol Médico puede tener el permiso de marcar citas como atendidas.
 */
final class ValidadorUsuario
{
    /**
     * @param array<string, mixed> $d nombre_usuario, correo, nombres, apellidos, rol, id_medico,
     *                                id_paciente, puede_marcar_atendida
     * @return array<string, string>
     */
    public static function usuario(array $d): array
    {
        $errores = [];

        $nombreUsuario = (string) $d['nombre_usuario'];
        if (preg_match('/^[A-Za-z0-9._-]{3,30}$/', $nombreUsuario) !== 1) {
            $errores['nombre_usuario'] = 'El nombre de usuario debe tener de 3 a 30 caracteres: letras, números, punto, guion o guion bajo.';
        }

        $correo = Validador::correo((string) $d['correo'], true);
        if ($correo !== null) {
            $errores['correo'] = $correo;
        }

        $nombres = Validador::nombre((string) $d['nombres'], 'El nombre');
        if ($nombres !== null) {
            $errores['nombres'] = $nombres;
        }

        $apellidos = Validador::nombre((string) $d['apellidos'], 'El apellido');
        if ($apellidos !== null) {
            $errores['apellidos'] = $apellidos;
        }

        $rol = (int) $d['rol'];
        if (!in_array($rol, [1, 2, 3, 4], true)) {
            $errores['rol'] = 'Seleccione un rol de la lista.';
        }

        if ($rol === 3 && (int) $d['id_medico'] <= 0) {
            $errores['id_medico'] = 'El rol Médico necesita una ficha de médico vinculada.';
        }

        if ($rol === 4 && (int) $d['id_paciente'] <= 0) {
            $errores['id_paciente'] = 'El rol Paciente necesita una ficha de paciente vinculada.';
        }

        return $errores;
    }
}
