<?php
declare(strict_types=1);

namespace App\Seguridad;

/**
 * Roles del sistema y menú de cada uno (RF01, RN12).
 *
 * Los números coinciden con la tabla rol de la base de datos:
 * 1 = Administrador, 2 = Recepcionista, 3 = Médico, 4 = Paciente.
 *
 * Esta clase es la única fuente de verdad sobre los roles. Si un menú cambia,
 * se cambia aquí y no en cada página.
 */
final class Roles
{
    public const ADMINISTRADOR = 1;
    public const RECEPCIONISTA = 2;
    public const MEDICO        = 3;
    public const PACIENTE      = 4;

    /** Nombre que se muestra al usuario. */
    public static function nombre(int $rol): string
    {
        return match ($rol) {
            self::ADMINISTRADOR => 'Administrador',
            self::RECEPCIONISTA => 'Recepcionista',
            self::MEDICO        => 'Médico',
            self::PACIENTE      => 'Paciente',
            default             => 'Sin rol',
        };
    }

    /**
     * Opciones del menú de un rol.
     * Cada opción apunta a una página de public/. Las páginas que todavía no
     * existen darán "no encontrado" hasta que se construyan en los siguientes bloques.
     *
     * @return array<int, array{texto: string, pagina: string}>
     */
    public static function menu(int $rol): array
    {
        return match ($rol) {
            self::ADMINISTRADOR => [
                ['texto' => 'Inicio',               'pagina' => 'inicio.php'],
                ['texto' => 'Médicos y horarios',   'pagina' => 'medicos.php'],
                ['texto' => 'Especialidades',       'pagina' => 'especialidades.php'],
                ['texto' => 'Usuarios',             'pagina' => 'usuarios.php'],
                ['texto' => 'Reportes',             'pagina' => 'reportes.php'],
                ['texto' => 'Cambiar contraseña',   'pagina' => 'cambiar_clave.php'],
            ],
            self::RECEPCIONISTA => [
                ['texto' => 'Inicio',               'pagina' => 'inicio.php'],
                ['texto' => 'Pacientes',            'pagina' => 'pacientes.php'],
                ['texto' => 'Nueva cita',           'pagina' => 'cita_nueva.php'],
                ['texto' => 'Citas',                'pagina' => 'citas.php'],
                ['texto' => 'Agenda de médicos',    'pagina' => 'agenda.php'],
                ['texto' => 'Cambiar contraseña',   'pagina' => 'cambiar_clave.php'],
            ],
            self::MEDICO => [
                ['texto' => 'Inicio',               'pagina' => 'inicio.php'],
                ['texto' => 'Mi agenda',            'pagina' => 'mi_agenda.php'],
                ['texto' => 'Cambiar contraseña',   'pagina' => 'cambiar_clave.php'],
            ],
            self::PACIENTE => [
                ['texto' => 'Inicio',               'pagina' => 'inicio.php'],
                ['texto' => 'Mis citas',            'pagina' => 'mis_citas.php'],
                ['texto' => 'Mis datos',            'pagina' => 'mis_datos.php'],
                ['texto' => 'Cambiar contraseña',   'pagina' => 'cambiar_clave.php'],
            ],
            default => [],
        };
    }
}
