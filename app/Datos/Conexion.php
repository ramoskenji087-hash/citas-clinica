<?php
declare(strict_types=1);

namespace App\Datos;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Acceso a datos: conexión a PostgreSQL con PDO y el controlador pdo_pgsql.
 *
 * Cumple con:
 *  - Sección 7: PDO con pdo_pgsql y consultas parametrizadas.
 *  - Sección 7: manejo de errores sin mostrar credenciales ni detalles internos.
 *  - RN14: los datos se guardan de forma persistente en PostgreSQL.
 *  - RF06, RF10 y RN06: PHP y PostgreSQL usan la misma zona horaria, para que
 *    las comparaciones de fecha y hora den el mismo resultado en ambos lados.
 *
 * Esta clase es la única que conoce la cadena de conexión.
 */
final class Conexion
{
    /** Conexión única que se reutiliza durante la misma petición. */
    private static ?PDO $pdo = null;

    /** Devuelve la conexión y la crea solo la primera vez. */
    public static function obtener(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::crear();
        }

        return self::$pdo;
    }

    /** Crea la conexión y configura la zona horaria de la sesión. */
    private static function crear(): PDO
    {
        $raiz = dirname(__DIR__, 2);
        $config = require $raiz . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $base = $config['base'];

        // Zona horaria de PHP: la misma que se pondrá en la sesión de PostgreSQL.
        date_default_timezone_set($config['zona_horaria']);

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $base['host'], $base['puerto'], $base['nombre']);

        try {
            $pdo = new PDO($dsn, $base['usuario'], $base['clave'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Consultas preparadas de verdad: PostgreSQL recibe la sentencia y los datos por separado.
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // El valor se enlaza como parámetro, no se concatena en el SQL.
            $zona = $pdo->prepare("SELECT set_config('TimeZone', :zona, false)");
            $zona->execute([':zona' => $config['zona_horaria']]);
        } catch (PDOException $e) {
            // El detalle técnico va solo al log del servidor.
            error_log('[citas-clinica] Error de conexión a PostgreSQL: ' . $e->getMessage());
            throw new RuntimeException('No se pudo conectar con la base de datos. Intente más tarde.');
        }

        return $pdo;
    }
}
