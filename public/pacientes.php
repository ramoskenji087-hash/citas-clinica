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

// Mensajes que llegan después de guardar o cambiar el estado de un paciente.
$avisos = [
    'registrado'  => 'Paciente registrado correctamente.',
    'modificado'  => 'Datos del paciente actualizados.',
    'activado'    => 'Paciente activado.',
    'desactivado' => 'Paciente desactivado. Su historial se conserva.',
    'noexiste'    => 'El paciente no existe.',
    'error'       => 'No se pudo completar la acción. Intente de nuevo.',
];

$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;

$busqueda = is_string($_GET['buscar'] ?? null) ? trim($_GET['buscar']) : '';
$pacientes = [];
$errorGeneral = null;

try {
    $pacientes = $busqueda === '' ? PacienteService::listar() : PacienteService::buscar($busqueda);
} catch (RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

Plantilla::inicio('Pacientes');
?>
<h1>Pacientes</h1>
<p class="resumen">Registre, busque y modifique los datos de los pacientes.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<div class="acciones">
  <a class="btn btn-principal" href="paciente_form.php">Nuevo paciente</a>
</div>

<form class="buscador" method="get" action="pacientes.php" role="search">
  <div class="campo">
    <label for="buscar">Buscar por CI, nombre o apellido</label>
    <input id="buscar" name="buscar" type="text" maxlength="80" value="<?= Plantilla::e($busqueda) ?>">
  </div>
  <div class="acciones-fila">
    <button class="btn btn-linea" type="submit">Buscar</button>
    <?php if ($busqueda !== ''): ?>
      <a class="btn btn-linea" href="pacientes.php">Ver todos</a>
    <?php endif; ?>
  </div>
</form>

<?php if ($pacientes === []): ?>
  <p>No hay pacientes que mostrar.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($pacientes as $p): ?>
      <?php $activo = (int) $p['activo'] === 1; ?>
      <li>
        <div class="cuerpo">
          <p class="nombre"><?= Plantilla::e($p['apellidos'] . ', ' . $p['nombres']) ?></p>
          <p class="detalle">
            CI <?= Plantilla::e($p['ci']) ?> · <?= Plantilla::e($p['codigo']) ?> · Tel. <?= Plantilla::e($p['telefono']) ?>
          </p>
        </div>

        <div class="acciones-fila">
          <?php if (!$activo): ?>
            <span class="etiqueta etiqueta-cancelada">Inactivo</span>
          <?php endif; ?>

          <a class="btn btn-linea" href="paciente_historial.php?id=<?= (int) $p['id_paciente'] ?>">Historial</a>
          <a class="btn btn-linea" href="paciente_form.php?id=<?= (int) $p['id_paciente'] ?>">Modificar</a>

          <form method="post" action="paciente_estado.php">
            <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
            <input type="hidden" name="id" value="<?= (int) $p['id_paciente'] ?>">
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
