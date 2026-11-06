<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Servicios\RecordatorioService;

header('Content-Type: text/plain; charset=UTF-8');

// Solo el programador externo conoce la clave. Sin ella, esta página no hace nada.
$config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
$clave = is_string($_GET['clave'] ?? null) ? $_GET['clave'] : '';

if ($config['cron_clave'] === '' || !hash_equals($config['cron_clave'], $clave)) {
    http_response_code(403);
    echo "Acceso no autorizado.\n";
    exit;
}

// Abrir la conexión fija la zona horaria antes de calcular "dentro de 24 horas".
Conexion::obtener();

$hasta = (new DateTimeImmutable('now'))->modify('+24 hours');
$resultado = RecordatorioService::enviarPendientes($hasta);

echo "Recordatorios enviados: {$resultado['enviados']}. No enviados: {$resultado['fallidos']}.\n";
