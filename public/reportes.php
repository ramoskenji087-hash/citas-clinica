<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\AgendaService;
use App\Servicios\CitaService;
use App\Servicios\EspecialidadService;
use App\Servicios\ReporteService;
use App\Validacion\ValidadorReporte;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::ADMINISTRADOR]);
Conexion::obtener(); // fija la zona horaria antes de calcular las fechas por defecto

$texto = static fn (string $clave): string => is_string($_GET[$clave] ?? null) ? trim($_GET[$clave]) : '';
$numero = static fn (string $clave): int => ctype_digit($texto($clave)) ? (int) $texto($clave) : 0;

// Por defecto, el mes en curso hasta hoy.
$filtros = [
    'desde' => $texto('desde') !== '' ? $texto('desde') : date('Y-m-01'),
    'hasta' => $texto('hasta') !== '' ? $texto('hasta') : date('Y-m-d'),
    'medico' => $numero('medico'),
    'especialidad' => $numero('especialidad'),
    'estado' => $texto('estado'),
];

$errores = ValidadorReporte::filtros($filtros);

// Descarga en CSV: separador ";" (Excel en español) y BOM para que las tildes se vean bien.
if ($texto('formato') === 'csv' && $errores === []) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="citas_' . $filtros['desde'] . '_' . $filtros['hasta'] . '.csv"');

    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    fputcsv($salida, ['Código', 'Fecha', 'Hora inicio', 'Hora fin', 'Estado', 'Paciente', 'Médico',
                      'Especialidad', 'Motivo', 'Motivo de cancelación'], ';');

    foreach (ReporteService::detalle($filtros) as $fila) {
        fputcsv($salida, [
            $fila['codigo'], $fila['fecha'], $fila['hora_inicio'], $fila['hora_fin'], $fila['estado'],
            $fila['paciente'], $fila['medico'], $fila['especialidad'], $fila['motivo'], $fila['motivo_cancelacion'],
        ], ';');
    }

    fclose($salida);
    exit;
}

$medicos = [];
$especialidades = [];
$totales = [];
$porMedico = [];
$porEspecialidad = [];
$pacientes = [];
$detalle = [];
$errorGeneral = null;

if ($errores === []) {
    try {
        $medicos = CitaService::medicosActivos();
        $especialidades = EspecialidadService::listar();
        $totales = ReporteService::totalesPorEstado($filtros);
        $porMedico = ReporteService::citasPorMedico($filtros);
        $porEspecialidad = ReporteService::citasPorEspecialidad($filtros);
        $pacientes = ReporteService::pacientesRegistrados($filtros['desde'], $filtros['hasta']);

        // Un solo día: se muestra la agenda diaria completa. Un periodo: solo las primeras 200 citas.
        $esUnDia = $filtros['desde'] === $filtros['hasta'];
        $detalle = ReporteService::detalle($filtros, $esUnDia ? 0 : 200);
    } catch (\RuntimeException $e) {
        $errorGeneral = $e->getMessage();
    }
}

$esUnDia = $filtros['desde'] === $filtros['hasta'];

// Agenda diaria: las citas agrupadas por médico.
$agendaPorMedico = [];
foreach ($detalle as $fila) {
    $agendaPorMedico[$fila['medico']][] = $fila;
}

Plantilla::inicio('Reportes');
?>
<h1>Reportes</h1>
<p class="resumen">Consulte las citas y los pacientes de un periodo. Puede descargar el detalle en CSV.</p>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<?php if ($errores !== []): ?>
  <div class="resumen-errores" role="alert">
    <p>Revise los filtros del reporte:</p>
    <ul>
      <?php foreach ($errores as $campo => $mensaje): ?>
        <li><a href="#<?= Plantilla::e($campo) ?>"><?= Plantilla::e($mensaje) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form class="buscador" method="get" action="reportes.php" role="search">
  <div class="campo">
    <label for="desde">Desde</label>
    <input id="desde" name="desde" type="date" value="<?= Plantilla::e($filtros['desde']) ?>">
  </div>
  <div class="campo">
    <label for="hasta">Hasta</label>
    <input id="hasta" name="hasta" type="date" value="<?= Plantilla::e($filtros['hasta']) ?>">
  </div>
  <div class="campo">
    <label for="medico">Médico</label>
    <select id="medico" name="medico">
      <option value="">Todos los médicos</option>
      <?php foreach ($medicos as $m): ?>
        <option value="<?= (int) $m['id_medico'] ?>"<?= (int) $m['id_medico'] === $filtros['medico'] ? ' selected' : '' ?>>
          Dr(a). <?= Plantilla::e($m['nombres'] . ' ' . $m['apellidos']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="campo">
    <label for="especialidad">Especialidad</label>
    <select id="especialidad" name="especialidad">
      <option value="">Todas las especialidades</option>
      <?php foreach ($especialidades as $e): ?>
        <option value="<?= (int) $e['id_especialidad'] ?>"<?= (int) $e['id_especialidad'] === $filtros['especialidad'] ? ' selected' : '' ?>>
          <?= Plantilla::e($e['nombre']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="campo">
    <label for="estado">Estado</label>
    <select id="estado" name="estado">
      <option value="">Todos los estados</option>
      <?php foreach (AgendaService::ESTADOS as $opcion): ?>
        <option value="<?= Plantilla::e($opcion) ?>"<?= $opcion === $filtros['estado'] ? ' selected' : '' ?>>
          <?= Plantilla::e($opcion) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="acciones-fila">
    <button class="btn btn-linea" type="submit">Generar reporte</button>
    <a class="btn btn-principal" href="reportes.php?<?= Plantilla::e(http_build_query(array_merge($filtros, ['formato' => 'csv']))) ?>">Descargar CSV</a>
  </div>
</form>

<?php if ($errores === []): ?>
  <h2>Totales por estado</h2>
  <?php if ($totales === []): ?>
    <p>No hay citas en este periodo.</p>
  <?php else: ?>
    <ul class="lista">
      <?php foreach ($totales as $t): ?>
        <li>
          <div class="cuerpo"><p class="nombre"><?= Plantilla::e($t['estado']) ?></p></div>
          <div class="acciones-fila"><span class="etiqueta etiqueta-<?= Plantilla::e(strtolower((string) $t['estado'])) ?>"><?= (int) $t['total'] ?></span></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>Citas por médico</h2>
  <?php if ($porMedico === []): ?>
    <p>Sin datos para este periodo.</p>
  <?php else: ?>
    <ul class="lista">
      <?php foreach ($porMedico as $r): ?>
        <li>
          <div class="cuerpo">
            <p class="nombre">Dr(a). <?= Plantilla::e($r['medico']) ?></p>
            <p class="detalle">Atendidas: <?= (int) $r['atendidas'] ?> · Canceladas: <?= (int) $r['canceladas'] ?> · Inasistencias: <?= (int) $r['inasistencias'] ?></p>
          </div>
          <div class="acciones-fila"><span class="etiqueta etiqueta-programada">Total <?= (int) $r['total'] ?></span></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>Citas por especialidad</h2>
  <?php if ($porEspecialidad === []): ?>
    <p>Sin datos para este periodo.</p>
  <?php else: ?>
    <ul class="lista">
      <?php foreach ($porEspecialidad as $r): ?>
        <li>
          <div class="cuerpo">
            <p class="nombre"><?= Plantilla::e($r['especialidad']) ?></p>
            <p class="detalle">Atendidas: <?= (int) $r['atendidas'] ?> · Canceladas: <?= (int) $r['canceladas'] ?> · Inasistencias: <?= (int) $r['inasistencias'] ?></p>
          </div>
          <div class="acciones-fila"><span class="etiqueta etiqueta-programada">Total <?= (int) $r['total'] ?></span></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>Pacientes registrados en el periodo</h2>
  <p>Total: <?= count($pacientes) ?></p>
  <?php if ($pacientes !== []): ?>
    <ul class="lista">
      <?php foreach (array_slice($pacientes, 0, 100) as $p): ?>
        <li>
          <div class="cuerpo">
            <p class="nombre"><?= Plantilla::e($p['apellidos'] . ', ' . $p['nombres']) ?></p>
            <p class="detalle"><?= Plantilla::e($p['codigo']) ?> · CI <?= Plantilla::e($p['ci']) ?> · Registrado el <?= Plantilla::e(date('d/m/Y', strtotime((string) $p['fecha']))) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($esUnDia): ?>
    <h2>Agenda diaria por médico (<?= Plantilla::e(date('d/m/Y', strtotime($filtros['desde']))) ?>)</h2>
    <?php if ($agendaPorMedico === []): ?>
      <p>No hay citas programadas para este día.</p>
    <?php else: ?>
      <?php foreach ($agendaPorMedico as $nombreMedico => $citas): ?>
        <h3>Dr(a). <?= Plantilla::e((string) $nombreMedico) ?></h3>
        <ul class="lista">
          <?php foreach ($citas as $c): ?>
            <li>
              <div class="cuerpo">
                <p class="nombre"><?= Plantilla::e($c['hora_inicio']) ?> a <?= Plantilla::e($c['hora_fin']) ?> · <?= Plantilla::e($c['paciente']) ?></p>
                <p class="detalle"><?= Plantilla::e($c['codigo']) ?> · <?= Plantilla::e($c['especialidad']) ?></p>
              </div>
              <div class="acciones-fila"><span class="etiqueta etiqueta-<?= Plantilla::e(strtolower((string) $c['estado'])) ?>"><?= Plantilla::e($c['estado']) ?></span></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php else: ?>
    <h2>Detalle de citas del periodo</h2>
    <?php if ($detalle === []): ?>
      <p>No hay citas en este periodo.</p>
    <?php else: ?>
      <p class="resumen">Se muestran hasta 200 citas. Para el listado completo, descargue el CSV.</p>
      <ul class="lista">
        <?php foreach ($detalle as $c): ?>
          <li>
            <div class="cuerpo">
              <p class="nombre"><?= Plantilla::e(date('d/m/Y', strtotime((string) $c['fecha']))) ?> · <?= Plantilla::e($c['hora_inicio']) ?> · <?= Plantilla::e($c['paciente']) ?></p>
              <p class="detalle"><?= Plantilla::e($c['codigo']) ?> · <?= Plantilla::e($c['especialidad']) ?> · Dr(a). <?= Plantilla::e($c['medico']) ?></p>
            </div>
            <div class="acciones-fila"><span class="etiqueta etiqueta-<?= Plantilla::e(strtolower((string) $c['estado'])) ?>"><?= Plantilla::e($c['estado']) ?></span></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php
Plantilla::fin();
