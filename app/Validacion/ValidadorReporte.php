<?php
declare(strict_types=1);

namespace App\Validacion;

use App\Servicios\AgendaService;

/**
 * Validación de los filtros de los reportes (RF13).
 */
final class ValidadorReporte
{
    /**
     * @param array<string, mixed> $f desde, hasta, medico, especialidad, estado
     * @return array<string, string>
     */
    public static function filtros(array $f): array
    {
        $errores = [];

        $desde = (string) $f['desde'];
        $hasta = (string) $f['hasta'];

        if (!ValidadorGestionCita::fechaValida($desde)) {
            $errores['desde'] = 'La fecha inicial no es válida.';
        }

        if (!ValidadorGestionCita::fechaValida($hasta)) {
            $errores['hasta'] = 'La fecha final no es válida.';
        }

        if ($errores === [] && $desde > $hasta) {
            $errores['hasta'] = 'La fecha final no puede ser anterior a la inicial.';
        }

        if ((string) $f['estado'] !== '' && !in_array((string) $f['estado'], AgendaService::ESTADOS, true)) {
            $errores['estado'] = 'Seleccione un estado de la lista.';
        }

        return $errores;
    }
}
