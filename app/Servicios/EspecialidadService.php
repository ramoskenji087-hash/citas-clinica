<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Datos\ErroresBD;
use App\Validacion\ValidadorAgenda;
use PDOException;

/**
 * Gestión de especialidades (RF04, RN11).
 *
 * - No se permiten dos especialidades con el mismo nombre normalizado (minúsculas, sin tildes,
 *   sin espacios sobrantes). PHP lo comprueba primero para dar un mensaje temprano, y la base
 *   lo garantiza con el índice único ux_especialidad_nombre_normalizado.
 * - Nunca se borra: se desactiva (RN11).
 */
final class EspecialidadService
{
    /** Todas las especialidades, ordenadas por nombre. */
    public static function listar(): array
    {
        return Conexion::obtener()->query(
            'SELECT id_especialidad, nombre, descripcion, activo::int AS activo
               FROM especialidad
              ORDER BY nombre'
        )->fetchAll();
    }

    /** Solo las especialidades activas: las que se pueden asignar a un médico. */
    public static function listarActivas(): array
    {
        return Conexion::obtener()->query(
            'SELECT id_especialidad, nombre, descripcion
               FROM especialidad
              WHERE activo
              ORDER BY nombre'
        )->fetchAll();
    }

    /** Datos de una especialidad, o null si no existe. */
    public static function obtener(int $id): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT id_especialidad, nombre, descripcion, activo::int AS activo
               FROM especialidad
              WHERE id_especialidad = :id_especialidad'
        );
        $consulta->execute([':id_especialidad' => $id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Crea una especialidad (si $id es null) o modifica la existente.
     * Lanza ReglaException si ya existe otra con el mismo nombre normalizado.
     */
    public static function guardar(?int $id, array $datos): int
    {
        $pdo = Conexion::obtener();
        $normalizado = ValidadorAgenda::nombreNormalizado((string) $datos['nombre']);

        // Misma transformación que el índice único de la base. Solo son fragmentos fijos de SQL.
        $expresion = <<<'SQL'
btrim(regexp_replace(lower(translate(nombre, 'ÁÉÍÓÚÜÑáéíóúüñ', 'AEIOUUNaeiouun')), '\s+', ' ', 'g'))
SQL;

        $duplicado = $pdo->prepare(
            "SELECT 1 FROM especialidad
              WHERE {$expresion} = :normalizado
                AND id_especialidad <> :id_excluir
              LIMIT 1"
        );
        $duplicado->execute([':normalizado' => $normalizado, ':id_excluir' => $id ?? 0]);

        if ($duplicado->fetchColumn() !== false) {
            throw new ReglaException('Ya existe una especialidad con ese nombre.');
        }

        $parametros = [
            ':nombre' => trim((string) $datos['nombre']),
            ':descripcion' => trim((string) $datos['descripcion']) === '' ? null : trim((string) $datos['descripcion']),
        ];

        try {
            if ($id === null) {
                $consulta = $pdo->prepare(
                    'INSERT INTO especialidad (nombre, descripcion)
                     VALUES (:nombre, :descripcion)
                     RETURNING id_especialidad'
                );
                $consulta->execute($parametros);

                return (int) $consulta->fetchColumn();
            }

            $parametros[':id_especialidad'] = $id;
            $pdo->prepare(
                'UPDATE especialidad
                    SET nombre = :nombre, descripcion = :descripcion
                  WHERE id_especialidad = :id_especialidad'
            )->execute($parametros);

            return $id;
        } catch (PDOException $e) {
            throw ErroresBD::traducir($e, [
                '23505' => 'Ya existe una especialidad con ese nombre.',
            ]);
        }
    }

    /** Activa o desactiva una especialidad (RN11). Nunca se borra. */
    public static function cambiarActivo(int $id, bool $activo): void
    {
        $consulta = Conexion::obtener()->prepare(
            'UPDATE especialidad SET activo = :activo WHERE id_especialidad = :id_especialidad'
        );
        $consulta->execute([
            ':activo' => $activo ? 'true' : 'false',
            ':id_especialidad' => $id,
        ]);
    }
}
