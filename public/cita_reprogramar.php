<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\CitaEstadoService;
use App\Servicios\CitaService;
use App\Servicios\ReglaException;
use App\Validacion\ValidadorCita;
use App\Validacion\ValidadorGestionCita;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);
Conexion::obtener();

$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$origen = $esPost ? $_POST : $_GET;

$id = is_string($origen['id'] ?? null) && ctype_digit($origen['id']) ? (int) $origen['id'] : 0;
$fechaParam = is_string($origen['fecha'] ?? null) ? $origen['fecha'] : '';
$hora = is_string($origen['hora'] ?? null) ? $origen['hora'] : '';

$errores = [];
$errorGeneral = null;

$cita = null;
try {
    $cita = $id > 0 ? CitaEstadoService::obtener($id) : null;
} catch (\RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

if ($cita === null && $errorGeneral === null) {
    header('Location: citas.php?aviso=noexiste');
    exit;
}

// Si no se indica fecha, se propone la fecha actual de la cita.
$fecha = $fechaParam !== '' ? $fechaParam : substr((string) ($cita['inicio'] ?? ''), 0, 10);

// Confirmar la nueva fecha y hora.
if ($esPost && $cita !== null) {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } elseif (!CitaEstadoService::estaAbierta((string) $cita['estado'])) {
        $errorGeneral = 'Solo se reprograman citas Programadas o Confirmadas.';
    } else {
        $errores = ValidadorGestionCita::reprogramacion($fecha, $hora);

        if ($errores === []) {
            try {
                CitaEstadoService::reprogramar($id, $fecha, $hora);
                header('Location: citas.php?aviso=reprogramada');
                exit;
            } catch (ReglaException $e) {
                $errorGeneral = $e->getMessage();
            } catch (\RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

// La fecha se valida también al mostrar el formulario, no solo al confirmar (RN06).
if ($fecha !== '' && !isset($errores['fecha'])) {
    $errorFecha = ValidadorCita::fecha($fecha);
    if ($errorFecha !== null) {
        $errores['fecha'] = $errorFecha;
    }
}

$fechaCorrecta = $fecha !== '' && !isset($errores['fecha']);

// Horas libres del médico para la fecha elegida.
$horas = [];
if ($cita !== null && CitaEstadoService::estaAbierta((string) $cita['estado']) && $fechaCorrecta) {
    try {
        $horas = CitaService::horasLibres((int) $cita['id_medico'], $fecha);
    } catch (\RuntimeException $e) {
        $errorGeneral = $errorGeneral ?? $e->getMessage();
    }
}

Plantilla::inicio('Reprogramar cita');
?>
<p class="fecha"><a href="citas.php">&larr; Volver a citas</a></p>
<h1>Reprogramar cita <?= Plantilla::e($cita['codigo'] ?? '') ?></h1>

<?php if ($errorGeneral !== null || $errores !== []): ?>
  <div class="resumen-errores" role="alert">
    <p>No se pudo reprogramar. Revise estos datos:</p>
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

<?php if ($cita !== null): ?>
  <p class="resumen"><?= Plantilla::e($cita['paciente']) ?> · <?= Plantilla::e($cita['especialidad']) ?> · Dr(a). <?= Plantilla::e($cita['medico']) ?></p>
  <p>Horario actual: <?= Plantilla::e(substr((string) $cita['inicio'], 0, 16)) ?> (estado <?= Plantilla::e($cita['estado']) ?>).</p>

  <?php if (!CitaEstadoService::estaAbierta((string) $cita['estado'])): ?>
    <p>Esta cita no se puede reprogramar porque está en estado <?= Plantilla::e($cita['estado']) ?>.</p>
  <?php else: ?>
    <h2>Paso 1: elija la nueva fecha</h2>
    <form method="get" action="cita_reprogramar.php">
      <input type="hidden" name="id" value="<?= (int) $cita['id_cita'] ?>">
      <div class="campo">
        <label for="fecha">Nueva fecha</label>
        <input id="fecha" name="fecha" type="date" value="<?= Plantilla::e($fecha) ?>">
      </div>
      <div class="acciones">
        <button class="btn btn-linea" type="submit">Ver horas libres</button>
      </div>
    </form>

    <?php if ($fechaCorrecta): ?>
      <h2>Paso 2: elija una hora libre</h2>
      <?php if ($horas === []): ?>
        <p>No hay horas libres para este médico en esa fecha. Pruebe con otro día.</p>
      <?php else: ?>
        <form method="post" action="cita_reprogramar.php">
          <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
          <input type="hidden" name="id" value="<?= (int) $cita['id_cita'] ?>">
          <input type="hidden" name="fecha" value="<?= Plantilla::e($fecha) ?>">
          <div class="acciones">
            <?php foreach ($horas as $h): ?>
              <button class="btn btn-linea" type="submit" name="hora" value="<?= Plantilla::e($h['hora']) ?>">
                <?= Plantilla::e($h['hora']) ?> a <?= Plantilla::e($h['fin']) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php
Plantilla::fin();
