<?php
declare(strict_types=1);

/**
 * Arranque común del sistema.
 *
 * Lo incluyen todas las páginas de public/ y las pruebas de tests/ al inicio.
 * Hace dos cosas:
 *   1. Configura los errores: se registran en el log del servidor y nunca se
 *      muestran en pantalla (sección 7: sin rutas, trazas ni credenciales).
 *   2. Carga automáticamente las clases del namespace App: App\Datos\Conexion
 *      se busca en app/Datos/Conexion.php.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

spl_autoload_register(function (string $clase): void {
    $prefijo = 'App\\';

    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return; // Clase ajena al proyecto: otro cargador se encargará de ella.
    }

    $relativa = str_replace('\\', DIRECTORY_SEPARATOR, substr($clase, strlen($prefijo)));
    $ruta = __DIR__ . DIRECTORY_SEPARATOR . $relativa . '.php';

    if (is_file($ruta)) {
        require $ruta;
    }
});
