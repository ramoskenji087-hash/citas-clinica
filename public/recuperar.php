<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Sesion;
use App\Servicios\CuentaCorreoService;
use App\Validacion\Validador;
use App\Vistas\Plantilla;

Sesion::iniciar();
Conexion::obtener();

$enviado = false;
$error = null;
$correo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        $correo = is_string($_POST['correo'] ?? null) ? trim($_POST['correo']) : '';
        $errorCorreo = Validador::correo($correo, true);

        if ($errorCorreo !== null) {
            $error = $errorCorreo;
        } else {
            try {
                CuentaCorreoService::enviarRecuperacion($correo);
            } catch (\Throwable $e) {
                // El detalle queda en el registro del servidor. Al usuario se le responde igual,
                // para no revelar si el correo está registrado.
                error_log('[citas-clinica] Recuperación de contraseña: ' . $e->getMessage());
            }

            $enviado = true;
        }
    }
}

Plantilla::inicio('Recuperar contraseña', false);
?>
<section class="acceso">
  <h1>Recuperar contraseña</h1>

  <?php if ($enviado): ?>
    <p class="aviso aviso-ok">Si el correo está registrado en una cuenta activa, le enviaremos un enlace para elegir una nueva contraseña. El enlace vence en 1 hora. Revise también la carpeta de spam.</p>
    <p><a href="login.php">Volver a iniciar sesión</a></p>

  <?php else: ?>
    <?php if ($error !== null): ?>
      <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($error) ?></p>
    <?php endif; ?>

    <p>Escriba el correo de su cuenta. Le enviaremos un enlace para elegir una nueva contraseña.</p>

    <form method="post" action="recuperar.php">
      <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

      <div class="campo">
        <label for="correo">Correo electrónico</label>
        <input id="correo" name="correo" type="email" autocomplete="email" required
               value="<?= Plantilla::e($correo) ?>">
      </div>

      <button class="btn btn-principal" type="submit">Enviar enlace</button>
    </form>
    <p><a href="login.php">Volver a iniciar sesión</a></p>
  <?php endif; ?>
</section>
<?php
Plantilla::fin();
