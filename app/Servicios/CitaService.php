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
 * Reserva de citas (RF06, RF07, RN03 a RN09, RN13).
 *
 * - Las horas libres se calculan con los bloques de horario del médico para ese día de la semana
 *   (RN05), con la duración de cada bloque (RN08), sin las citas que ya ocupan el horario
 *   (RN07, RN09) y sin las horas pasadas (RN06).
 * - Al reservar, el médico se bloquea con SELECT ... FOR UPDATE (RN13). Con el bloqueo tomado,
 *   se vuelve a comprobar que la hora sigue libre y solo después se inserta la cita.
 * - La base vuelve a validar cada inserción con los triggers VS001 a VS012 y con la restricción
 *   EXCLUDE, que impide las citas superpuestas aunque dos usuarios reserven a la vez.
 */
final class CitaService
{
    /** Pacientes activos, para el formulario de nueva cita. */
    public static function pacientesActivos(): array
    {
        return Conexion::obtener()->query(
            'SELECT id_paciente, codigo, ci, nombres, apellidos
               FROM paciente
              WHERE activo
              ORDER BY apellidos, nombres'
        )->fetchAll();
    }

    /** Médicos activos. Si se indica una especialidad, solo los que la atienden. */
    public static function medicosActivos(int $idEspecialidad = 0): array
    {
        $consulta = Conexion::obtener()->prepare(
            $idEspecialidad > 0
                ? 'SELECT m.id_medico, m.nombres, m.apellidos
                     FROM medico m
                     JOIN medico_especialidad me ON me.id_medico = m.id_medico
                    WHERE m.activo AND me.id_especialidad = :id_especialidad
                    ORDER BY m.apellidos, m.nombres'
                : 'SELECT m.id_medico, m.nombres, m.apellidos
                     FROM medico m
                    WHERE m.activo
                    ORDER BY m.apellidos, m.nombres'
        );
        $consulta->execute($idEspecialidad > 0 ? [':id_especialidad' => $idEspecialidad] : []);

        return $consulta->fetchAll();
    }

    /**
     * Horas libres de un médico en una fecha. Cada elemento indica la hora de inicio,
     * la hora de fin y la duración de una cita posible.
     *
     * @return array<int, array{hora: string, fin: string, duracion: int}>
     */
    public static function horasLibres(int $idMedico, string $fecha): array
    {
        $pdo = Conexion::obtener();

        $medico = $pdo->prepare('SELECT activo::int FROM medico WHERE id_medico = :id_medico');
        $medico->execute([':id_medico' => $idMedico]);
        $activo = $medico->fetchColumn();

        if ($activo === false || (int) $activo !== 1) {
            return [];
        }

        // 1 = lunes ... 7 = domingo, igual que dia_semana en la base.
        $dia = (int) (new DateTimeImmutable($fecha))->format('N');

        $bloques = $pdo->prepare(
            'SELECT hora_inicio::text AS inicio, hora_fin::text AS fin, duracion_cita
               FROM horario_medico
              WHERE id_medico = :id_medico AND dia_semana = :dia
              ORDER BY hora_inicio'
        );
        $bloques->execute([':id_medico' => $idMedico, ':dia' => $dia]);
        $filasBloques = $bloques->fetchAll();

        $ocupadas = $pdo->prepare(
            "SELECT inicio, fin
               FROM cita
              WHERE id_medico = :id_medico
                AND estado <> 'Cancelada'
                AND inicio::date = :fecha"
        );
        $ocupadas->execute([':id_medico' => $idMedico, ':fecha' => $fecha]);
        $filasOcupadas = $ocupadas->fetchAll();

        $ahora = new DateTimeImmutable('now');
        $libres = [];

        foreach ($filasBloques as $bloque) {
            $duracion = (int) $bloque['duracion_cita'];
            $cursor = new DateTimeImmutable($fecha . ' ' . $bloque['inicio']);
            $limite = new DateTimeImmutable($fecha . ' ' . $bloque['fin']);

            while (true) {
                $finCita = $cursor->modify('+' . $duracion . ' minutes');

                if ($finCita > $limite) {
                    break;
                }

                // Solo horas que aún no han empezado y que no chocan con una cita existente.
                if ($cursor > $ahora && !self::cruzaOcupada($cursor, $finCita, $filasOcupadas)) {
                    $libres[] = [
                        'hora' => $cursor->format('H:i'),
                        'fin' => $finCita->format('H:i'),
                        'duracion' => $duracion,
                    ];
                }

                $cursor = $finCita;
            }
        }

        usort($libres, static fn (array $a, array $b): int => strcmp($a['hora'], $b['hora']));

        return $libres;
    }

    /**
     * Reserva una cita en estado Programada. Devuelve el id y el código (CM-0000).
     *
     * Lanza ReglaException si el paciente, el médico o la especialidad están inactivos, si la hora
     * ya no está libre, o si la base rechaza la inserción.
     *
     * @param array<string, mixed> $datos paciente, especialidad, medico, fecha (AAAA-MM-DD), hora (HH:MM), motivo
     * @return array{id: int, codigo: string}
     */
    public static function registrar(array $datos, int $idUsuario): array
    {
        $pdo = Conexion::obtener();
        $idMedico = (int) $datos['medico'];
        $fecha = (string) $datos['fecha'];
        $hora = (string) $datos['hora'];
        $motivo = trim((string) ($datos['motivo'] ?? ''));

        try {
            $pdo->beginTransaction();

            // RN13: bloquear la fila del médico. Otra reserva para el mismo médico espera hasta terminar.
            $pdo->prepare('SELECT id_medico FROM medico WHERE id_medico = :id_medico FOR UPDATE')
                ->execute([':id_medico' => $idMedico]);

            self::verificarActivos($pdo, $datos);

            // Con el médico bloqueado, se vuelve a comprobar que la hora siga libre.
            $elegida = null;
            foreach (self::horasLibres($idMedico, $fecha) as $libre) {
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

            $consulta = $pdo->prepare(
                "INSERT INTO cita (id_paciente, id_medico, id_especialidad, inicio, fin, motivo, estado, id_usuario_registro)
                 VALUES (:paciente, :medico, :especialidad, :inicio, :fin, :motivo, 'Programada', :usuario)
                 RETURNING id_cita, codigo"
            );
            $consulta->execute([
                ':paciente' => (int) $datos['paciente'],
                ':medico' => $idMedico,
                ':especialidad' => (int) $datos['especialidad'],
                ':inicio' => $inicio,
                ':fin' => $fin,
                ':motivo' => $motivo === '' ? null : $motivo,
                ':usuario' => $idUsuario,
            ]);
            $fila = $consulta->fetch();

            $pdo->commit();

            return ['id' => (int) $fila['id_cita'], 'codigo' => (string) $fila['codigo']];
        } catch (PDOException $e) {
            self::deshacer($pdo);
            throw ErroresBD::traducir($e, [
                '23503' => 'El médico no atiende la especialidad seleccionada.',
            ]);
        } catch (Throwable $e) {
            self::deshacer($pdo);
            throw $e;
        }
    }

    /** Indica si el intervalo [inicio, fin) choca con alguna cita ocupada. */
    private static function cruzaOcupada(DateTimeImmutable $inicio, DateTimeImmutable $fin, array $ocupadas): bool
    {
        foreach ($ocupadas as $cita) {
            $inicioCita = new DateTimeImmutable((string) $cita['inicio']);
            $finCita = new DateTimeImmutable((string) $cita['fin']);

            if ($inicio < $finCita && $fin > $inicioCita) {
                return true;
            }
        }

        return false;
    }

    /** Comprueba que el paciente, el médico y la especialidad estén activos. */
    private static function verificarActivos(PDO $pdo, array $datos): void
    {
        $comprobaciones = [
            ['SELECT activo::int FROM paciente WHERE id_paciente = :id', (int) $datos['paciente'],
                'El paciente está inactivo y no puede tener citas nuevas.'],
            ['SELECT activo::int FROM medico WHERE id_medico = :id', (int) $datos['medico'],
                'El médico está inactivo y no puede recibir citas.'],
            ['SELECT activo::int FROM especialidad WHERE id_especialidad = :id', (int) $datos['especialidad'],
                'La especialidad está inactiva.'],
        ];

        foreach ($comprobaciones as [$sql, $id, $mensaje]) {
            $consulta = $pdo->prepare($sql);
            $consulta->execute([':id' => $id]);
            $activo = $consulta->fetchColumn();

            if ($activo === false || (int) $activo !== 1) {
                throw new ReglaException($mensaje);
            }
        }
    }

    /** Deshace la transacción si quedó abierta. */
    private static function deshacer(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
