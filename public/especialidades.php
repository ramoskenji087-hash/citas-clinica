<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\EspecialidadService;
use App\Servicios\ReglaException;
use App\Validacion\ValidadorAgenda;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::ADMINISTRADOR]);

$datos = ['nombre' => '', 'descripcion' => ''];
$idEditar = null;
$errores = [];
$errorGeneral = null;

// Lo que llega por POST: guardar, activar o desactivar. Siempre con token CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
        $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;

        if (in_array($accion, ['activar', 'desactivar'], true) && $id > 0) {
            try {
                EspecialidadService::cambiarActivo($id, $accion === 'activar');
                header('Location: especialidades.php?aviso=' . ($accion === 'activar' ? 'activada' : 'desactivada'));
                exit;
            } catch (RuntimeException $e) {
                header('Location: especialidades.php?aviso=error');
                exit;
            }
        }

        if ($accion === 'guardar') {
            $idEditar = $id > 0 ? $id : null;
            $datos['nombre'] = is_string($_POST['nombre'] ?? null) ? trim($_POST['nombre']) : '';
            $datos['descripcion'] = is_string($_POST['descripcion'] ?? null) ? trim($_POST['descripcion']) : '';

            $errores = ValidadorAgenda::especialidad($datos);

            if ($errores === []) {
                try {
                    EspecialidadService::guardar($idEditar, $datos);
                    header('Location: especialidades.php?aviso=guardada');
                    exit;
                } catch (ReglaException $e) {
                    $errorGeneral = $e->getMessage();
                } catch (RuntimeException $e) {
                    $errorGeneral = $e->getMessage();
                }
            }
        }
    }
}

// Con ?editar= se carga una especialidad en el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_string($_GET['editar'] ?? null) && ctype_digit($_GET['editar'])) {
    try {
        $existente = EspecialidadService::obtener((int) $_GET['editar']);
        if ($existente !== null) {
            $idEditar = (int) $existente['id_especialidad'];
            $datos['nombre'] = (string) $existente['nombre'];
            $datos['descripcion'] = (string) ($existente['descripcion'] ?? '');
        }
    } catch (RuntimeException $e) {
        $errorGeneral = $e->getMessage();
    }
}

$avisos = [
    'guardada' => 'Especialidad guardada correctamente.',
    'activada' => 'Especialidad activada.',
    'desactivada' => 'Especialidad desactivada. Ya no se podrá asignar a médicos nuevos.',
    'error' => 'No se pudo completar la acción. Intente de nuevo.',
];
$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;

$especialidades = [];
try {
    $especialidades = EspecialidadService::listar();
} catch (RuntimeException $e) {
    $errorGeneral = $errorGeneral ?? $e->getMessage();
}

$titulo = $idEditar === null ? 'Nueva especialidad' : 'Modificar especialidad';

Plantilla::inicio('Especialidades');
?>
<h1>Especialidades</h1>
<p class="resumen">Las especialidades que se pueden asignar a los médicos de la clínica.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null || $errores !== []): ?>
  <div class="resumen-errores" role="alert">
    <p>No se pudo guardar. Revise estos datos:</p>
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

<h2><?= Plantilla::e($titulo) ?></h2>
<form method="post" action="especialidades.php">
  <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
  <input type="hidden" name="accion" value="guardar">
  <?php if ($idEditar !== null): ?>
    <input type="hidden" name="id" value="<?= $idEditar ?>">
  <?php endif; ?>

  <div class="campos-dobles">
    <div class="campo<?= isset($errores['nombre']) ? ' con-error' : '' ?>">
      <label for="nombre">Nombre</label>
      <input id="nombre" name="nombre" type="text" maxlength="80" value="<?= Plantilla::e($datos['nombre']) ?>"
             <?= isset($errores['nombre']) ? 'aria-invalid="true" aria-describedby="nombre-error"' : '' ?>>
      <?php if (isset($errores['nombre'])): ?>
        <p class="mensaje" id="nombre-error">Error: <?= Plantilla::e($errores['nombre']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['descripcion']) ? ' con-error' : '' ?>">
      <label for="descripcion">Descripción (opcional)</label>
      <input id="descripcion" name="descripcion" type="text" maxlength="255" value="<?= Plantilla::e($datos['descripcion']) ?>">
      <?php if (isset($errores['descripcion'])): ?>
        <p class="mensaje" id="descripcion-error">Error: <?= Plantilla::e($errores['descripcion']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="acciones">
    <button class="btn btn-principal" type="submit">Guardar</button>
    <?php if ($idEditar !== null): ?>
      <a class="btn btn-linea" href="especialidades.php">Cancelar</a>
    <?php endif; ?>
  </div>
</form>

<h2>Lista de especialidades</h2>
<?php if ($especialidades === []): ?>
  <p>Todavía no hay especialidades registradas.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($especialidades as $e): ?>
      <?php $activa = (int) $e['activo'] === 1; ?>
      <li>
        <div class="cuerpo">
          <p class="nombre"><?= Plantilla::e($e['nombre']) ?></p>
          <?php if ((string) $e['descripcion'] !== ''): ?>
            <p class="detalle"><?= Plantilla::e($e['descripcion']) ?></p>
          <?php endif; ?>
        </div>

        <div class="acciones-fila">
          <?php if (!$activa): ?>
            <span class="etiqueta etiqueta-cancelada">Inactiva</span>
          <?php endif; ?>

          <a class="btn btn-linea" href="especialidades.php?editar=<?= (int) $e['id_especialidad'] ?>">Modificar</a>

          <form method="post" action="especialidades.php">
            <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
            <input type="hidden" name="id" value="<?= (int) $e['id_especialidad'] ?>">
            <input type="hidden" name="accion" value="<?= $activa ? 'desactivar' : 'activar' ?>">
            <button class="btn btn-linea" type="submit"><?= $activa ? 'Desactivar' : 'Activar' ?></button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
Plantilla::fin();
