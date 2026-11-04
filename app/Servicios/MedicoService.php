<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Datos\ErroresBD;
use PDO;
use PDOException;
use Throwable;

/**
 * Gestión de médicos (RF03, RN02, RN04, RN11).
 *
 * - Cada médico tiene un código (MED-0000) y un número de registro profesional único.
 * - Nunca se borra: se desactiva para conservar su historial (RN11).
 * - Un médico inactivo no recibe citas nuevas (RN04). La base lo impide con el trigger VS001.
 * - Las especialidades se guardan en medico_especialidad (relación N:M).
 * - Guardar al médico y sus especialidades es una sola transacción: se guarda todo o nada.
 */
final class MedicoService
{
    /** Campos del formulario que se guardan en la tabla medico. */
    private const CAMPOS = ['registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo'];

    /**
     * Lista de médicos con sus especialidades. Con búsqueda, filtra por nombre o por especialidad.
     */
    public static function listar(string $busqueda = ''): array
    {
        $seleccion = "SELECT m.id_medico, m.codigo, m.registro_profesional, m.nombres, m.apellidos,
                             m.telefono, m.correo, m.activo::int AS activo,
                             COALESCE(string_agg(e.nombre, ', ' ORDER BY e.nombre), '') AS especialidades
                        FROM medico m
                        LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico
                        LEFT JOIN especialidad e ON e.id_especialidad = me.id_especialidad";
        $orden = ' GROUP BY m.id_medico ORDER BY m.apellidos, m.nombres';

        if ($busqueda === '') {
            return Conexion::obtener()->query($seleccion . $orden)->fetchAll();
        }

        // Los comodines % y _ que escribe el usuario se buscan como texto normal.
        $patron = '%' . addcslashes($busqueda, '%_\\') . '%';
        $consulta = Conexion::obtener()->prepare(
            $seleccion . "
                       WHERE m.nombres ILIKE :p1
                          OR m.apellidos ILIKE :p2
                          OR (m.nombres || ' ' || m.apellidos) ILIKE :p3
                          OR m.id_medico IN (
                               SELECT me2.id_medico
                                 FROM medico_especialidad me2
                                 JOIN especialidad e2 ON e2.id_especialidad = me2.id_especialidad
                                WHERE e2.nombre ILIKE :p4)" . $orden
        );
        $consulta->execute([':p1' => $patron, ':p2' => $patron, ':p3' => $patron, ':p4' => $patron]);

        return $consulta->fetchAll();
    }

    /** Datos de un médico con la lista de ids de sus especialidades, o null si no existe. */
    public static function obtener(int $id): ?array
    {
        $pdo = Conexion::obtener();
        $consulta = $pdo->prepare(
            'SELECT id_medico, codigo, registro_profesional, nombres, apellidos, telefono, correo,
                    activo::int AS activo
               FROM medico
              WHERE id_medico = :id_medico'
        );
        $consulta->execute([':id_medico' => $id]);
        $medico = $consulta->fetch();

        if ($medico === false) {
            return null;
        }

        $medico['especialidades'] = self::idsDeEspecialidades($pdo, $id);

        return $medico;
    }

    /**
     * Registra un médico nuevo con sus especialidades.
     * Lanza ReglaException si el registro profesional ya existe o una especialidad está inactiva.
     */
    public static function registrar(array $datos, array $idsEspecialidades): int
    {
        $pdo = Conexion::obtener();

        try {
            $pdo->beginTransaction();
            self::verificarActivas($pdo, $idsEspecialidades);

            $consulta = $pdo->prepare(
                'INSERT INTO medico (registro_profesional, nombres, apellidos, telefono, correo)
                 VALUES (:registro_profesional, :nombres, :apellidos, :telefono, :correo)
                 RETURNING id_medico'
            );
            $consulta->execute(self::parametros($datos));
            $id = (int) $consulta->fetchColumn();

            self::agregarEspecialidades($pdo, $id, $idsEspecialidades);
            $pdo->commit();

            return $id;
        } catch (PDOException $e) {
            self::deshacer($pdo);
            throw ErroresBD::traducir($e, [
                '23505' => 'Ya existe un médico con ese número de registro profesional.',
            ]);
        } catch (Throwable $e) {
            self::deshacer($pdo);
            throw $e;
        }
    }

    /**
     * Modifica los datos de un médico y reemplaza sus especialidades activas.
     * Las especialidades inactivas que ya tenía el médico se conservan, porque el formulario
     * solo muestra las activas. No se puede quitar una especialidad si tiene citas registradas.
     */
    public static function modificar(int $id, array $datos, array $idsEspecialidades): void
    {
        $pdo = Conexion::obtener();

        try {
            $pdo->beginTransaction();
            self::verificarActivas($pdo, $idsEspecialidades);

            $parametros = self::parametros($datos);
            $parametros[':id_medico'] = $id;
            $pdo->prepare(
                'UPDATE medico
                    SET registro_profesional = :registro_profesional,
                        nombres = :nombres,
                        apellidos = :apellidos,
                        telefono = :telefono,
                        correo = :correo
                  WHERE id_medico = :id_medico'
            )->execute($parametros);

            $actuales = self::idsDeEspecialidades($pdo, $id);
            $deseadas = array_values(array_unique(array_merge(
                array_map('intval', $idsEspecialidades),
                self::especialidadesInactivas($pdo, $id)
            )));

            $quitar = array_diff($actuales, $deseadas);
            $agregar = array_diff($deseadas, $actuales);

            $borrar = $pdo->prepare(
                'DELETE FROM medico_especialidad WHERE id_medico = :id_medico AND id_especialidad = :id_especialidad'
            );
            foreach ($quitar as $idEspecialidad) {
                $borrar->execute([':id_medico' => $id, ':id_especialidad' => $idEspecialidad]);
            }

            self::agregarEspecialidades($pdo, $id, $agregar);
            $pdo->commit();
        } catch (PDOException $e) {
            self::deshacer($pdo);
            throw ErroresBD::traducir($e, [
                '23505' => 'Ya existe un médico con ese número de registro profesional.',
                '23503' => 'No se puede quitar una especialidad que ya tiene citas registradas con este médico.',
            ]);
        } catch (Throwable $e) {
            self::deshacer($pdo);
            throw $e;
        }
    }

    /** Activa o desactiva un médico (RN11). Nunca se borra. */
    public static function cambiarActivo(int $id, bool $activo): void
    {
        $consulta = Conexion::obtener()->prepare(
            'UPDATE medico SET activo = :activo WHERE id_medico = :id_medico'
        );
        $consulta->execute([
            ':activo' => $activo ? 'true' : 'false',
            ':id_medico' => $id,
        ]);
    }

    /** Ids de las especialidades asignadas al médico. */
    public static function idsDeEspecialidades(PDO $pdo, int $idMedico): array
    {
        $consulta = $pdo->prepare(
            'SELECT id_especialidad FROM medico_especialidad
              WHERE id_medico = :id_medico
              ORDER BY id_especialidad'
        );
        $consulta->execute([':id_medico' => $idMedico]);

        return array_map('intval', array_column($consulta->fetchAll(), 'id_especialidad'));
    }

    /** Ids de las especialidades inactivas que tiene asignadas el médico. */
    private static function especialidadesInactivas(PDO $pdo, int $idMedico): array
    {
        $consulta = $pdo->prepare(
            'SELECT me.id_especialidad
               FROM medico_especialidad me
               JOIN especialidad e ON e.id_especialidad = me.id_especialidad
              WHERE me.id_medico = :id_medico AND NOT e.activo'
        );
        $consulta->execute([':id_medico' => $idMedico]);

        return array_map('intval', array_column($consulta->fetchAll(), 'id_especialidad'));
    }

    /** Comprueba que todas las especialidades elegidas existan y estén activas. */
    private static function verificarActivas(PDO $pdo, array $ids): void
    {
        $consulta = $pdo->prepare('SELECT activo::int FROM especialidad WHERE id_especialidad = :id_especialidad');

        foreach ($ids as $id) {
            $consulta->execute([':id_especialidad' => (int) $id]);
            $activo = $consulta->fetchColumn();

            if ($activo === false || (int) $activo !== 1) {
                throw new ReglaException('Una de las especialidades seleccionadas no está disponible.');
            }
        }
    }

    /** Inserta la relación médico-especialidad para cada id. */
    private static function agregarEspecialidades(PDO $pdo, int $idMedico, array $ids): void
    {
        $consulta = $pdo->prepare(
            'INSERT INTO medico_especialidad (id_medico, id_especialidad)
             VALUES (:id_medico, :id_especialidad)'
        );

        foreach ($ids as $id) {
            $consulta->execute([':id_medico' => $idMedico, ':id_especialidad' => (int) $id]);
        }
    }

    /** Convierte los datos del formulario en parámetros con nombre. */
    private static function parametros(array $datos): array
    {
        $parametros = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $datos[$campo] ?? '';
            $parametros[':' . $campo] = $valor === '' ? null : $valor;
        }

        return $parametros;
    }

    /** Deshace la transacción si quedó abierta. */
    private static function deshacer(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
