<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Datos\ErroresBD;
use PDOException;

/**
 * Portal del paciente: datos del paciente, contacto, ficha médica y preferencias (RF02, sección 3.4).
 *
 * El paciente cambia su teléfono, su correo, su dirección, su ficha médica, su contacto de emergencia
 * y sus preferencias. Su CI, su nombre, su fecha de nacimiento y su sexo los cambia Recepción.
 * El identificador del paciente siempre viene de la sesión, nunca de la dirección de la página.
 */
final class PortalPacienteService
{
    /** Datos del paciente para mostrar en el portal, o null si no existe. */
    public static function datos(int $idPaciente): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT codigo, ci, nombres, apellidos, fecha_nacimiento::text AS fecha_nacimiento,
                    sexo, telefono, correo, direccion
               FROM paciente
              WHERE id_paciente = :id_paciente'
        );
        $consulta->execute([':id_paciente' => $idPaciente]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Guarda los datos de contacto. Los campos vacíos opcionales se guardan como NULL.
     *
     * @param array<string, string> $datos telefono (ya limpio), correo y direccion
     */
    public static function actualizarContacto(int $idPaciente, array $datos): void
    {
        try {
            Conexion::obtener()->prepare(
                'UPDATE paciente
                    SET telefono = :telefono, correo = :correo, direccion = :direccion
                  WHERE id_paciente = :id_paciente'
            )->execute([
                ':telefono' => $datos['telefono'],
                ':correo' => $datos['correo'] === '' ? null : $datos['correo'],
                ':direccion' => $datos['direccion'] === '' ? null : $datos['direccion'],
                ':id_paciente' => $idPaciente,
            ]);
        } catch (PDOException $e) {
            throw ErroresBD::traducir($e, [
                '23514' => 'Revise el teléfono y el correo electrónico.',
            ]);
        }
    }

    /** Ficha médica, contacto de emergencia y preferencias del paciente, o null si no existe. */
    public static function ficha(int $idPaciente): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT tipo_sangre, alergias, enfermedades_cronicas, medicamentos, antecedentes,
                    contacto_emergencia_nombre, contacto_emergencia_parentesco, contacto_emergencia_telefono,
                    recibir_notificaciones::int AS recibir_notificaciones,
                    compartir_historial_medico::int AS compartir_historial_medico
               FROM paciente
              WHERE id_paciente = :id_paciente'
        );
        $consulta->execute([':id_paciente' => $idPaciente]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Guarda la ficha médica, el contacto de emergencia y las preferencias.
     * Los textos vacíos se guardan como NULL.
     *
     * @param array<string, mixed> $datos
     */
    public static function actualizarFicha(int $idPaciente, array $datos): void
    {
        $nulo = static fn (string $valor): ?string => $valor === '' ? null : $valor;

        try {
            Conexion::obtener()->prepare(
                'UPDATE paciente
                    SET tipo_sangre = :tipo_sangre,
                        alergias = :alergias,
                        enfermedades_cronicas = :enfermedades_cronicas,
                        medicamentos = :medicamentos,
                        antecedentes = :antecedentes,
                        contacto_emergencia_nombre = :emergencia_nombre,
                        contacto_emergencia_parentesco = :emergencia_parentesco,
                        contacto_emergencia_telefono = :emergencia_telefono,
                        recibir_notificaciones = :notificaciones,
                        compartir_historial_medico = :compartir
                  WHERE id_paciente = :id_paciente'
            )->execute([
                ':tipo_sangre' => $nulo((string) $datos['tipo_sangre']),
                ':alergias' => $nulo((string) $datos['alergias']),
                ':enfermedades_cronicas' => $nulo((string) $datos['enfermedades_cronicas']),
                ':medicamentos' => $nulo((string) $datos['medicamentos']),
                ':antecedentes' => $nulo((string) $datos['antecedentes']),
                ':emergencia_nombre' => $nulo((string) $datos['contacto_emergencia_nombre']),
                ':emergencia_parentesco' => $nulo((string) $datos['contacto_emergencia_parentesco']),
                ':emergencia_telefono' => $nulo((string) $datos['contacto_emergencia_telefono']),
                ':notificaciones' => $datos['recibir_notificaciones'] ? 'true' : 'false',
                ':compartir' => $datos['compartir_historial_medico'] ? 'true' : 'false',
                ':id_paciente' => $idPaciente,
            ]);
        } catch (PDOException $e) {
            throw ErroresBD::traducir($e, [
                '23514' => 'Revise los datos de la ficha médica.',
            ]);
        }
    }
}
