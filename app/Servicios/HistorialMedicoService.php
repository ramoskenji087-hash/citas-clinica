<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;

/**
 * Historial médico compartido (sección 3.4 del documento).
 *
 * Un médico ve la ficha médica de un paciente solo si tiene al menos una cita con él y el paciente
 * permitió compartir su ficha. Nunca se muestran el teléfono ni el correo del paciente.
 */
final class HistorialMedicoService
{
    /** Ficha médica para ese médico, o null si no tiene cita con el paciente o el paciente no la comparte. */
    public static function fichaParaMedico(int $idMedico, int $idPaciente): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT p.nombres, p.apellidos, p.ci, p.fecha_nacimiento::text AS fecha_nacimiento,
                    p.tipo_sangre, p.alergias, p.enfermedades_cronicas, p.medicamentos, p.antecedentes
               FROM paciente p
              WHERE p.id_paciente = :paciente
                AND p.compartir_historial_medico = TRUE
                AND EXISTS (SELECT 1 FROM cita c WHERE c.id_paciente = p.id_paciente AND c.id_medico = :medico)'
        );
        $consulta->execute([':paciente' => $idPaciente, ':medico' => $idMedico]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }
}
