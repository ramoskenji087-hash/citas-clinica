<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;

/**
 * Consulta de citas para las agendas (RF10, RF11).
 *
 * Solo lee datos. Los filtros son opcionales: fecha, médico y estado. Si un filtro está vacío,
 * no se aplica. El motivo de la cita se incluye porque la recepción y el médico tienen permiso
 * para verlo (RF11).
 */
final class AgendaService
{
    /** Estados válidos para filtrar (RF10). */
    public const ESTADOS = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];

    /**
     * Citas que cumplen los filtros, ordenadas por hora de inicio.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listar(string $fecha = '', int $idMedico = 0, string $estado = ''): array
    {
        $condiciones = [];
        $parametros = [];

        if ($fecha !== '') {
            $condiciones[] = 'c.inicio::date = :fecha';
            $parametros[':fecha'] = $fecha;
        }

        if ($idMedico > 0) {
            $condiciones[] = 'c.id_medico = :id_medico';
            $parametros[':id_medico'] = $idMedico;
        }

        if ($estado !== '' && in_array($estado, self::ESTADOS, true)) {
            $condiciones[] = 'c.estado = :estado';
            $parametros[':estado'] = $estado;
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        $consulta = Conexion::obtener()->prepare(
            "SELECT c.id_cita, c.codigo, c.id_medico, c.estado,
                    c.inicio::text AS inicio,
                    to_char(c.inicio, 'HH24:MI') AS hora_inicio,
                    to_char(c.fin, 'HH24:MI') AS hora_fin,
                    c.motivo, c.motivo_cancelacion,
                    p.nombres || ' ' || p.apellidos AS paciente,
                    p.ci AS paciente_ci, p.telefono AS paciente_telefono,
                    m.nombres || ' ' || m.apellidos AS medico,
                    e.nombre AS especialidad
               FROM cita c
               JOIN paciente p ON p.id_paciente = c.id_paciente
               JOIN medico m ON m.id_medico = c.id_medico
               JOIN especialidad e ON e.id_especialidad = c.id_especialidad"
            . $where
            . ' ORDER BY c.inicio'
        );
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }
}
