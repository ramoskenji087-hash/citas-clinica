<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\PacienteService;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::PACIENTE]);
Conexion::obtener(); // fija la zona horaria antes de mostrar las fechas

// El paciente se toma de la sesión, nunca de la dirección (así no puede ver las citas de otro).
$usuario = Sesion::usuarioActual();
$idPaciente = (int) ($usuario['id_paciente'] ?? 0);

if ($idPaciente <= 0) {
    Plantilla::inicio('Mis citas');
    echo '<h1>Mis citas</h1><p class="aviso aviso-error" role="alert">Su usuario no está vinculado a una ficha de paciente. Acuda a Recepción.</p>';
    Plantilla::fin();
    exit;
}

$citas = [];
$errorGeneral = null;

try {
    $citas = PacienteService::historial($idPaciente);
} catch (\RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

Plantilla::inicio('Mis citas');
?>
<h1>Mis citas</h1>
<p class="resumen">Aquí puede ver todas sus citas, incluidas las canceladas.</p>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<?php if ($citas === []): ?>
  <p>Todavía no tiene citas registradas. Para programar una, acuda a Recepción.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($citas as $c): ?>
      <li>
        <div class="cuerpo">
          <p class="nombre"><?= Plantilla::e(date('d/m/Y H:i', strtotime((string) $c['inicio']))) ?></p>
          <p class="detalle">
            <?= Plantilla::e($c['codigo']) ?> · <?= Plantilla::e($c['especialidad']) ?> · Dr(a). <?= Plantilla::e($c['medico']) ?> · <?= Plantilla::e($c['modalidad']) ?>
          </p>
          <p class="detalle">Registrada el <?= Plantilla::e(date('d/m/Y', strtotime((string) $c['fecha_creacion']))) ?></p>
          <?php if ($c['motivo_cancelacion'] !== null): ?>
            <p class="detalle">Motivo de cancelación: <?= Plantilla::e($c['motivo_cancelacion']) ?></p>
          <?php endif; ?>
        </div>
        <div class="acciones-fila">
          <span class="etiqueta etiqueta-<?= Plantilla::e(strtolower((string) $c['estado'])) ?>"><?= Plantilla::e($c['estado']) ?></span>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
Plantilla::fin();
