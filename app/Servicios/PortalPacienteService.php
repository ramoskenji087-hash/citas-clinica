<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Datos\ErroresBD;
use PDOException;

/**
 * Portal del paciente: datos del paciente y sus datos de contacto (RF02).
 *
 * El paciente solo puede cambiar su teléfono, su correo y su dirección. Su CI, su nombre, su fecha
 * de nacimiento y su sexo los cambia Recepción. El identificador del paciente siempre viene de la
 * sesión, nunca de la dirección de la página.
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
}
