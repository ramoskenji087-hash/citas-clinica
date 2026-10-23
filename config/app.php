<?php
declare(strict_types=1);

/**
 * Configuración general del sistema.
 *
 * Lee config/local.php (clave de la base y zona horaria) y devuelve un arreglo.
 * Si falta ese archivo o la zona horaria no es válida, registra el detalle en el
 * log del servidor y muestra un mensaje genérico al usuario (sección 7:
 * manejo de errores sin mostrar credenciales ni detalles internos).
 */

$rutaLocal = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';

if (!is_file($rutaLocal)) {
    error_log('[citas-clinica] Falta config/local.php. Copie config/local.ejemplo.php y ajuste los valores.');
    http_response_code(500);
    exit('El sistema no está configurado. Contacte al administrador.');
}

$local = require $rutaLocal;

if (!in_array($local['zona_horaria'] ?? '', timezone_identifiers_list(), true)) {
    error_log('[citas-clinica] El valor zona_horaria de config/local.php no es válido.');
    http_response_code(500);
    exit('El sistema no está configurado. Contacte al administrador.');
}

return [
    'nombre_sistema' => 'Clínica Vida Salud',
    'zona_horaria'   => $local['zona_horaria'],
    'base' => [
        'host'    => $local['db_host'] ?? 'localhost',
        'puerto'  => $local['db_puerto'] ?? '5432',
        'nombre'  => $local['db_nombre'] ?? 'citas_clinica',
        'usuario' => $local['db_usuario'] ?? 'postgres',
        'clave'   => $local['db_clave'] ?? '',
    ],
];
