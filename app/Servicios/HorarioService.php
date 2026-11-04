<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Datos\ErroresBD;
use PDOException;

/**
 * Horarios de atención de cada médico (RF05, RN05, RN08).
 *
 * Un horario es un bloque por día: por ejemplo, lunes de 08:00 a 12:00 con citas de 30 minutos.
 * Los horarios no son historial de citas, por eso sí se pueden eliminar. Las citas ya registradas
 * no dependen de ellos.
 */
final class HorarioService
{
    /** Horarios de un médico, ordenados por día y hora. */
    public static function listarPorMedico(int $idMedico): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT id_horario, dia_semana,
                    to_char(hora_inicio, 'HH24:MI') AS hora_inicio,
                    to_char(hora_fin, 'HH24:MI') AS hora_fin,
                    duracion_cita
               FROM horario_medico
              WHERE id_medico = :id_medico
              ORDER BY dia_semana, hora_inicio"
        );
        $consulta->execute([':id_medico' => $idMedico]);

        return $consulta->fetchAll();
    }

    /**
     * Agrega un bloque de horario. La base rechaza los bloques que se cruzan con otro del mismo
     * médico y día (VS012), y los valores fuera de rango (23514).
     */
    public static function agregar(int $idMedico, array $datos): int
    {
        try {
            $consulta = Conexion::obtener()->prepare(
                'INSERT INTO horario_medico (id_medico, dia_semana, hora_inicio, hora_fin, duracion_cita)
                 VALUES (:id_medico, :dia, :hora_inicio, :hora_fin, :duracion)
                 RETURNING id_horario'
            );
            $consulta->execute([
                ':id_medico' => $idMedico,
                ':dia' => (int) $datos['dia'],
                ':hora_inicio' => $datos['hora_inicio'],
                ':hora_fin' => $datos['hora_fin'],
                ':duracion' => (int) $datos['duracion'],
            ]);

            return (int) $consulta->fetchColumn();
        } catch (PDOException $e) {
            throw ErroresBD::traducir($e, [
                '23514' => 'El horario no es válido. Revise las horas y la duración.',
            ]);
        }
    }

    /** Elimina un bloque de horario. Se comprueba que pertenezca al médico indicado. */
    public static function eliminar(int $idHorario, int $idMedico): void
    {
        $consulta = Conexion::obtener()->prepare(
            'DELETE FROM horario_medico
              WHERE id_horario = :id_horario AND id_medico = :id_medico'
        );
        $consulta->execute([':id_horario' => $idHorario, ':id_medico' => $idMedico]);
    }
}
