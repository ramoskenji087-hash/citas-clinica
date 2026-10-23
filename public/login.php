<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Sesion;
use App\Servicios\AutenticacionService;
use App\Vistas\Plantilla;

Sesion::iniciar();

// Si ya hay una sesión activa, no se muestra el formulario otra vez.
if (Sesion::estaAutenticado()) {
    header('Location: inicio.php');
    exit;
}

$error = null;
$identificador = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        $identificador = is_string($_POST['usuario'] ?? null) ? trim($_POST['usuario']) : '';
        $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';

        if ($identificador === '' || $clave === '') {
            $error = 'Escriba su usuario o correo y su contraseña.';
        } else {
            try {
                $usuario = AutenticacionService::autenticar($identificador, $clave);
            } catch (RuntimeException $e) {
                $usuario = null;
                $error = 'No se pudo verificar el acceso. Intente más tarde.';
            }

            if ($usuario !== null) {
                Sesion::iniciarUsuario($usuario);
                header('Location: inicio.php');
                exit;
            }

            // Mismo mensaje para usuario inexistente, clave incorrecta o cuenta inactiva.
            $error ??= 'Usuario o contraseña incorrectos.';
        }
    }
}

// Mensajes que llegan por la dirección (por ejemplo, después de cerrar sesión).
$codigo = is_string($_GET['motivo'] ?? null) ? $_GET['motivo'] : '';
$aviso = match ($codigo) {
    'sesion' => 'Debe iniciar sesión para ver esa página.',
    'salir'  => 'Sesión cerrada correctamente.',
    default  => null,
};

Plantilla::inicio('Iniciar sesión', false);
?>
<section class="acceso">
  <h1>Iniciar sesión</h1>

  <?php if ($error !== null): ?>
    <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($error) ?></p>
  <?php elseif ($aviso !== null): ?>
    <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
  <?php endif; ?>

  <form method="post" action="login.php">
    <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

    <div class="campo">
      <label for="usuario">Usuario o correo</label>
      <input id="usuario" name="usuario" type="text" autocomplete="username" required
             value="<?= Plantilla::e($identificador) ?>">
    </div>

    <div class="campo">
      <label for="clave">Contraseña</label>
      <input id="clave" name="clave" type="password" autocomplete="current-password" required>
    </div>

    <button class="btn btn-principal" type="submit">Entrar</button>
  </form>
</section>
<?php
Plantilla::fin();
