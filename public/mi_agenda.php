<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\AgendaService;
use App\Servicios\AdministracionUsuariosService;
use App\Servicios\CitaEstadoService;
use App\Servicios\ReglaException;
use App\Validacion\ValidadorGestionCita;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::MEDICO]);
Conexion::obtener();

$usuario = Sesion::usuarioActual();
$idMedico = (int) ($usuario['id_medico'] ?? 0);

// Un usuario de rol Médico debe estar vinculado a su ficha de médico (RN12).
if ($idMedico <= 0) {
    Plantilla::inicio('Mi agenda');
    echo '<h1>Mi agenda</h1><p class="aviso aviso-error" role="alert">Su usuario no está vinculado a una ficha de médico. Pida al administrador que lo vincule.</p>';
    Plantilla::fin();
    exit;
}

$errorGeneral = null;

// Marcar una cita como atendida (POST con token CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;
    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } elseif ($accion === 'atendida' && $id > 0) {
        try {
            CitaEstadoService::marcarAtendida($id, $idMedico, (int) $usuario['id']);
            header('Location: mi_agenda.php?aviso=atendida');
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

$fechaParam = is_string($_GET['fecha'] ?? null) ? $_GET['fecha'] : '';
$fecha = ValidadorGestionCita::fechaValida($fechaParam) ? $fechaParam : date('Y-m-d');

$estadoParam = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : '';
$estado = in_array($estadoParam, AgendaService::ESTADOS, true) ? $estadoParam : '';

// ¿Tiene permiso para marcar citas como atendidas? Se consulta en cada carga (RN12).
$puedeMarcar = false;
$puedeVerMotivo = false;
$citas = [];

try {
    $puedeMarcar = CitaEstadoService::puedeMarcarAtendida((int) $usuario['id']);
    $puedeVerMotivo = AdministracionUsuariosService::puedeVerMotivo((int) $usuario['id']);
    $citas = AgendaService::listar($fecha, $idMedico, $estado);
} catch (\RuntimeException $e) {
    $errorGeneral = $errorGeneral ?? $e->getMessage();
}

$avisos = [
    'atendida' => 'Cita marcada como Atendida. Su registro se conserva.',
];
$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;

Plantilla::inicio('Mi agenda');
?>
<h1>Mi agenda</h1>
<p class="resumen">Sus citas por fecha y estado.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<?php if (!$puedeMarcar): ?>
  <p class="aviso aviso-error">Su usuario no tiene permiso para marcar citas como atendidas. Puede consultar su agenda.</p>
<?php endif; ?>

<form class="buscador" method="get" action="mi_agenda.php" role="search">
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
  <p>No tiene citas para esta fecha y estado.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($citas as $c): ?>
      <?php
      $abierta = CitaEstadoService::estaAbierta((string) $c['estado']);
      $comenzo = CitaEstadoService::yaComenzo((string) $c['inicio']);
      ?>
      <li>
        <div class="cuerpo">
          <p class="nombre"><?= Plantilla::e($c['hora_inicio']) ?> a <?= Plantilla::e($c['hora_fin']) ?> · <?= Plantilla::e($c['paciente']) ?></p>
          <p class="detalle"><?= Plantilla::e($c['codigo']) ?> · <?= Plantilla::e($c['especialidad']) ?></p>
          <p class="detalle">CI <?= Plantilla::e($c['paciente_ci']) ?> · Tel. <?= Plantilla::e($c['paciente_telefono']) ?></p>
          <?php if ($puedeVerMotivo && (string) $c['motivo'] !== ''): ?>
            <p class="detalle">Motivo: <?= Plantilla::e($c['motivo']) ?></p>
          <?php endif; ?>
        </div>

        <div class="acciones-fila">
          <span class="etiqueta etiqueta-<?= Plantilla::e(strtolower((string) $c['estado'])) ?>"><?= Plantilla::e($c['estado']) ?></span>

          <?php if ($abierta && $comenzo && $puedeMarcar): ?>
            <form method="post" action="mi_agenda.php">
              <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
              <input type="hidden" name="id" value="<?= (int) $c['id_cita'] ?>">
              <input type="hidden" name="accion" value="atendida">
              <button class="btn btn-principal" type="submit">Marcar atendida</button>
            </form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
Plantilla::fin();
