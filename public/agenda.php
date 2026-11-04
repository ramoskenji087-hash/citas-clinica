<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\AgendaService;
use App\Servicios\CitaService;
use App\Validacion\ValidadorGestionCita;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);
Conexion::obtener();

$fechaParam = is_string($_GET['fecha'] ?? null) ? $_GET['fecha'] : '';
$fecha = ValidadorGestionCita::fechaValida($fechaParam) ? $fechaParam : date('Y-m-d');

$medicoParam = is_string($_GET['medico'] ?? null) ? $_GET['medico'] : '';
$idMedico = ctype_digit($medicoParam) ? (int) $medicoParam : 0;

$estadoParam = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : '';
$estado = in_array($estadoParam, AgendaService::ESTADOS, true) ? $estadoParam : '';

$medicos = [];
$citas = [];
$errorGeneral = null;

try {
    $medicos = CitaService::medicosActivos();
    $citas = AgendaService::listar($fecha, $idMedico, $estado);
} catch (\RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

Plantilla::inicio('Agenda de médicos');
?>
<h1>Agenda de médicos</h1>
<p class="resumen">Revise la agenda de cada médico por fecha y estado. Para cambiar una cita, use la página Citas.</p>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<form class="buscador" method="get" action="agenda.php" role="search">
  <div class="campo">
    <label for="medico">Médico</label>
    <select id="medico" name="medico">
      <option value="">Todos los médicos</option>
      <?php foreach ($medicos as $m): ?>
        <option value="<?= (int) $m['id_medico'] ?>"<?= (int) $m['id_medico'] === $idMedico ? ' selected' : '' ?>>
          Dr(a). <?= Plantilla::e($m['nombres'] . ' ' . $m['apellidos']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="campo">
    <label for="fecha">Fecha</label>
    <input id="fecha" name="fecha" type="date" value="<?= Plantilla::e($fecha) ?>">
  </div>
  <div class="campo">
    <label for="estado">Estado</label>
    <select id="estado" name="estado">
      <option value="">Todos los estados</option>
      <?php foreach (AgendaService::ESTADOS as $opcion): ?>
        <option value="<?= Plantilla::e($opcion) ?>"<?= $opcion === $estado ? ' selected' : '' ?>>
          <?= Plantilla::e($opcion) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="acciones-fila">
    <button class="btn btn-linea" type="submit">Ver agenda</button>
  </div>
</form>

<?php if ($citas === []): ?>
  <p>No hay citas para esta agenda.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($citas as $c): ?>
      <li>
        <div class="cuerpo">
          <p class="nombre"><?= Plantilla::e($c['hora_inicio']) ?> a <?= Plantilla::e($c['hora_fin']) ?> · <?= Plantilla::e($c['paciente']) ?></p>
          <p class="detalle">
            <?= Plantilla::e($c['codigo']) ?> · <?= Plantilla::e($c['especialidad']) ?> · Dr(a). <?= Plantilla::e($c['medico']) ?>
          </p>
          <?php if ((string) $c['motivo'] !== ''): ?>
            <p class="detalle">Motivo: <?= Plantilla::e($c['motivo']) ?></p>
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
