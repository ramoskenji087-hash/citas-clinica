<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;
use DateTimeImmutable;
use RuntimeException;

/**
 * Recordatorios automáticos de citas por correo (sección 3.4 del documento).
 *
 * Cada cita recibe un solo recordatorio, y solo si su paciente lo aceptó en su ficha. El envío lo
 * dispara un programador externo que llama a public/cron_recordatorios.php con una clave secreta
 * (variable CRON_CLAVE), así que no depende de que alguien esté conectado.
 */
final class RecordatorioService
{
    /**
     * Citas Programadas o Confirmadas que empiezan entre ahora y $hasta, que todavía no recibieron
     * recordatorio y cuyo paciente lo acepta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendientes(DateTimeImmutable $hasta): array
    {
        $consulta = Conexion::obtener()->prepare(
            "SELECT c.id_cita, c.codigo, c.inicio::text AS inicio, c.modalidad,
                    p.nombres || ' ' || p.apellidos AS paciente, p.correo,
                    m.nombres || ' ' || m.apellidos AS medico, e.nombre AS especialidad
               FROM cita c
               JOIN paciente p ON p.id_paciente = c.id_paciente
               JOIN medico m ON m.id_medico = c.id_medico
               JOIN especialidad e ON e.id_especialidad = c.id_especialidad
              WHERE c.estado IN ('Programada', 'Confirmada')
                AND c.recordatorio_enviado = FALSE
                AND p.recibir_notificaciones = TRUE
                AND p.correo IS NOT NULL
                AND c.inicio >= :ahora
                AND c.inicio <= :hasta
              ORDER BY c.inicio"
        );
        $consulta->execute([
            ':ahora' => date('Y-m-d H:i:s'),
            ':hasta' => $hasta->format('Y-m-d H:i:s'),
        ]);

        return $consulta->fetchAll();
    }

    /**
     * Envía los recordatorios pendientes. Cada cita se marca como recordada solo si el correo salió.
     *
     * @return array{enviados: int, fallidos: int}
     */
    public static function enviarPendientes(DateTimeImmutable $hasta): array
    {
        $enviados = 0;
        $fallidos = 0;

        foreach (self::pendientes($hasta) as $cita) {
            try {
                CorreoService::enviar(
                    (string) $cita['correo'],
                    (string) $cita['paciente'],
                    'Recordatorio de su cita ' . $cita['codigo'],
                    self::html($cita),
                    self::texto($cita)
                );
                self::marcarEnviado((int) $cita['id_cita']);
                $enviados++;
            } catch (RuntimeException $e) {
                error_log('[citas-clinica] Recordatorio no enviado (' . $cita['codigo'] . '): ' . $e->getMessage());
                $fallidos++;
            }
        }

        return ['enviados' => $enviados, 'fallidos' => $fallidos];
    }

    /** Marca la cita como recordada, para no volver a enviar el mismo correo. */
    public static function marcarEnviado(int $idCita): void
    {
        Conexion::obtener()
            ->prepare('UPDATE cita SET recordatorio_enviado = TRUE WHERE id_cita = :id_cita')
            ->execute([':id_cita' => $idCita]);
    }

    /** @param array<string, mixed> $cita */
    private static function cuando(array $cita): string
    {
        return date('d/m/Y H:i', strtotime((string) $cita['inicio']));
    }

    /** @param array<string, mixed> $cita */
    private static function modalidad(array $cita): string
    {
        return (string) $cita['modalidad'] === 'Virtual' ? 'virtual (videollamada)' : 'presencial (en la clínica)';
    }

    /** @param array<string, mixed> $cita */
    private static function texto(array $cita): string
    {
        return 'Hola, ' . $cita['paciente'] . ".\n"
            . "Le recordamos su cita en Clínica Vida Salud:\n"
            . 'Fecha y hora: ' . self::cuando($cita) . "\n"
            . 'Médico: Dr(a). ' . $cita['medico'] . ' (' . $cita['especialidad'] . ")\n"
            . 'Modalidad: ' . self::modalidad($cita) . "\n"
            . 'Código: ' . $cita['codigo'] . "\n\n"
            . 'Si no puede asistir, comuníquese con la clínica.';
    }

    /** @param array<string, mixed> $cita */
    private static function html(array $cita): string
    {
        $e = static fn (string $valor): string => htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:Arial,sans-serif;color:#1F2D3A;max-width:560px">'
            . '<h2 style="color:#0E5A6E">Recordatorio de su cita</h2>'
            . '<p>Hola, ' . $e((string) $cita['paciente']) . '.</p>'
            . '<ul>'
            . '<li><strong>Fecha y hora:</strong> ' . $e(self::cuando($cita)) . '</li>'
            . '<li><strong>Médico:</strong> Dr(a). ' . $e((string) $cita['medico'])
            . ' (' . $e((string) $cita['especialidad']) . ')</li>'
            . '<li><strong>Modalidad:</strong> ' . $e(self::modalidad($cita)) . '</li>'
            . '<li><strong>Código:</strong> ' . $e((string) $cita['codigo']) . '</li>'
            . '</ul>'
            . '<p>Si no puede asistir, comuníquese con la clínica.</p>'
            . '</div>';
    }
}
