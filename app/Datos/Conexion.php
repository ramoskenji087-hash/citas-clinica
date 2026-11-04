<?php
declare(strict_types=1);

namespace App\Datos;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO única a PostgreSQL para toda la aplicación.
 *
 * - Los errores de base de datos se lanzan como excepciones (ERRMODE_EXCEPTION).
 * - Los resultados vienen como arreglos asociativos.
 * - La zona horaria de la sesión se toma de config/app.php.
 */
final class Conexion
{
    private static ?PDO $pdo = null;

    /** Nombre de base que reemplaza al de config. Solo lo usan las pruebas automáticas. */
    private static ?string $nombreBase = null;

    public static function obtener(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::crear();
        }

        return self::$pdo;
    }

    /**
     * Cambia la base de datos que usará la conexión. Cierra la conexión actual para que
     * la siguiente llamada a obtener() se conecte a la nueva base.
     */
    public static function usarBase(string $nombre): void
    {
        self::$nombreBase = $nombre;
        self::$pdo = null;
    }

    private static function crear(): PDO
    {
        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $base = $config['base'];

        $nombre = self::$nombreBase ?? $base['nombre'];
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $base['host'], $base['puerto'], $nombre);

        try {
            $pdo = new PDO($dsn, $base['usuario'], $base['clave'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('No se pudo conectar con la base de datos. Revise config/local.php.', 0, $e);
        }

        // Zona horaria de PHP y de la sesión de PostgreSQL. Las páginas calculan "hoy" y las fechas
        // por defecto con date(), así que PHP debe usar la misma zona que la base.
        date_default_timezone_set($config['zona_horaria']);
        // SET no acepta parámetros enlazados en PostgreSQL; set_config() sí.
        $pdo->prepare("SELECT set_config('TimeZone', :zona, false)")->execute([':zona' => $config['zona_horaria']]);

        return $pdo;
    }
}