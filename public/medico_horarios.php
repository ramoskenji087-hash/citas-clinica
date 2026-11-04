<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\HorarioService;
use App\Servicios\MedicoService;
use App\Servicios\ReglaException;
use App\Validacion\ValidadorAgenda;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::ADMINISTRADOR]);

$idMedico = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : 0;

$diasSemana = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves',
               5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
$duraciones = [15, 20, 30, 45, 60];

$datos = ['dia' => '', 'hora_inicio' => '', 'hora_fin' => '', 'duracion' => '30'];
$errores = [];
$errorGeneral = null;
$medico = null;
$horarios = [];

try {
    // false = no existe; null = error de base de datos (se muestra el mensaje, no el "no existe")
    $medico = $idMedico > 0 ? (MedicoService::obtener($idMedico) ?? false) : false;
} catch (RuntimeException $e) {
    $medico = null;
    $errorGeneral = $e->getMessage();
}

if ($medico === false) {
    header('Location: medicos.php?aviso=noexiste');
    exit;
}

if ($medico !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } elseif (($_POST['accion'] ?? '') === 'eliminar') {
        $idHorario = is_string($_POST['id_horario'] ?? null) && ctype_digit($_POST['id_horario'])
            ? (int) $_POST['id_horario'] : 0;
        try {
            HorarioService::eliminar($idHorario, $idMedico);
            header('Location: medico_horarios.php?id=' . $idMedico . '&aviso=eliminado');
            exit;
        } catch (RuntimeException $e) {
            $errorGeneral = $e->getMessage();
        }
    } elseif (($_POST['accion'] ?? '') === 'agregar') {
        foreach (array_keys($datos) as $campo) {
            $valor = $_POST[$campo] ?? '';
            $datos[$campo] = is_string($valor) ? trim($valor) : '';
        }

        $errores = ValidadorAgenda::horario($datos);

        if ($errores === []) {
            try {
                HorarioService::agregar($idMedico, $datos);
                header('Location: medico_horarios.php?id=' . $idMedico . '&aviso=agregado');
                exit;
            } catch (ReglaException $e) {
                // Por ejemplo, el bloque se cruza con otro del mismo día (VS012).
                $errorGeneral = $e->getMessage();
            } catch (RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

$avisos = [
    'agregado' => 'Horario agregado correctamente.',
    'eliminado' => 'Horario eliminado.',
];
$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;

if ($medico !== null) {
    try {
        $horarios = HorarioService::listarPorMedico($idMedico);
    } catch (RuntimeException $e) {
        $errorGeneral = $errorGeneral ?? $e->getMessage();
    }
}

Plantilla::inicio('Horarios del médico');
?>
<p class="fecha"><a href="medicos.php">&larr; Volver a médicos y horarios</a></p>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<?php if ($medico !== null): ?>
  <h1>Horarios de Dr(a). <?= Plantilla::e($medico['nombres'] . ' ' . $medico['apellidos']) ?></h1>
  <p class="resumen">Cada bloque indica el día, las horas de atención y la duración de cada cita.</p>

  <?php if ($aviso !== null): ?>
    <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
  <?php endif; ?>

  <h2>Horarios configurados</h2>
  <?php if ($horarios === []): ?>
    <p>Este médico todavía no tiene horarios. Agregue el primero.</p>
  <?php else: ?>
    <ul class="lista">
      <?php foreach ($horarios as $h): ?>
        <li>
          <div class="cuerpo">
            <p class="nombre">
              <?= Plantilla::e($diasSemana[(int) $h['dia_semana']] ?? 'Día ' . (int) $h['dia_semana']) ?>
              · <?= Plantilla::e($h['hora_inicio']) ?> a <?= Plantilla::e($h['hora_fin']) ?>
            </p>
            <p class="detalle">Citas de <?= (int) $h['duracion_cita'] ?> minutos</p>
          </div>
          <form method="post" action="medico_horarios.php?id=<?= $idMedico ?>">
            <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="id_horario" value="<?= (int) $h['id_horario'] ?>">
            <button class="btn btn-linea" type="submit">Eliminar</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2>Agregar horario</h2>

  <?php if ($errores !== []): ?>
    <div class="resumen-errores" role="alert">
      <p>No se pudo agregar el horario. Revise estos datos:</p>
      <ul>
        <?php foreach ($errores as $campo => $mensaje): ?>
          <li><a href="#<?= Plantilla::e($campo) ?>"><?= Plantilla::e($mensaje) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="medico_horarios.php?id=<?= $idMedico ?>">
    <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
    <input type="hidden" name="accion" value="agregar">

    <div class="campos-dobles">
      <div class="campo<?= isset($errores['dia']) ? ' con-error' : '' ?>">
        <label for="dia">Día de la semana</label>
        <select id="dia" name="dia">
          <option value="">Seleccione</option>
          <?php foreach ($diasSemana as $numero => $nombreDia): ?>
            <option value="<?= $numero ?>"<?= (string) $numero === $datos['dia'] ? ' selected' : '' ?>>
              <?= Plantilla::e($nombreDia) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errores['dia'])): ?>
          <p class="mensaje" id="dia-error">Error: <?= Plantilla::e($errores['dia']) ?></p>
        <?php endif; ?>
      </div>

      <div class="campo<?= isset($errores['duracion']) ? ' con-error' : '' ?>">
        <label for="duracion">Duración de cada cita</label>
        <select id="duracion" name="duracion">
          <?php foreach ($duraciones as $minutos): ?>
            <option value="<?= $minutos ?>"<?= (string) $minutos === $datos['duracion'] ? ' selected' : '' ?>>
              <?= $minutos ?> minutos
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errores['duracion'])): ?>
          <p class="mensaje" id="duracion-error">Error: <?= Plantilla::e($errores['duracion']) ?></p>
        <?php endif; ?>
      </div>

      <div class="campo<?= isset($errores['hora_inicio']) ? ' con-error' : '' ?>">
        <label for="hora_inicio">Hora de inicio</label>
        <input id="hora_inicio" name="hora_inicio" type="time" value="<?= Plantilla::e($datos['hora_inicio']) ?>">
        <?php if (isset($errores['hora_inicio'])): ?>
          <p class="mensaje" id="hora_inicio-error">Error: <?= Plantilla::e($errores['hora_inicio']) ?></p>
        <?php endif; ?>
      </div>

      <div class="campo<?= isset($errores['hora_fin']) ? ' con-error' : '' ?>">
        <label for="hora_fin">Hora de fin</label>
        <input id="hora_fin" name="hora_fin" type="time" value="<?= Plantilla::e($datos['hora_fin']) ?>">
        <?php if (isset($errores['hora_fin'])): ?>
          <p class="mensaje" id="hora_fin-error">Error: <?= Plantilla::e($errores['hora_fin']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="acciones">
      <button class="btn btn-principal" type="submit">Agregar horario</button>
    </div>
  </form>
<?php endif; ?>
<?php
Plantilla::fin();
