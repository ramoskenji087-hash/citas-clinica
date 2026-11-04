<?php
declare(strict_types=1);

namespace App\Validacion;

/**
 * Validación de los cambios sobre una cita ya registrada (RF08, RF09) y de los filtros
 * de las agendas (RF11).
 */
final class ValidadorGestionCita
{
    /** Motivo de cancelación obligatorio, de 3 a 255 caracteres (RF09, RC06). */
    public static function cancelacion(string $motivo): ?string
    {
        $longitud = preg_match_all('/./us', trim($motivo));

        return $longitud >= 3 && $longitud <= 255
            ? null
            : 'Escriba el motivo de la cancelación, entre 3 y 255 caracteres.';
    }

    /**
     * Nueva fecha y hora para reprogramar (RF08). La fecha no puede ser pasada (RN06).
     *
     * @return array<string, string>
     */
    public static function reprogramacion(string $fecha, string $hora): array
    {
        $errores = [];

        $errorFecha = ValidadorCita::fecha($fecha);
        if ($errorFecha !== null) {
            $errores['fecha'] = $errorFecha;
        }

        $errorHora = Validador::hora($hora);
        if ($errorHora !== null) {
            $errores['hora'] = 'Elija una hora libre de la lista.';
        }

        return $errores;
    }

    /** Indica si el texto es una fecha real con formato AAAA-MM-DD. Sirve para filtrar las agendas. */
    public static function fechaValida(string $valor): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) !== 1) {
            return false;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]);
    }
}
