<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Sesion;

Sesion::iniciar();
Sesion::cerrar();

header('Location: login.php?motivo=salir');
exit;
