<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\CitaService;
use App\Servicios\EspecialidadService;
use App\Servicios\ReglaException;
use App\Validacion\ValidadorCita;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);

// Abrir la conexión fija la zona horaria de PHP, antes de calcular fechas y horas.
Conexion::obtener();

// Los datos llegan por GET (paso 1) o por POST (reservar la hora elegida).
$origen = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$texto = static fn (string $clave): string => is_string($origen[$clave] ?? null) ? trim($origen[$clave]) : '';
$numero = static fn (string $clave): int => ctype_digit($texto($clave)) ? (int) $texto($clave) : 0;

$datos = [
    'paciente' => $numero('paciente'),
    'especialidad' => $numero('especialidad'),
    'medico' => $numero('medico'),
    'fecha' => $texto('fecha'),
    'hora' => $texto('hora'),
    'motivo' => $texto('motivo'),
];

if ($datos['fecha'] === '') {
    $datos['fecha'] = date('Y-m-d');
}

$errores = [];
$errorGeneral = null;

// Paso 2: reservar la hora elegida.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        $errores = ValidadorCita::cita($datos);

        if ($errores === []) {
            try {
                $usuario = Sesion::usuarioActual();
                $cita = CitaService::registrar($datos, (int) $usuario['id']);

                header('Location: cita_nueva.php?reservada=' . $cita['codigo']);
                exit;
            } catch (ReglaException $e) {
                $errorGeneral = $e->getMessage();
            } catch (RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

// Código de la cita recién registrada (llega por la dirección tras redirigir).
$reservada = is_string($_GET['reservada'] ?? null) && preg_match('/^CM-\d{4,}$/', $_GET['reservada']) === 1
    ? $_GET['reservada']
    : null;

$pacientes = [];
$especialidades = [];
$medicos = [];
$horas = [];
$mostrarHoras = false;

try {
    $pacientes = CitaService::pacientesActivos();
    $especialidades = EspecialidadService::listarActivas();
    $medicos = CitaService::medicosActivos($datos['especialidad']);

    $mostrarHoras = $datos['paciente'] > 0
        && $datos['especialidad'] > 0
        && $datos['medico'] > 0
        && ValidadorCita::fecha($datos['fecha']) === null;

    if ($mostrarHoras) {
        $horas = CitaService::horasLibres($datos['medico'], $datos['fecha']);
    }
} catch (RuntimeException $e) {
    $errorGeneral = $errorGeneral ?? $e->getMessage();
}

Plantilla::inicio('Nueva cita');
?>
<h1>Nueva cita</h1>
<p class="resumen">Elija al paciente, la especialidad, el médico y el día. Después elija una hora libre.</p>

<?php if ($reservada !== null): ?>
  <p class="aviso aviso-ok">Cita registrada con el código <?= Plantilla::e($reservada) ?>. Su estado es Programada.</p>
<?php endif; ?>

<?php if ($errorGeneral !== null || $errores !== []): ?>
  <div class="resumen-errores" role="alert">
    <p>No se pudo registrar la cita. Revise estos datos:</p>
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

<h2>Paso 1: paciente, especialidad, médico y fecha</h2>
<form method="get" action="cita_nueva.php">
  <div class="campos-dobles">
    <div class="campo">
      <label for="paciente">Paciente</label>
      <select id="paciente" name="paciente">
        <option value="">Seleccione un paciente</option>
        <?php foreach ($pacientes as $p): ?>
          <option value="<?= (int) $p['id_paciente'] ?>"<?= (int) $p['id_paciente'] === $datos['paciente'] ? ' selected' : '' ?>>
            <?= Plantilla::e($p['apellidos'] . ', ' . $p['nombres'] . ' (CI ' . $p['ci'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="campo">
      <label for="especialidad">Especialidad</label>
      <select id="especialidad" name="especialidad">
        <option value="">Todas las especialidades</option>
        <?php foreach ($especialidades as $e): ?>
          <option value="<?= (int) $e['id_especialidad'] ?>"<?= (int) $e['id_especialidad'] === $datos['especialidad'] ? ' selected' : '' ?>>
            <?= Plantilla::e($e['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="campo">
      <label for="medico">Médico</label>
      <select id="medico" name="medico">
        <option value="">Seleccione un médico</option>
        <?php foreach ($medicos as $m): ?>
          <option value="<?= (int) $m['id_medico'] ?>"<?= (int) $m['id_medico'] === $datos['medico'] ? ' selected' : '' ?>>
            Dr(a). <?= Plantilla::e($m['nombres'] . ' ' . $m['apellidos']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="campo">
      <label for="fecha">Fecha de la cita</label>
      <input id="fecha" name="fecha" type="date" value="<?= Plantilla::e($datos['fecha']) ?>">
    </div>
  </div>

  <div class="acciones">
    <button class="btn btn-principal" type="submit">Ver horas libres</button>
  </div>
</form>

<?php if ($mostrarHoras): ?>
  <h2>Paso 2: hora libre</h2>

  <?php if ($horas === []): ?>
    <p>No hay horas libres para este médico en esa fecha. Pruebe con otro día o con otro médico.</p>
  <?php else: ?>
    <form method="post" action="cita_nueva.php">
      <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
      <input type="hidden" name="paciente" value="<?= $datos['paciente'] ?>">
      <input type="hidden" name="especialidad" value="<?= $datos['especialidad'] ?>">
      <input type="hidden" name="medico" value="<?= $datos['medico'] ?>">
      <input type="hidden" name="fecha" value="<?= Plantilla::e($datos['fecha']) ?>">

      <div class="campo ancho">
        <label for="motivo">Motivo de la consulta (opcional)</label>
        <input id="motivo" name="motivo" type="text" maxlength="200" value="<?= Plantilla::e($datos['motivo']) ?>">
      </div>

      <p>Elija una hora. Solo aparecen las horas libres.</p>
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
<?php
Plantilla::fin();
