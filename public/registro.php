<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Sesion;
use App\Servicios\CuentaCorreoService;
use App\Servicios\RegistroPacienteService;
use App\Servicios\ReglaException;
use App\Vistas\Plantilla;

Sesion::iniciar();
Conexion::obtener();

$campos = ['nombre_usuario', 'correo', 'ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'direccion'];
$datos = array_fill_keys($campos, '');
$errores = [];
$errorGeneral = null;
$listo = false;
$avisoCorreo = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        foreach ($campos as $campo) {
            $datos[$campo] = is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : '';
        }

        $errores = RegistroPacienteService::validar($datos);

        if ($errores === []) {
            try {
                $resultado = RegistroPacienteService::registrar($datos);
                $listo = true;

                try {
                    CuentaCorreoService::enviarActivacion($resultado['id_usuario']);
                    $avisoCorreo = 'Le enviamos un correo para elegir su contraseña. Revise también la carpeta de spam.';
                } catch (\RuntimeException $e) {
                    error_log('[citas-clinica] Registro de paciente sin correo de activación: ' . $e->getMessage());
                    $avisoCorreo = 'Su cuenta se creó, pero no pudimos enviar el correo ahora. Intente más tarde con "¿Olvidó su contraseña?".';
                }
            } catch (ReglaException $e) {
                $errorGeneral = $e->getMessage();
            } catch (\RuntimeException $e) {
                error_log('[citas-clinica] Registro de paciente: ' . $e->getMessage());
                $errorGeneral = 'No se pudo completar el registro. Intente de nuevo más tarde.';
            }
        }
    }
}

Plantilla::inicio('Crear mi cuenta de paciente', false);
?>
<section class="acceso">
  <h1>Crear mi cuenta de paciente</h1>

  <?php if ($listo): ?>
    <p class="aviso aviso-ok"><?= Plantilla::e($avisoCorreo ?? '') ?></p>
    <p><a href="login.php">Volver a iniciar sesión</a></p>
  <?php else: ?>
    <p>Complete sus datos. Si Recepción ya lo registró, use el mismo correo que tiene en la clínica.</p>

    <?php if ($errorGeneral !== null): ?>
      <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
    <?php endif; ?>

    <?php if ($errores !== []): ?>
      <div class="resumen-errores" role="alert">
        <p>Revise estos datos:</p>
        <ul>
          <?php foreach ($errores as $campo => $mensaje): ?>
            <li><a href="#<?= Plantilla::e($campo) ?>"><?= Plantilla::e($mensaje) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" action="registro.php">
      <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

      <div class="campo" id="nombre_usuario">
        <label for="nombre_usuario_campo">Nombre de usuario</label>
        <input id="nombre_usuario_campo" name="nombre_usuario" type="text" maxlength="30" value="<?= Plantilla::e($datos['nombre_usuario']) ?>" required>
      </div>

      <div class="campo" id="correo">
        <label for="correo_campo">Correo electrónico</label>
        <input id="correo_campo" name="correo" type="email" maxlength="120" value="<?= Plantilla::e($datos['correo']) ?>" required>
      </div>

      <div class="campo" id="ci">
        <label for="ci_campo">Cédula de identidad (CI)</label>
        <input id="ci_campo" name="ci" type="text" maxlength="15" value="<?= Plantilla::e($datos['ci']) ?>" required>
      </div>

      <div class="campos-dobles">
        <div class="campo" id="nombres">
          <label for="nombres_campo">Nombres</label>
          <input id="nombres_campo" name="nombres" type="text" maxlength="80" value="<?= Plantilla::e($datos['nombres']) ?>" required>
        </div>
        <div class="campo" id="apellidos">
          <label for="apellidos_campo">Apellidos</label>
          <input id="apellidos_campo" name="apellidos" type="text" maxlength="80" value="<?= Plantilla::e($datos['apellidos']) ?>" required>
        </div>
        <div class="campo" id="fecha_nacimiento">
          <label for="fecha_nacimiento_campo">Fecha de nacimiento</label>
          <input id="fecha_nacimiento_campo" name="fecha_nacimiento" type="date" value="<?= Plantilla::e($datos['fecha_nacimiento']) ?>" required>
        </div>
        <div class="campo" id="sexo">
          <label for="sexo_campo">Sexo</label>
          <select id="sexo_campo" name="sexo" required>
            <option value="">Seleccione</option>
            <?php foreach (['Masculino', 'Femenino', 'Otro'] as $opcion): ?>
              <option value="<?= $opcion ?>"<?= $datos['sexo'] === $opcion ? ' selected' : '' ?>><?= $opcion ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo" id="telefono">
          <label for="telefono_campo">Teléfono</label>
          <input id="telefono_campo" name="telefono" type="tel" maxlength="20" value="<?= Plantilla::e($datos['telefono']) ?>" required>
        </div>
        <div class="campo" id="direccion">
          <label for="direccion_campo">Dirección (opcional)</label>
          <input id="direccion_campo" name="direccion" type="text" maxlength="200" value="<?= Plantilla::e($datos['direccion']) ?>">
        </div>
      </div>

      <div class="acciones">
        <button class="btn btn-principal" type="submit">Crear cuenta</button>
      </div>
    </form>
    <p><a href="login.php">Ya tengo una cuenta</a></p>
  <?php endif; ?>
</section>
<?php
Plantilla::fin();
