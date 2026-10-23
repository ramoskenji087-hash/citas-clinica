<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Gestión de pacientes (RF02, RF12, RN01, RN11).
 *
 * - Cada paciente tiene un código único (PAC-0001) que genera la base de datos.
 * - La CI es única. La base lo garantiza, y aquí el error se traduce a un mensaje claro (P2).
 * - Nunca se borra un paciente: se desactiva para conservar su historial (RN11).
 * - Todas las consultas usan parámetros con nombre (sección 7).
 */
final class PacienteService
{
    /** Campos que guarda el formulario. Los nombres coinciden con las columnas de la tabla paciente. */
    private const CAMPOS = ['ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion'];

    /** Todos los pacientes, ordenados por apellido. */
    public static function listar(): array
    {
        return Conexion::obtener()->query(
            'SELECT id_paciente, codigo, ci, nombres, apellidos, telefono, correo, activo::int AS activo
               FROM paciente
              ORDER BY apellidos, nombres'
        )->fetchAll();
    }

    /**
     * Busca por CI, nombre o apellido (RF02). Devuelve como máximo 100 pacientes.
     */
    public static function buscar(string $termino): array
    {
        // Se escapan % y _ para que el usuario no pueda usarlos como comodines.
        $patron = '%' . addcslashes($termino, '%_\\') . '%';

        $consulta = Conexion::obtener()->prepare(
            "SELECT id_paciente, codigo, ci, nombres, apellidos, telefono, correo, activo::int AS activo
               FROM paciente
              WHERE ci ILIKE :p1
                 OR nombres ILIKE :p2
                 OR apellidos ILIKE :p3
                 OR (nombres || ' ' || apellidos) ILIKE :p4
              ORDER BY apellidos, nombres
              LIMIT 100"
        );
        $consulta->execute([
            ':p1' => $patron,
            ':p2' => $patron,
            ':p3' => $patron,
            ':p4' => $patron,
        ]);

        return $consulta->fetchAll();
    }

    /** Datos completos de un paciente, o null si no existe. */
    public static function obtener(int $id): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT id_paciente, codigo, ci, nombres, apellidos, fecha_nacimiento, sexo,
                    telefono, correo, direccion, activo::int AS activo
               FROM paciente
              WHERE id_paciente = :id_paciente'
        );
        $consulta->execute([':id_paciente' => $id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Registra un paciente nuevo y devuelve su id.
     * Lanza ReglaException si la CI ya existe.
     */
    public static function registrar(array $datos): int
    {
        try {
            $consulta = Conexion::obtener()->prepare(
                'INSERT INTO paciente (ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion)
                 VALUES (:ci, :nombres, :apellidos, :fecha_nacimiento, :sexo, :telefono, :correo, :direccion)
                 RETURNING id_paciente'
            );
            $consulta->execute(self::parametros($datos));

            return (int) $consulta->fetchColumn();
        } catch (PDOException $e) {
            throw self::traducir($e);
        }
    }

    /** Actualiza los datos de un paciente. Lanza ReglaException si la CI ya pertenece a otro. */
    public static function modificar(int $id, array $datos): void
    {
        try {
            $consulta = Conexion::obtener()->prepare(
                'UPDATE paciente
                    SET ci = :ci,
                        nombres = :nombres,
                        apellidos = :apellidos,
                        fecha_nacimiento = :fecha_nacimiento,
                        sexo = :sexo,
                        telefono = :telefono,
                        correo = :correo,
                        direccion = :direccion
                  WHERE id_paciente = :id_paciente'
            );

            $parametros = self::parametros($datos);
            $parametros[':id_paciente'] = $id;
            $consulta->execute($parametros);
        } catch (PDOException $e) {
            throw self::traducir($e);
        }
    }

    /**
     * Activa o desactiva un paciente (RN11). Nunca se borra.
     * PostgreSQL recibe 'true' o 'false' como texto, que es la forma segura de enviar un booleano con PDO.
     */
    public static function cambiarActivo(int $id, bool $activo): void
    {
        $consulta = Conexion::obtener()->prepare(
            'UPDATE paciente SET activo = :activo WHERE id_paciente = :id_paciente'
        );
        $consulta->execute([
            ':activo'      => $activo ? 'true' : 'false',
            ':id_paciente' => $id,
        ]);
    }

    /**
     * Todas las citas del paciente, incluidas las canceladas, de la más reciente
     * a la más antigua (RF12).
     */
    public static function historial(int $idPaciente): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT c.codigo, c.inicio, c.estado, c.motivo_cancelacion, c.fecha_creacion,
                    m.nombres || ' ' || m.apellidos AS medico,
                    e.nombre AS especialidad
               FROM cita c
               JOIN medico m       ON m.id_medico = c.id_medico
               JOIN especialidad e ON e.id_especialidad = c.id_especialidad
              WHERE c.id_paciente = :id_paciente
              ORDER BY c.inicio DESC"
        );
        $consulta->execute([':id_paciente' => $idPaciente]);

        return $consulta->fetchAll();
    }

    /** Convierte los datos del formulario en parámetros con nombre (:ci, :nombres, ...). Los vacíos se guardan como NULL. */
    private static function parametros(array $datos): array
    {
        $parametros = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $datos[$campo] ?? '';
            $parametros[':' . $campo] = $valor === '' ? null : $valor;
        }

        return $parametros;
    }

    /**
     * Convierte un error de la base en un error que se puede mostrar.
     * La CI duplicada tiene su propio mensaje. Cualquier otro error se registra en el log
     * y el usuario recibe un mensaje genérico (sección 7).
     */
    private static function traducir(PDOException $e): Throwable
    {
        // 23505 = violación de restricción única
        if (($e->errorInfo[0] ?? '') === '23505') {
            return new ReglaException('Ya existe un paciente con ese CI.');
        }

        error_log('[citas-clinica] Error de base de datos en pacientes: ' . $e->getMessage());

        return new RuntimeException('No se pudo guardar la información. Intente más tarde.');
    }
}
