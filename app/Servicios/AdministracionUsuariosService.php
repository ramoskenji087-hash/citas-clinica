<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Datos\ErroresBD;
use PDO;
use PDOException;
use Throwable;

/**
 * Administración de cuentas de usuario (RF01, RN12).
 *
 * - Cada médico y cada paciente puede tener como máximo una cuenta.
 * - La contraseña se guarda solo como hash (password_hash).
 * - El administrador no puede desactivar su propia cuenta, para no quedar fuera del sistema.
 */
final class AdministracionUsuariosService
{
    /** Todas las cuentas, con su rol y su vínculo a una ficha. */
    public static function listar(): array
    {
        return Conexion::obtener()->query(
            'SELECT u.id_usuario, u.nombre_usuario, u.correo, u.nombres, u.apellidos,
                    u.id_rol, r.nombre AS rol, u.id_medico, u.id_paciente,
                    u.puede_marcar_atendida::int AS puede_marcar_atendida, u.activo::int AS activo,
                    m.codigo AS codigo_medico, p.codigo AS codigo_paciente
               FROM usuario u
               JOIN rol r ON r.id_rol = u.id_rol
               LEFT JOIN medico m ON m.id_medico = u.id_medico
               LEFT JOIN paciente p ON p.id_paciente = u.id_paciente
              ORDER BY u.apellidos, u.nombres'
        )->fetchAll();
    }

    /** Datos de una cuenta, o null si no existe. */
    public static function obtener(int $id): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT id_usuario, nombre_usuario, correo, nombres, apellidos, id_rol, id_medico, id_paciente,
                    puede_marcar_atendida::int AS puede_marcar_atendida, activo::int AS activo
               FROM usuario
              WHERE id_usuario = :id_usuario'
        );
        $consulta->execute([':id_usuario' => $id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /** Médicos que todavía no tienen cuenta, más el que ya tenga la cuenta indicada (si aplica). */
    public static function medicosDisponibles(int $idUsuarioActual = 0): array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT m.id_medico, m.codigo, m.nombres, m.apellidos
               FROM medico m
              WHERE NOT EXISTS (SELECT 1 FROM usuario u WHERE u.id_medico = m.id_medico AND u.id_usuario <> :actual)
              ORDER BY m.apellidos, m.nombres'
        );
        $consulta->execute([':actual' => $idUsuarioActual]);

        return $consulta->fetchAll();
    }

    /** Pacientes que todavía no tienen cuenta, más el que ya tenga la cuenta indicada (si aplica). */
    public static function pacientesDisponibles(int $idUsuarioActual = 0): array
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT p.id_paciente, p.codigo, p.nombres, p.apellidos
               FROM paciente p
              WHERE NOT EXISTS (SELECT 1 FROM usuario u WHERE u.id_paciente = p.id_paciente AND u.id_usuario <> :actual)
              ORDER BY p.apellidos, p.nombres'
        );
        $consulta->execute([':actual' => $idUsuarioActual]);

        return $consulta->fetchAll();
    }

    /**
     * Crea una cuenta con su contraseña inicial. Lanza ReglaException si el nombre de usuario,
     * el correo o el vínculo ya existen.
     *
     * @param array<string, mixed> $d
     */
    public static function crear(array $d): int
    {
        $pdo = Conexion::obtener();
        self::verificarUnicos($pdo, $d, 0);

        try {
            $consulta = $pdo->prepare(
                'INSERT INTO usuario (nombre_usuario, correo, nombres, apellidos, password_hash, id_rol,
                                      id_medico, id_paciente, puede_marcar_atendida, activo)
                 VALUES (:nombre_usuario, :correo, :nombres, :apellidos, :hash, :rol,
                         :medico, :paciente, :permiso, TRUE)
                 RETURNING id_usuario'
            );
            $consulta->execute(self::parametros($d) + [
                ':hash' => password_hash((string) $d['clave'], PASSWORD_DEFAULT),
            ]);

            return (int) $consulta->fetchColumn();
        } catch (PDOException $e) {
            throw ErroresBD::traducir($e, [
                '23505' => 'Ya existe una cuenta con ese nombre de usuario o correo.',
                '23514' => 'El rol no coincide con la ficha vinculada.',
            ]);
        }
    }

    /**
     * Modifica una cuenta. Si se escribe una contraseña nueva, se reemplaza el hash; si no, se conserva.
     *
     * @param array<string, mixed> $d
     */
    public static function modificar(int $id, array $d): void
    {
        $pdo = Conexion::obtener();
        self::verificarUnicos($pdo, $d, $id);

        $parametros = self::parametros($d) + [':id_usuario' => $id];
        $claveNueva = (string) ($d['clave'] ?? '');

        $sql = 'UPDATE usuario
                   SET nombre_usuario = :nombre_usuario, correo = :correo, nombres = :nombres,
                       apellidos = :apellidos, id_rol = :rol, id_medico = :medico,
                       id_paciente = :paciente, puede_marcar_atendida = :permiso';

        if ($claveNueva !== '') {
            $sql .= ', password_hash = :hash';
            $parametros[':hash'] = password_hash($claveNueva, PASSWORD_DEFAULT);
        }

        try {
            $pdo->prepare($sql . ' WHERE id_usuario = :id_usuario')->execute($parametros);
        } catch (PDOException $e) {
            throw ErroresBD::traducir($e, [
                '23505' => 'Ya existe una cuenta con ese nombre de usuario o correo.',
                '23514' => 'El rol no coincide con la ficha vinculada.',
            ]);
        }
    }

    /**
     * Activa o desactiva una cuenta. El administrador no puede desactivar su propia cuenta.
     */
    public static function cambiarActivo(int $id, bool $activo, int $idAdministrador): void
    {
        if (!$activo && $id === $idAdministrador) {
            throw new ReglaException('No puede desactivar su propia cuenta.');
        }

        Conexion::obtener()->prepare('UPDATE usuario SET activo = :activo WHERE id_usuario = :id_usuario')
            ->execute([':activo' => $activo ? 'true' : 'false', ':id_usuario' => $id]);
    }

    /**
     * Comprueba antes de guardar que el nombre de usuario, el correo y los vínculos no estén
     * usados por otra cuenta. Así el mensaje es claro antes de llegar a la base.
     *
     * @param array<string, mixed> $d
     */
    private static function verificarUnicos(PDO $pdo, array $d, int $idActual): void
    {
        $consulta = $pdo->prepare(
            'SELECT 1 FROM usuario
              WHERE id_usuario <> :actual
                AND (lower(nombre_usuario) = lower(:nombre) OR lower(correo) = lower(:correo))
              LIMIT 1'
        );
        $consulta->execute([':actual' => $idActual, ':nombre' => (string) $d['nombre_usuario'], ':correo' => (string) $d['correo']]);
        if ($consulta->fetchColumn() !== false) {
            throw new ReglaException('Ya existe una cuenta con ese nombre de usuario o correo.');
        }

        $medico = (int) ($d['id_medico'] ?? 0);
        if ($medico > 0) {
            $ocupado = $pdo->prepare('SELECT 1 FROM usuario WHERE id_medico = :medico AND id_usuario <> :actual LIMIT 1');
            $ocupado->execute([':medico' => $medico, ':actual' => $idActual]);
            if ($ocupado->fetchColumn() !== false) {
                throw new ReglaException('Esa ficha de médico ya tiene una cuenta de usuario.');
            }
        }

        $paciente = (int) ($d['id_paciente'] ?? 0);
        if ($paciente > 0) {
            $ocupado = $pdo->prepare('SELECT 1 FROM usuario WHERE id_paciente = :paciente AND id_usuario <> :actual LIMIT 1');
            $ocupado->execute([':paciente' => $paciente, ':actual' => $idActual]);
            if ($ocupado->fetchColumn() !== false) {
                throw new ReglaException('Esa ficha de paciente ya tiene una cuenta de usuario.');
            }
        }
    }

    /**
     * Parámetros comunes de alta y modificación. El permiso solo se guarda para el rol Médico,
     * y cada vínculo solo para su rol, para cumplir los CHECK de la tabla usuario.
     *
     * @param array<string, mixed> $d
     */
    private static function parametros(array $d): array
    {
        $rol = (int) $d['rol'];

        return [
            ':nombre_usuario' => (string) $d['nombre_usuario'],
            ':correo' => (string) $d['correo'],
            ':nombres' => (string) $d['nombres'],
            ':apellidos' => (string) $d['apellidos'],
            ':rol' => $rol,
            ':medico' => $rol === 3 ? (int) $d['id_medico'] : null,
            ':paciente' => $rol === 4 ? (int) $d['id_paciente'] : null,
            ':permiso' => $rol === 3 && !empty($d['puede_marcar_atendida']) ? 'true' : 'false',
        ];
    }
}
