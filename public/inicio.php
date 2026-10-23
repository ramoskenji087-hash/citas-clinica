<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Vistas\Plantilla;

Sesion::iniciar();

// Sin sesión no se muestra nada de esta página (P9, RF01).
Sesion::requerirSesion();

$usuario = Sesion::usuarioActual();

Plantilla::inicio('Inicio');
?>
<h1>Hola, <?= Plantilla::e($usuario['nombre']) ?></h1>
<p class="resumen">Ha entrado como <?= Plantilla::e(Roles::nombre((int) $usuario['id_rol'])) ?>.</p>
<p>Use el menú superior para navegar. Las opciones que ve dependen de su rol (RN12).</p>
<?php
Plantilla::fin();
