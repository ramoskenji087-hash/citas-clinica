<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\PacienteService;
use App\Vistas\Plantilla;
use RuntimeException;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);

$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : 0;
$paciente = null;
$citas = [];
$errorGeneral = null;

try {
    $paciente = $id > 0 ? PacienteService::obtener($id) : null;

    if ($paciente === null) {
        header('Location: pacientes.php?aviso=noexiste');
        exit;
    }

    $citas = PacienteService::historial($id);
} catch (RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

Plantilla::inicio('Historial del paciente');
?>
<p class="fecha"><a href="pacientes.php">&larr; Volver a pacientes</a></p>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php elseif ($paciente !== null): ?>
  <h1><?= Plantilla::e($paciente['nombres'] . ' ' . $paciente['apellidos']) ?></h1>
  <p class="resumen">CI <?= Plantilla::e($paciente['ci']) ?> · <?= Plantilla::e($paciente['codigo']) ?></p>

  <h2>Historial de citas</h2>

  <?php if ($citas === []): ?>
    <p>Este paciente no tiene citas registradas.</p>
  <?php else: ?>
    <ul class="lista">
      <?php foreach ($citas as $cita): ?>
        <li>
          <div class="cuerpo">
            <p class="nombre"><?= Plantilla::e(date('d/m/Y H:i', strtotime($cita['inicio']))) ?></p>
            <p class="detalle">
              <?= Plantilla::e($cita['especialidad']) ?> · <?= Plantilla::e($cita['medico']) ?> · <?= Plantilla::e($cita['codigo']) ?>
            </p>
            <p class="detalle">Registrada el <?= Plantilla::e(date('d/m/Y', strtotime($cita['fecha_creacion']))) ?></p>
            <?php if ($cita['motivo_cancelacion'] !== null): ?>
              <p class="detalle">Motivo de cancelación: <?= Plantilla::e($cita['motivo_cancelacion']) ?></p>
            <?php endif; ?>
          </div>
          <span class="etiqueta etiqueta-<?= Plantilla::e(strtolower($cita['estado'])) ?>"><?= Plantilla::e($cita['estado']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
<?php
Plantilla::fin();
