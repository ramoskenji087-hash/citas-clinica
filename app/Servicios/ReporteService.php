<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;

/**
 * Reportes administrativos (RF13).
 *
 * Todos los reportes usan los mismos filtros: rango de fechas (desde, hasta), médico,
 * especialidad y estado. Los filtros vacíos no se aplican. Solo se leen datos.
 */
final class ReporteService
{
    /** Totales de citas por estado dentro del periodo. */
    public static function totalesPorEstado(array $f): array
    {
        [$where, $parametros] = self::condiciones($f);

        $consulta = Conexion::obtener()->prepare(
            'SELECT c.estado, COUNT(*) AS total
               FROM cita c' . $where . '
              GROUP BY c.estado
              ORDER BY c.estado'
        );
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    /** Citas por médico, con las atendidas, canceladas e inasistencias de cada uno. */
    public static function citasPorMedico(array $f): array
    {
        [$where, $parametros] = self::condiciones($f);

        $consulta = Conexion::obtener()->prepare(
            "SELECT m.nombres || ' ' || m.apellidos AS medico,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE c.estado = 'Atendida') AS atendidas,
                    COUNT(*) FILTER (WHERE c.estado = 'Cancelada') AS canceladas,
                    COUNT(*) FILTER (WHERE c.estado = 'Inasistencia') AS inasistencias
               FROM cita c
               JOIN medico m ON m.id_medico = c.id_medico
               JOIN especialidad e ON e.id_especialidad = c.id_especialidad" . $where . '
              GROUP BY m.id_medico, m.nombres, m.apellidos
              ORDER BY medico'
        );
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    /** Citas por especialidad, con las atendidas, canceladas e inasistencias de cada una. */
    public static function citasPorEspecialidad(array $f): array
    {
        [$where, $parametros] = self::condiciones($f);

        $consulta = Conexion::obtener()->prepare(
            "SELECT e.nombre AS especialidad,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE c.estado = 'Atendida') AS atendidas,
                    COUNT(*) FILTER (WHERE c.estado = 'Cancelada') AS canceladas,
                    COUNT(*) FILTER (WHERE c.estado = 'Inasistencia') AS inasistencias
               FROM cita c
               JOIN medico m ON m.id_medico = c.id_medico
               JOIN especialidad e ON e.id_especialidad = c.id_especialidad" . $where . '
              GROUP BY e.id_especialidad, e.nombre
              ORDER BY especialidad'
        );
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    /** Pacientes registrados dentro del periodo (fecha de registro). No usa los filtros de cita. */
    public static function pacientesRegistrados(string $desde, string $hasta): array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT codigo, ci, nombres, apellidos, fecha_registro::date AS fecha
               FROM paciente
              WHERE fecha_registro::date BETWEEN :desde AND :hasta
              ORDER BY fecha_registro'
        );
        $consulta->execute([':desde' => $desde, ':hasta' => $hasta]);

        return $consulta->fetchAll();
    }

    /**
     * Detalle de las citas del periodo, ordenadas por hora. Es la base de la agenda diaria por médico
     * (cuando el periodo es un solo día) y del archivo CSV.
     *
     * @param int $limite 0 para todas las filas
     */
    public static function detalle(array $f, int $limite = 0): array
    {
        [$where, $parametros] = self::condiciones($f);

        $sql = "SELECT c.codigo, c.inicio::date AS fecha, to_char(c.inicio, 'HH24:MI') AS hora_inicio,
                       to_char(c.fin, 'HH24:MI') AS hora_fin, c.estado,
                       p.nombres || ' ' || p.apellidos AS paciente,
                       m.nombres || ' ' || m.apellidos AS medico,
                       e.nombre AS especialidad,
                       COALESCE(c.motivo, '') AS motivo,
                       COALESCE(c.motivo_cancelacion, '') AS motivo_cancelacion
                  FROM cita c
                  JOIN paciente p ON p.id_paciente = c.id_paciente
                  JOIN medico m ON m.id_medico = c.id_medico
                  JOIN especialidad e ON e.id_especialidad = c.id_especialidad" . $where
            . ' ORDER BY c.inicio';

        if ($limite > 0) {
            $sql .= ' LIMIT ' . $limite;
        }

        $consulta = Conexion::obtener()->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    /**
     * Construye el WHERE con los filtros indicados. Solo se agregan fragmentos fijos de SQL;
     * los valores van como parámetros con nombre.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function condiciones(array $f): array
    {
        $condiciones = ['c.inicio::date BETWEEN :desde AND :hasta'];
        $parametros = [':desde' => (string) $f['desde'], ':hasta' => (string) $f['hasta']];

        if ((int) ($f['medico'] ?? 0) > 0) {
            $condiciones[] = 'c.id_medico = :medico';
            $parametros[':medico'] = (int) $f['medico'];
        }

        if ((int) ($f['especialidad'] ?? 0) > 0) {
            $condiciones[] = 'c.id_especialidad = :especialidad';
            $parametros[':especialidad'] = (int) $f['especialidad'];
        }

        if ((string) ($f['estado'] ?? '') !== '') {
            $condiciones[] = 'c.estado = :estado';
            $parametros[':estado'] = (string) $f['estado'];
        }

        return [' WHERE ' . implode(' AND ', $condiciones), $parametros];
    }
}
