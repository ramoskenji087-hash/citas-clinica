<?php
declare(strict_types=1);

/**
 * Prueba de conexión a la base de datos.
 *
 * Se ejecuta desde la consola de XAMPP, no desde el navegador:
 *   php tests/conexion.php
 *
 * Resultado esperado:
 *   Conexión correcta. Roles en la base: 4
 *   Zona horaria de la sesión: (la que puso en config/local.php)
 */

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;

try {
    $pdo = Conexion::obtener();

    $fila = $pdo->query('SELECT COUNT(*) AS total FROM rol')->fetch();
    echo "Conexión correcta. Roles en la base: {$fila['total']}\n";

    $zona = $pdo->query('SHOW TIME ZONE')->fetchColumn();
    echo "Zona horaria de la sesión: {$zona}\n";
} catch (Throwable $e) {
    // Aquí sí se muestra el mensaje, porque es una prueba en la consola del desarrollador.
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
