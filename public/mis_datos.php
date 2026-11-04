<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\PortalPacienteService;
use App\Validacion\Validador;
use App\Validacion\ValidadorPortal;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::PACIENTE]);
Conexion::obtener();

$usuario = Sesion::usuarioActual();
$idPaciente = (int) ($usuario['id_paciente'] ?? 0);

if ($idPaciente <= 0) {
    Plantilla::inicio('Mis datos');
    echo '<h1>Mis datos</h1><p class="aviso aviso-error" role="alert">Su usuario no está vinculado a una ficha de paciente. Acuda a Recepción.</p>';
    Plantilla::fin();
    exit;
}

$contactoCampos = ['telefono', 'correo', 'direccion'];
$datos = ['telefono' => '', 'correo' => '', 'direccion' => ''];
$errores = [];
$errorGeneral = null;
$guardado = false;
$paciente = null;

try {
    $paciente = PortalPacienteService::datos($idPaciente);

    if ($paciente === null) {
        header('Location: inicio.php');
        exit;
    }

    foreach ($contactoCampos as $campo) {
        $datos[$campo] = (string) ($paciente[$campo] ?? '');
    }
} catch (\RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $paciente !== null) {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        foreach ($contactoCampos as $campo) {
            $valor = $_POST[$campo] ?? '';
            $datos[$campo] = is_string($valor) ? trim($valor) : '';
        }

        $datos['telefono'] = Validador::limpiarTelefono($datos['telefono']);
        $errores = ValidadorPortal::contacto($datos);

        if ($errores === []) {
            try {
                PortalPacienteService::actualizarContacto($idPaciente, $datos);
                header('Location: mis_datos.php?aviso=guardado');
                exit;
            } catch (\RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

$avisos = [
    'guardado' => 'Sus datos de contacto se guardaron correctamente.',
];
$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;

Plantilla::inicio('Mis datos');
?>
<h1>Mis datos</h1>
<p class="resumen">Puede actualizar su teléfono, su correo y su dirección. Para cambiar otros datos, acuda a Recepción.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null || $errores !== []): ?>
  <div class="resumen-errores" role="alert">
    <p>No se pudieron guardar sus datos. Revise estos campos:</p>
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

<?php if ($paciente !== null): ?>
  <h2>Datos de la ficha</h2>
  <ul class="lista">
    <li>
      <div class="cuerpo">
        <p class="nombre"><?= Plantilla::e($paciente['nombres'] . ' ' . $paciente['apellidos']) ?></p>
        <p class="detalle">
          <?= Plantilla::e($paciente['codigo']) ?> · CI <?= Plantilla::e($paciente['ci']) ?>
          · Nacimiento <?= Plantilla::e(date('d/m/Y', strtotime((string) $paciente['fecha_nacimiento']))) ?>
          · <?= Plantilla::e($paciente['sexo']) ?>
        </p>
      </div>
    </li>
  </ul>

  <h2>Datos de contacto</h2>
  <form method="post" action="mis_datos.php">
    <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

    <div class="campo<?= isset($errores['telefono']) ? ' con-error' : '' ?>">
      <label for="telefono">Teléfono</label>
      <input id="telefono" name="telefono" type="tel" maxlength="20" value="<?= Plantilla::e($datos['telefono']) ?>"
             <?= isset($errores['telefono']) ? 'aria-invalid="true" aria-describedby="telefono-error"' : '' ?>>
      <?php if (isset($errores['telefono'])): ?>
        <p class="mensaje" id="telefono-error">Error: <?= Plantilla::e($errores['telefono']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['correo']) ? ' con-error' : '' ?>">
      <label for="correo">Correo electrónico (opcional)</label>
      <input id="correo" name="correo" type="text" maxlength="120" value="<?= Plantilla::e($datos['correo']) ?>"
             <?= isset($errores['correo']) ? 'aria-invalid="true" aria-describedby="correo-error"' : '' ?>>
      <?php if (isset($errores['correo'])): ?>
        <p class="mensaje" id="correo-error">Error: <?= Plantilla::e($errores['correo']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo ancho<?= isset($errores['direccion']) ? ' con-error' : '' ?>">
      <label for="direccion">Dirección (opcional)</label>
      <input id="direccion" name="direccion" type="text" maxlength="200" value="<?= Plantilla::e($datos['direccion']) ?>"
             <?= isset($errores['direccion']) ? 'aria-invalid="true" aria-describedby="direccion-error"' : '' ?>>
      <?php if (isset($errores['direccion'])): ?>
        <p class="mensaje" id="direccion-error">Error: <?= Plantilla::e($errores['direccion']) ?></p>
      <?php endif; ?>
    </div>

    <div class="acciones">
      <button class="btn btn-principal" type="submit">Guardar cambios</button>
    </div>
  </form>
<?php endif; ?>
<?php
Plantilla::fin();
