<?php
declare(strict_types=1);

/**
 * Configuración general del sistema.
 *
 * Cada valor se toma primero de una variable de entorno (para la nube) y, si no existe,
 * de config/local.php (para su computadora). Así la clave nunca tiene que estar en el código.
 *
 * Si no hay ni variables de entorno ni config/local.php, registra el detalle en el log
 * del servidor y muestra un mensaje genérico (sección 7: sin credenciales ni detalles internos).
 */

$rutaLocal = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';
$local = [];
if (is_file($rutaLocal)) {
    $local = require $rutaLocal;
}

if ($local === [] && getenv('DB_NOMBRE') === false) {
    error_log('[citas-clinica] Falta config/local.php o las variables de entorno. Copie config/local.ejemplo.php y ajuste los valores.');
    http_response_code(500);
    exit('El sistema no está configurado. Contacte al administrador.');
}

/** Variable de entorno si existe; si no, el valor de config/local.php; si tampoco, el respaldo. */
$valor = static function (string $variable, string $clave, string $respaldo = '') use ($local): string {
    $desdeEntorno = getenv($variable);

    if ($desdeEntorno !== false && $desdeEntorno !== '') {
        return $desdeEntorno;
    }

    return (string) ($local[$clave] ?? $respaldo);
};

$zona = $valor('ZONA_HORARIA', 'zona_horaria');

if (!in_array($zona, timezone_identifiers_list(), true)) {
    error_log('[citas-clinica] La zona horaria no es válida (variable ZONA_HORARIA o zona_horaria en config/local.php).');
    http_response_code(500);
    exit('El sistema no está configurado. Contacte al administrador.');
}

return [
    'nombre_sistema' => 'Clínica Vida Salud',
    // Clave secreta del programador de recordatorios. En Render: variable CRON_CLAVE.
    'cron_clave'     => $valor('CRON_CLAVE', 'cron_clave', ''),
    'zona_horaria'   => $zona,
    // Dirección pública de la carpeta public, sin barra final. Se usa para armar los enlaces del correo.
    'url_base'       => rtrim($valor('URL_BASE', 'url_base', 'http://localhost/citas-clinica/public'), '/'),
    'base' => [
        'host'    => $valor('DB_HOST', 'db_host', 'localhost'),
        'puerto'  => $valor('DB_PUERTO', 'db_puerto', '5432'),
        'nombre'  => $valor('DB_NOMBRE', 'db_nombre', 'citas_clinica'),
        'usuario' => $valor('DB_USUARIO', 'db_usuario', 'postgres'),
        'clave'   => $valor('DB_CLAVE', 'db_clave', ''),
        // require en la nube (Neon); vacío en su computadora.
        'sslmode' => $valor('DB_SSLMODE', 'db_sslmode', ''),
    ],
    'correo' => [
        'api_key'          => $valor('BREVO_API_KEY', 'brevo_api_key', ''),
        'remitente'        => $valor('CORREO_REMITENTE', 'correo_remitente', ''),
        'nombre_remitente' => $valor('CORREO_NOMBRE', 'correo_nombre', 'Clínica Vida Salud'),
    ],
];
