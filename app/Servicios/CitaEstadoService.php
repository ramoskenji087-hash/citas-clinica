<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use App\Datos\ErroresBD;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

/**
 * Cambios de estado de una cita (RF08, RF09, RF10, RN09, RN10, RN12, RN13, RC04, RC06, RC07).
 *
 * - La máquina de estados está en TRANSICIONES. Debe coincidir con la función
 *   fn_transicion_permitida de la base (sql/triggers.sql).
 * - Cada cambio se hace en una transacción, con la cita bloqueada con FOR UPDATE.
 * - Atendida e Inasistencia solo se registran cuando la cita ya comenzó (RC07).
 * - Las citas nunca se borran: se cancelan con motivo y conservan su historial (RF09, RN10).
 */
final class CitaEstadoService
{
    /** Transiciones permitidas entre estados (RF10). Los estados finales no tienen salidas. */
    public const TRANSICIONES = [
        'Programada' => ['Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'],
        'Confirmada' => ['Atendida', 'Cancelada', 'Inasistencia'],
    ];

    /** Estados que solo se registran cuando la cita ya comenzó (RC07). */
    private const REQUIEREN_INICIO = ['Atendida', 'Inasistencia'];

    /** Datos completos de una cita, o null si no existe. */
    public static function obtener(int $id): ?array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT c.id_cita, c.codigo, c.id_medico, c.estado,
                    c.inicio::text AS inicio, c.fin::text AS fin,
                    c.motivo, c.motivo_cancelacion,
                    p.nombres || ' ' || p.apellidos AS paciente,
                    m.nombres || ' ' || m.apellidos AS medico,
                    e.nombre AS especialidad
               FROM cita c
               JOIN paciente p ON p.id_paciente = c.id_paciente
               JOIN medico m ON m.id_medico = c.id_medico
               JOIN especialidad e ON e.id_especialidad = c.id_especialidad
              WHERE c.id_cita = :id_cita"
        );
        $consulta->execute([':id_cita' => $id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /** Indica si una cita ya comenzó, según la hora actual (RC07). */
    public static function yaComenzo(string $inicio): bool
    {
        return new DateTimeImmutable($inicio) <= new DateTimeImmutable('now');
    }

    /** Indica si el usuario tiene el permiso para marcar citas como atendidas (RN12). */
    public static function puedeMarcarAtendida(int $idUsuario): bool
    {
        $consulta = Conexion::obtener()->prepare(
            'SELECT puede_marcar_atendida::int FROM usuario WHERE id_usuario = :id_usuario'
        );
        $consulta->execute([':id_usuario' => $idUsuario]);

        return (int) $consulta->fetchColumn() === 1;
    }

    /** Programada o Confirmada: los únicos estados que se pueden reprogramar o cambiar. */
    public static function estaAbierta(string $estado): bool
    {
        return $estado === 'Programada' || $estado === 'Confirmada';
    }

    public static function confirmar(int $id): void
    {
        self::cambiarEstado($id, 'Confirmada');
    }

    public static function registrarInasistencia(int $id): void
    {
        self::cambiarEstado($id, 'Inasistencia');
    }

    /** Solo el médico de la cita, con permiso, puede marcarla como atendida (RN12). */
    public static function marcarAtendida(int $id, int $idMedico, int $idUsuario): void
    {
        if (!self::puedeMarcarAtendida($idUsuario)) {
            throw new ReglaException('Su usuario no tiene permiso para marcar citas como atendidas.');
        }

        self::cambiarEstado($id, 'Atendida', null, $idMedico);
    }

    /** Cancela la cita con motivo. La cita queda en el historial y su horario se libera (RF09). */
    public static function cancelar(int $id, string $motivo): void
    {
        self::cambiarEstado($id, 'Cancelada', trim($motivo));
    }

    /**
     * Reprograma una cita Programada o Confirmada a una nueva fecha y hora (RF08).
     * El resultado siempre queda en Programada (RC04).
     */
    public static function reprogramar(int $id, string $fecha, string $hora): void
    {
        $pdo = Conexion::obtener();

        try {
            $pdo->beginTransaction();

            $cita = self::bloquearCita($pdo, $id);

            if (!self::estaAbierta($cita['estado'])) {
                throw new ReglaException('Solo se reprograman citas Programadas o Confirmadas.');
            }

            $idMedico = (int) $cita['id_medico'];

            // RN13: bloquear al médico antes de comprobar la hora.
            $pdo->prepare('SELECT id_medico FROM medico WHERE id_medico = :id_medico FOR UPDATE')
                ->execute([':id_medico' => $idMedico]);

            $elegida = null;
            foreach (CitaService::horasLibres($idMedico, $fecha) as $libre) {
                if ($libre['hora'] === $hora) {
                    $elegida = $libre;
                    break;
                }
            }

            if ($elegida === null) {
                throw new ReglaException('Esa hora ya no está disponible. Elija otra.');
            }

            $inicio = $fecha . ' ' . $hora . ':00';
            $fin = (new DateTimeImmutable($inicio))
                ->modify('+' . $elegida['duracion'] . ' minutes')
                ->format('Y-m-d H:i:s');

            $pdo->prepare(
                "UPDATE cita
                    SET inicio = :inicio, fin = :fin, estado = 'Programada'
                  WHERE id_cita = :id_cita"
            )->execute([':inicio' => $inicio, ':fin' => $fin, ':id_cita' => $id]);

            $pdo->commit();
        } catch (PDOException $e) {
            self::deshacer($pdo);
            throw ErroresBD::traducir($e, ['23514' => 'Revise la fecha y la hora de la cita.']);
        } catch (Throwable $e) {
            self::deshacer($pdo);
            throw $e;
        }
    }

    /**
     * Cambia el estado de una cita según la máquina de estados (RF10).
     * Comprueba la transición, el inicio de la cita y, para atendida, el médico dueño de la cita.
     */
    private static function cambiarEstado(int $id, string $nuevo, ?string $motivo = null, ?int $idMedico = null): void
    {
        $pdo = Conexion::obtener();

        try {
            $pdo->beginTransaction();

            $cita = self::bloquearCita($pdo, $id);

            if ($idMedico !== null && (int) $cita['id_medico'] !== $idMedico) {
                throw new ReglaException('Solo el médico de la cita puede marcarla como atendida.');
            }

            $actual = (string) $cita['estado'];
            if (!in_array($nuevo, self::TRANSICIONES[$actual] ?? [], true)) {
                throw new ReglaException('Una cita en estado ' . $actual . ' no se puede pasar a ' . $nuevo . '.');
            }

            if (in_array($nuevo, self::REQUIEREN_INICIO, true) && !self::yaComenzo((string) $cita['inicio'])) {
                throw new ReglaException('Esta cita todavía no comenzó. Solo se registra ' . strtolower($nuevo) . ' cuando empieza.');
            }

            $pdo->prepare(
                'UPDATE cita
                    SET estado = :estado, motivo_cancelacion = :motivo
                  WHERE id_cita = :id_cita'
            )->execute([':estado' => $nuevo, ':motivo' => $motivo, ':id_cita' => $id]);

            $pdo->commit();
        } catch (PDOException $e) {
            self::deshacer($pdo);
            throw ErroresBD::traducir($e, ['23514' => 'El motivo de la cancelación no es válido.']);
        } catch (Throwable $e) {
            self::deshacer($pdo);
            throw $e;
        }
    }

    /** Bloquea la fila de la cita para cambiarla sin que otra operación la toque al mismo tiempo. */
    private static function bloquearCita(PDO $pdo, int $id): array
    {
        $consulta = $pdo->prepare(
            'SELECT id_cita, id_medico, estado, inicio::text AS inicio
               FROM cita
              WHERE id_cita = :id_cita
                FOR UPDATE'
        );
        $consulta->execute([':id_cita' => $id]);
        $cita = $consulta->fetch();

        if ($cita === false) {
            throw new ReglaException('La cita no existe.');
        }

        return $cita;
    }

    /** Deshace la transacción si quedó abierta. */
    private static function deshacer(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
