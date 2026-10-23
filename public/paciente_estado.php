<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\PacienteService;
use RuntimeException;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);

// Solo se acepta POST con token válido: así un enlace de otro sitio no puede desactivar pacientes (CSRF).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
    header('Location: pacientes.php?aviso=error');
    exit;
}

$id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;
$accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

if ($id === 0 || !in_array($accion, ['activar', 'desactivar'], true)) {
    header('Location: pacientes.php?aviso=error');
    exit;
}

try {
    PacienteService::cambiarActivo($id, $accion === 'activar');
    $resultado = $accion === 'activar' ? 'activado' : 'desactivado';
} catch (RuntimeException $e) {
    $resultado = 'error';
}

header('Location: pacientes.php?aviso=' . $resultado);
exit;
