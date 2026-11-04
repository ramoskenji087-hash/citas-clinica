<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Sesion;
use App\Servicios\UsuarioService;
use App\Validacion\ValidadorPortal;
use App\Vistas\Plantilla;

// Cualquier rol puede cambiar su contraseña, siempre que tenga sesión iniciada.
Sesion::iniciar();
Sesion::requerirSesion();

$usuario = Sesion::usuarioActual();

$errores = [];
$errorGeneral = null;
$cambiada = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        $actual = is_string($_POST['clave_actual'] ?? null) ? $_POST['clave_actual'] : '';
        $nueva = is_string($_POST['clave_nueva'] ?? null) ? $_POST['clave_nueva'] : '';
        $repetir = is_string($_POST['clave_repetir'] ?? null) ? $_POST['clave_repetir'] : '';

        $errores = ValidadorPortal::cambioClave($actual, $nueva, $repetir);

        if ($errores === []) {
            try {
                UsuarioService::cambiarClave((int) $usuario['id'], $actual, $nueva);

                // Nuevo identificador de sesión después de cambiar la clave.
                session_regenerate_id(true);
                $cambiada = true;
            } catch (\App\Servicios\ReglaException $e) {
                $errorGeneral = $e->getMessage();
            } catch (\RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

Plantilla::inicio('Cambiar contraseña');
?>
<section class="acceso">
  <h1>Cambiar contraseña</h1>
  <p class="resumen">Cuenta: <?= Plantilla::e($usuario['nombre']) ?></p>

  <?php if ($cambiada): ?>
    <p class="aviso aviso-ok">Su contraseña se cambió correctamente. La próxima vez que entre, use la nueva.</p>
  <?php endif; ?>

  <?php if ($errorGeneral !== null || $errores !== []): ?>
    <div class="resumen-errores" role="alert">
      <p>No se pudo cambiar la contraseña. Revise estos datos:</p>
      <ul>
        <?php if ($errorGeneral !== null): ?>
          <li><?= Plantilla::e($errorGeneral) ?></li>
        <?php endif; ?>
        <?php foreach ($errores as $campo => $mensaje): ?>
          <li><a href="#<?= Plantilla::e($campo) ?>"><?= Plantilla::e($mensaje) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="cambiar_clave.php">
    <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

    <div class="campo<?= isset($errores['clave_actual']) ? ' con-error' : '' ?>">
      <label for="clave_actual">Contraseña actual</label>
      <input id="clave_actual" name="clave_actual" type="password" autocomplete="current-password">
      <?php if (isset($errores['clave_actual'])): ?>
        <p class="mensaje" id="clave_actual-error">Error: <?= Plantilla::e($errores['clave_actual']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['clave_nueva']) ? ' con-error' : '' ?>">
      <label for="clave_nueva">Contraseña nueva</label>
      <input id="clave_nueva" name="clave_nueva" type="password" autocomplete="new-password">
      <?php if (isset($errores['clave_nueva'])): ?>
        <p class="mensaje" id="clave_nueva-error">Error: <?= Plantilla::e($errores['clave_nueva']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['clave_repetir']) ? ' con-error' : '' ?>">
      <label for="clave_repetir">Repita la contraseña nueva</label>
      <input id="clave_repetir" name="clave_repetir" type="password" autocomplete="new-password">
      <?php if (isset($errores['clave_repetir'])): ?>
        <p class="mensaje" id="clave_repetir-error">Error: <?= Plantilla::e($errores['clave_repetir']) ?></p>
      <?php endif; ?>
    </div>

    <p class="resumen">La contraseña nueva debe tener al menos 8 caracteres, con letras y números.</p>

    <div class="acciones">
      <button class="btn btn-principal" type="submit">Cambiar contraseña</button>
    </div>
  </form>
</section>
<?php
Plantilla::fin();
