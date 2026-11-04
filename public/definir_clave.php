<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Servicios\CuentaCorreoService;
use App\Servicios\ReglaException;
use App\Vistas\Plantilla;

// Abrir la conexión fija la zona horaria antes de comparar los vencimientos.
Conexion::obtener();

// No requiere sesión: el propio enlace del correo es la prueba de identidad.
$token = is_string($_REQUEST['token'] ?? null) ? trim($_REQUEST['token']) : '';
$error = null;
$listo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clave = is_string($_POST['clave'] ?? null) ? $_POST['clave'] : '';
    $confirmar = is_string($_POST['confirmar'] ?? null) ? $_POST['confirmar'] : '';

    if ($clave !== $confirmar) {
        $error = 'Las dos contraseñas no coinciden.';
    } else {
        try {
            CuentaCorreoService::definirClave($token, $clave);
            $listo = true;
        } catch (ReglaException $e) {
            $error = $e->getMessage();
        }
    }
}

$cuenta = $listo ? null : CuentaCorreoService::validarToken($token);
$titulo = $listo ? 'Contraseña guardada' : 'Definir contraseña';

Plantilla::inicio($titulo, false);
?>
<section class="acceso">
  <h1><?= Plantilla::e($titulo) ?></h1>

  <?php if ($listo): ?>
    <p class="aviso aviso-ok">Su contraseña quedó guardada. Ya puede entrar al sistema.</p>
    <p><a class="btn btn-principal" href="login.php">Ir a iniciar sesión</a></p>

  <?php elseif ($cuenta === null): ?>
    <p class="aviso aviso-error" role="alert">El enlace no es válido, ya se usó o venció. Solicite uno nuevo.</p>
    <p><a href="recuperar.php">Solicitar un enlace nuevo</a></p>

  <?php else: ?>
    <p>Hola, <?= Plantilla::e($cuenta['nombres']) ?>. Elija su contraseña. Debe tener al menos 8 caracteres, con letras y números.</p>

    <?php if ($error !== null): ?>
      <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="definir_clave.php">
      <input type="hidden" name="token" value="<?= Plantilla::e($token) ?>">

      <div class="campo">
        <label for="clave">Nueva contraseña</label>
        <input id="clave" name="clave" type="password" autocomplete="new-password" required>
      </div>

      <div class="campo">
        <label for="confirmar">Repita la contraseña</label>
        <input id="confirmar" name="confirmar" type="password" autocomplete="new-password" required>
      </div>

      <button class="btn btn-principal" type="submit">Guardar contraseña</button>
    </form>
  <?php endif; ?>
</section>
<?php
Plantilla::fin();
