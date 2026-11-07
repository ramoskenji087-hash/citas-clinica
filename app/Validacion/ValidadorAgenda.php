<?php
declare(strict_types=1);

namespace App\Validacion;

/**
 * Validación de médicos (RF03), especialidades (RF04) y horarios de atención (RF05, RN08).
 *
 * Cada método devuelve un arreglo de errores por campo. Si está vacío, los datos son válidos.
 * Las reglas de disponibilidad (solapes, horario y duración frente a las citas) las vuelve a
 * comprobar la base de datos con los triggers y la restricción EXCLUDE.
 */
final class ValidadorAgenda
{
    /** Duraciones de cita permitidas por la base de datos (RF05). */
    private const DURACIONES = [5, 10, 15];

    /**
     * Nombre normalizado: minúsculas, sin tildes y con espacios sobrantes quitados.
     * Debe producir el mismo resultado que la expresión del índice único ux_especialidad_nombre_normalizado.
     */
    public static function nombreNormalizado(string $nombre): string
    {
        $sinTildes = strtr($nombre, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', strtolower($sinTildes)));
    }

    /**
     * Datos de un médico. Los datos llegan sin espacios sobrantes y con el teléfono ya limpio.
     *
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function medico(array $d): array
    {
        $errores = [];

        $registro = (string) $d['registro_profesional'];
        if (preg_match('/^[A-Za-z0-9.\/-]{3,30}$/', $registro) !== 1) {
            $errores['registro_profesional'] = 'El registro profesional debe tener de 3 a 30 caracteres: letras, números, punto, guion o barra.';
        }

        $nombre = Validador::nombre((string) $d['nombres'], 'El nombre');
        if ($nombre !== null) {
            $errores['nombres'] = $nombre;
        }

        $apellido = Validador::nombre((string) $d['apellidos'], 'El apellido');
        if ($apellido !== null) {
            $errores['apellidos'] = $apellido;
        }

        $telefono = Validador::telefono((string) $d['telefono']);
        if ($telefono !== null) {
            $errores['telefono'] = $telefono;
        }

        // El correo del médico es obligatorio (campos mínimos del RF03).
        $correo = Validador::correo((string) $d['correo'], true);
        if ($correo !== null) {
            $errores['correo'] = $correo;
        }

        if (($d['especialidades'] ?? []) === []) {
            $errores['especialidades'] = 'Seleccione al menos una especialidad.';
        }

        return $errores;
    }

    /**
     * Datos de una especialidad (RF04). La descripción es opcional.
     *
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function especialidad(array $d): array
    {
        $errores = [];

        $nombre = Validador::nombre((string) $d['nombre'], 'El nombre');
        if ($nombre !== null) {
            $errores['nombre'] = $nombre;
        }

        if (preg_match_all('/./us', (string) $d['descripcion']) > 255) {
            $errores['descripcion'] = 'La descripción no puede tener más de 255 caracteres.';
        }

        return $errores;
    }

    /**
     * Un bloque de horario de atención (RF05): día, horas y duración de cita.
     *
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function horario(array $d): array
    {
        $errores = [];

        if (!in_array((int) $d['dia'], [1, 2, 3, 4, 5, 6, 7], true)) {
            $errores['dia'] = 'Seleccione un día de la semana.';
        }

        $inicio = Validador::hora((string) $d['hora_inicio']);
        if ($inicio !== null) {
            $errores['hora_inicio'] = $inicio;
        }

        $fin = Validador::hora((string) $d['hora_fin']);
        if ($fin !== null) {
            $errores['hora_fin'] = $fin;
        } elseif ($inicio === null && (string) $d['hora_fin'] <= (string) $d['hora_inicio']) {
            // "HH:MM" con cero a la izquierda se compara bien como texto.
            $errores['hora_fin'] = 'La hora final debe ser posterior a la hora inicial.';
        }

        if (!in_array((int) $d['duracion'], self::DURACIONES, true)) {
            $errores['duracion'] = 'La duración debe ser 5, 10 o 15 minutos.';
        }

        return $errores;
    }
}
