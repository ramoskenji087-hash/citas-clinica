<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\CitaEstadoService;
use App\Servicios\ReglaException;
use App\Validacion\ValidadorGestionCita;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);
Conexion::obtener();

$esPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$origen = $esPost ? $_POST : $_GET;

$id = is_string($origen['id'] ?? null) && ctype_digit($origen['id']) ? (int) $origen['id'] : 0;
$accion = is_string($origen['accion'] ?? null) ? $origen['accion'] : '';
$motivo = is_string($origen['motivo'] ?? null) ? trim($origen['motivo']) : '';

$errorGeneral = null;
$errorMotivo = null;

if ($id <= 0) {
    header('Location: citas.php?aviso=noexiste');
    exit;
}

if ($esPost) {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } elseif ($accion === 'cancelar') {
        $errorMotivo = ValidadorGestionCita::cancelacion($motivo);
        if ($errorMotivo === null) {
            try {
                CitaEstadoService::cancelar($id, $motivo);
                header('Location: citas.php?aviso=cancelada');
                exit;
            } catch (ReglaException $e) {
                $errorGeneral = $e->getMessage();
            } catch (\RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    } elseif ($accion === 'confirmar' || $accion === 'inasistencia') {
        try {
            if ($accion === 'confirmar') {
                CitaEstadoService::confirmar($id);
                header('Location: citas.php?aviso=confirmada');
            } else {
                CitaEstadoService::registrarInasistencia($id);
                header('Location: citas.php?aviso=inasistencia');
            }
            exit;
        } catch (ReglaException $e) {
            $errorGeneral = $e->getMessage();
        } catch (\RuntimeException $e) {
            $errorGeneral = $e->getMessage();
        }
    } else {
        $errorGeneral = 'La acción solicitada no existe.';
    }
}

// Pantalla del formulario de cancelación (GET, o POST con error de motivo).
$cita = null;
try {
    $cita = CitaEstadoService::obtener($id);
} catch (\RuntimeException $e) {
    $errorGeneral = $errorGeneral ?? $e->getMessage();
}

if ($cita === null) {
    header('Location: citas.php?aviso=noexiste');
    exit;
}

Plantilla::inicio('Cancelar cita');
?>
<p class="fecha"><a href="citas.php">&larr; Volver a citas</a></p>
<h1>Cancelar cita <?= Plantilla::e($cita['codigo']) ?></h1>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<?php if ($accion === 'cancelar' || $accion === '') : ?>
  <?php if (!CitaEstadoService::estaAbierta((string) $cita['estado'])): ?>
    <p>Esta cita está en estado <?= Plantilla::e($cita['estado']) ?> y no se puede cancelar.</p>
  <?php else: ?>
    <p class="resumen"><?= Plantilla::e($cita['paciente']) ?> · Dr(a). <?= Plantilla::e($cita['medico']) ?></p>
    <p>La cita queda en el historial con el motivo que escriba, y su horario se libera.</p>

    <form method="post" action="cita_accion.php">
      <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
      <input type="hidden" name="id" value="<?= (int) $cita['id_cita'] ?>">
      <input type="hidden" name="accion" value="cancelar">

      <div class="campo ancho<?= $errorMotivo !== null ? ' con-error' : '' ?>">
        <label for="motivo">Motivo de la cancelación</label>
        <input id="motivo" name="motivo" type="text" maxlength="255" value="<?= Plantilla::e($motivo) ?>"
               <?= $errorMotivo !== null ? 'aria-invalid="true" aria-describedby="motivo-error"' : '' ?>>
        <?php if ($errorMotivo !== null): ?>
          <p class="mensaje" id="motivo-error">Error: <?= Plantilla::e($errorMotivo) ?></p>
        <?php endif; ?>
      </div>

      <div class="acciones">
        <button class="btn btn-principal" type="submit">Cancelar la cita</button>
        <a class="btn btn-linea" href="citas.php">Volver sin cancelar</a>
      </div>
    </form>
  <?php endif; ?>
<?php endif; ?>
<?php
Plantilla::fin();
