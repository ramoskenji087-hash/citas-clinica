<?php
declare(strict_types=1);

namespace App\Datos;

use App\Servicios\ReglaException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Traduce los errores de PostgreSQL en mensajes que el usuario puede leer.
 *
 * - Dato repetido (23505) u otro error propio del caso de uso: el servicio indica el mensaje.
 * - Reglas de la base (triggers VS001 a VS012 y restricción de exclusión 23P01): se muestra
 *   el texto que la base redactó, porque ya está en español.
 * - Cualquier otro error: se registra en el log del servidor y el usuario recibe un mensaje
 *   genérico, sin credenciales ni detalles técnicos (sección 7).
 */
final class ErroresBD
{
    /**
     * Devuelve la excepción que debe ver el usuario.
     *
     * @param array<string, string> $mensajesPorCodigo mensajes propios del caso de uso, por código SQLSTATE
     */
    public static function traducir(PDOException $e, array $mensajesPorCodigo = []): Throwable
    {
        $codigo = (string) ($e->errorInfo[0] ?? '');

        if (isset($mensajesPorCodigo[$codigo])) {
            return new ReglaException($mensajesPorCodigo[$codigo]);
        }

        if ($codigo === '23P01') {
            return new ReglaException('El horario ya está ocupado por otra cita.');
        }

        if (str_starts_with($codigo, 'VS')) {
            return new ReglaException(self::mensajeDeLaBase($e));
        }

        error_log('[citas-clinica] Error de base de datos (' . $codigo . '): ' . $e->getMessage());

        return new RuntimeException('No se pudo guardar la información. Intente más tarde.');
    }

    /** Extrae el texto que PostgreSQL escribió después de "ERROR:". */
    private static function mensajeDeLaBase(PDOException $e): string
    {
        if (preg_match('/ERROR:\s*(.+)/', $e->getMessage(), $coincidencia) === 1) {
            return trim($coincidencia[1]);
        }

        return 'No se cumple una regla del sistema.';
    }
}
