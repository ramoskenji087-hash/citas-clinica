<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\AgendaService;
use App\Servicios\CitaEstadoService;
use App\Validacion\ValidadorGestionCita;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);

// Abrir la conexión fija la zona horaria antes de calcular "hoy" y "ya comenzó".
Conexion::obtener();

$fechaParam = is_string($_GET['fecha'] ?? null) ? $_GET['fecha'] : '';
$fecha = ValidadorGestionCita::fechaValida($fechaParam) ? $fechaParam : date('Y-m-d');

$estadoParam = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : '';
$estado = in_array($estadoParam, AgendaService::ESTADOS, true) ? $estadoParam : '';

$avisos = [
    'confirmada' => 'Cita confirmada.',
    'cancelada' => 'Cita cancelada. Quedó en el historial y su horario está libre.',
    'inasistencia' => 'Inasistencia registrada.',
    'reprogramada' => 'Cita reprogramada. Su estado es Programada.',
    'noexiste' => 'La cita no existe.',
    'error' => 'No se pudo completar la acción. Intente de nuevo.',
];
$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;

$citas = [];
$errorGeneral = null;

try {
    $citas = AgendaService::listar($fecha, 0, $estado);
} catch (\RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

Plantilla::inicio('Citas');
?>
<h1>Citas</h1>
<p class="resumen">Consulte las citas por fecha y estado, y cambie su estado cuando corresponda.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<div class="acciones">
  <a class="btn btn-principal" href="cita_nueva.php">Nueva cita</a>
  <a class="btn btn-linea" href="agenda.php">Agenda de médicos</a>
</div>

<form class="buscador" method="get" action="citas.php" role="search">
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
    <button class="btn btn-linea" type="submit">Ver citas</button>
  </div>
</form>

<?php if ($citas === []): ?>
  <p>No hay citas para este día y estado.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($citas as $c): ?>
      <?php
      $abierta = CitaEstadoService::estaAbierta((string) $c['estado']);
      $comenzo = CitaEstadoService::yaComenzo((string) $c['inicio']);
      $idCita = (int) $c['id_cita'];
      ?>
      <li>
        <div class="cuerpo">
          <p class="nombre"><?= Plantilla::e($c['hora_inicio']) ?> a <?= Plantilla::e($c['hora_fin']) ?> · <?= Plantilla::e($c['paciente']) ?></p>
          <p class="detalle">
            <?= Plantilla::e($c['codigo']) ?> · <?= Plantilla::e($c['especialidad']) ?> · Dr(a). <?= Plantilla::e($c['medico']) ?>
          </p>
          <?php if ((string) $c['motivo'] !== ''): ?>
            <p class="detalle">Motivo: <?= Plantilla::e($c['motivo']) ?></p>
          <?php endif; ?>
          <?php if ($c['motivo_cancelacion'] !== null): ?>
            <p class="detalle">Cancelada: <?= Plantilla::e($c['motivo_cancelacion']) ?></p>
          <?php endif; ?>
        </div>

        <div class="acciones-fila">
          <span class="etiqueta etiqueta-<?= Plantilla::e(strtolower((string) $c['estado'])) ?>"><?= Plantilla::e($c['estado']) ?></span>

          <?php if ($abierta): ?>
            <?php if ($c['estado'] === 'Programada'): ?>
              <form method="post" action="cita_accion.php">
                <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
                <input type="hidden" name="id" value="<?= $idCita ?>">
                <input type="hidden" name="accion" value="confirmar">
                <button class="btn btn-linea" type="submit">Confirmar</button>
              </form>
            <?php endif; ?>

            <a class="btn btn-linea" href="cita_reprogramar.php?id=<?= $idCita ?>&amp;fecha=<?= Plantilla::e(substr((string) $c['inicio'], 0, 10)) ?>">Reprogramar</a>
            <a class="btn btn-linea" href="cita_accion.php?id=<?= $idCita ?>&amp;accion=cancelar">Cancelar</a>

            <?php if ($comenzo): ?>
              <form method="post" action="cita_accion.php">
                <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
                <input type="hidden" name="id" value="<?= $idCita ?>">
                <input type="hidden" name="accion" value="inasistencia">
                <button class="btn btn-linea" type="submit">Inasistencia</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
Plantilla::fin();
