<?php
declare(strict_types=1);

namespace App\Validacion;

use DateTime;

/**
 * Validación de una reserva de cita (RF06, RN06).
 *
 * Igual que en el resto del sistema, el servidor vuelve a validar todo aunque el formulario
 * ya lo haya validado en el navegador. Cada método devuelve un arreglo de errores por campo.
 */
final class ValidadorCita
{
    /**
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function cita(array $d): array
    {
        $errores = [];

        $mensajesObligatorios = [
            'paciente' => 'Seleccione un paciente.',
            'especialidad' => 'Seleccione una especialidad.',
            'medico' => 'Seleccione un médico.',
        ];
        foreach ($mensajesObligatorios as $campo => $mensaje) {
            if ((int) ($d[$campo] ?? 0) <= 0) {
                $errores[$campo] = $mensaje;
            }
        }

        $fecha = self::fecha((string) ($d['fecha'] ?? ''));
        if ($fecha !== null) {
            $errores['fecha'] = $fecha;
        }

        // La hora debe ser una de las horas libres que muestra el sistema.
        $hora = Validador::hora((string) ($d['hora'] ?? ''));
        if ($hora !== null) {
            $errores['hora'] = 'Elija una hora libre de la lista.';
        }

        $motivo = (string) ($d['motivo'] ?? '');
        if (preg_match_all('/./us', $motivo) > 200) {
            $errores['motivo'] = 'El motivo no puede tener más de 200 caracteres.';
        }

        return $errores;
    }

    /**
     * Fecha de la cita: fecha real (AAAA-MM-DD) que no sea anterior a hoy (RN06).
     * Devuelve null si es válida, o el mensaje de error.
     */
    public static function fecha(string $valor): ?string
    {
        $fecha = DateTime::createFromFormat('!Y-m-d', $valor);
        $avisos = DateTime::getLastErrors();

        $invalida = $fecha === false
            || (is_array($avisos) && ($avisos['warning_count'] > 0 || $avisos['error_count'] > 0));

        if ($invalida) {
            return 'La fecha no es válida.';
        }

        return $fecha < new DateTime('today')
            ? 'No se pueden registrar citas en fechas pasadas.'
            : null;
    }
}
