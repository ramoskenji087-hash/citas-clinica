<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\MedicoService;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::ADMINISTRADOR]);

// Activar o desactivar desde la lista: solo con POST y token CSRF válido.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = 'error';
    $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;
    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

    if (Sesion::verificarCsrf($_POST['csrf'] ?? null) && $id > 0 && in_array($accion, ['activar', 'desactivar'], true)) {
        try {
            MedicoService::cambiarActivo($id, $accion === 'activar');
            $resultado = $accion === 'activar' ? 'activado' : 'desactivado';
        } catch (RuntimeException $e) {
            $resultado = 'error';
        }
    }

    header('Location: medicos.php?aviso=' . $resultado);
    exit;
}

$avisos = [
    'registrado' => 'Médico registrado correctamente.',
    'modificado' => 'Datos del médico actualizados.',
    'activado' => 'Médico activado.',
    'desactivado' => 'Médico desactivado. Su historial de citas se conserva.',
    'noexiste' => 'El médico no existe.',
    'error' => 'No se pudo completar la acción. Intente de nuevo.',
];
$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;

$busqueda = is_string($_GET['buscar'] ?? null) ? trim($_GET['buscar']) : '';
$medicos = [];
$errorGeneral = null;

try {
    $medicos = MedicoService::listar($busqueda);
} catch (RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

Plantilla::inicio('Médicos y horarios');
?>
<h1>Médicos y horarios</h1>
<p class="resumen">Registre médicos, asigne sus especialidades y defina sus horarios de atención.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<div class="acciones">
  <a class="btn btn-principal" href="medico_form.php">Nuevo médico</a>
  <a class="btn btn-linea" href="especialidades.php">Especialidades</a>
</div>

<form class="buscador" method="get" action="medicos.php" role="search">
  <div class="campo">
    <label for="buscar">Buscar por nombre o especialidad</label>
    <input id="buscar" name="buscar" type="text" maxlength="80" value="<?= Plantilla::e($busqueda) ?>">
  </div>
  <div class="acciones-fila">
    <button class="btn btn-linea" type="submit">Buscar</button>
    <?php if ($busqueda !== ''): ?>
      <a class="btn btn-linea" href="medicos.php">Ver todos</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($medicos === []): ?>
  <p>No hay médicos que mostrar.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($medicos as $m): ?>
      <?php $activo = (int) $m['activo'] === 1; ?>
      <li>
        <div class="cuerpo">
          <p class="nombre">Dr(a). <?= Plantilla::e($m['nombres'] . ' ' . $m['apellidos']) ?></p>
          <p class="detalle">
            <?= Plantilla::e($m['codigo']) ?> · Registro <?= Plantilla::e($m['registro_profesional']) ?>
            · <?= Plantilla::e($m['especialidades']) ?>
          </p>
        </div>

        <div class="acciones-fila">
          <?php if (!$activo): ?>
            <span class="etiqueta etiqueta-cancelada">Inactivo</span>
          <?php endif; ?>

          <a class="btn btn-linea" href="medico_horarios.php?id=<?= (int) $m['id_medico'] ?>">Horarios</a>
          <a class="btn btn-linea" href="medico_form.php?id=<?= (int) $m['id_medico'] ?>">Modificar</a>

          <form method="post" action="medicos.php">
            <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
            <input type="hidden" name="id" value="<?= (int) $m['id_medico'] ?>">
            <input type="hidden" name="accion" value="<?= $activo ? 'desactivar' : 'activar' ?>">
            <button class="btn btn-linea" type="submit"><?= $activo ? 'Desactivar' : 'Activar' ?></button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
Plantilla::fin();
