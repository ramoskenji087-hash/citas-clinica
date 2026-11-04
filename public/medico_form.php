<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\EspecialidadService;
use App\Servicios\MedicoService;
use App\Servicios\ReglaException;
use App\Validacion\Validador;
use App\Validacion\ValidadorAgenda;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::ADMINISTRADOR]);

// Con ?id= se modifica un médico; sin id se registra uno nuevo.
$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;

$campos = ['registro_profesional', 'nombres', 'apellidos', 'telefono', 'correo'];
$datos = array_fill_keys($campos, '');
$seleccionadas = [];
$errores = [];
$errorGeneral = null;
$especialidades = [];

try {
    if ($id !== null) {
        $medico = MedicoService::obtener($id);

        if ($medico === null) {
            header('Location: medicos.php?aviso=noexiste');
            exit;
        }

        foreach ($campos as $campo) {
            $datos[$campo] = (string) ($medico[$campo] ?? '');
        }
        $seleccionadas = $medico['especialidades'];
    }

    $especialidades = EspecialidadService::listarActivas();
} catch (RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        foreach ($campos as $campo) {
            $valor = $_POST[$campo] ?? '';
            $datos[$campo] = is_string($valor) ? trim($valor) : '';
        }
        $datos['telefono'] = Validador::limpiarTelefono($datos['telefono']);

        // Solo se aceptan ids numéricos de especialidades, sin repetir.
        $seleccionadas = [];
        $recibidas = $_POST['especialidades'] ?? [];
        if (is_array($recibidas)) {
            foreach ($recibidas as $valor) {
                if (is_string($valor) && ctype_digit($valor)) {
                    $seleccionadas[] = (int) $valor;
                }
            }
        }
        $seleccionadas = array_values(array_unique($seleccionadas));

        $errores = ValidadorAgenda::medico(array_merge($datos, ['especialidades' => $seleccionadas]));

        if ($errores === []) {
            try {
                if ($id === null) {
                    MedicoService::registrar($datos, $seleccionadas);
                    $resultado = 'registrado';
                } else {
                    MedicoService::modificar($id, $datos, $seleccionadas);
                    $resultado = 'modificado';
                }

                header('Location: medicos.php?aviso=' . $resultado);
                exit;
            } catch (ReglaException $e) {
                // Registro duplicado, especialidad inactiva o especialidad con citas.
                $errorGeneral = $e->getMessage();
            } catch (RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

$titulo = $id === null ? 'Nuevo médico' : 'Modificar médico';
$accion = $id === null ? 'medico_form.php' : 'medico_form.php?id=' . $id;

Plantilla::inicio($titulo);
?>
<p class="fecha"><a href="medicos.php">&larr; Volver a médicos y horarios</a></p>
<h1><?= Plantilla::e($titulo) ?></h1>

<?php if ($errorGeneral !== null || $errores !== []): ?>
  <div class="resumen-errores" role="alert">
    <p>No se pudo guardar. Revise estos datos:</p>
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

<form method="post" action="<?= Plantilla::e($accion) ?>">
  <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

  <div class="campos-dobles">
    <?php
    $textos = [
        'registro_profesional' => ['Número de registro profesional', 'text', 30],
        'nombres' => ['Nombres', 'text', 80],
        'apellidos' => ['Apellidos', 'text', 80],
        'telefono' => ['Teléfono', 'tel', 16],
        'correo' => ['Correo electrónico', 'text', 120],
    ];

    foreach ($textos as $campo => [$etiqueta, $tipo, $maximo]):
        $error = $errores[$campo] ?? null;
    ?>
    <div class="campo<?= $error !== null ? ' con-error' : '' ?>">
      <label for="<?= $campo ?>"><?= Plantilla::e($etiqueta) ?></label>
      <input id="<?= $campo ?>" name="<?= $campo ?>" type="<?= $tipo ?>" maxlength="<?= $maximo ?>"
             value="<?= Plantilla::e($datos[$campo]) ?>"
             <?= $error !== null ? 'aria-invalid="true" aria-describedby="' . $campo . '-error"' : '' ?>>
      <?php if ($error !== null): ?>
        <p class="mensaje" id="<?= $campo ?>-error">Error: <?= Plantilla::e($error) ?></p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <fieldset class="grupo" id="especialidades">
    <legend>Especialidades (seleccione una o más)</legend>
    <?php if ($especialidades === []): ?>
      <p>No hay especialidades activas. Registre una en <a href="especialidades.php">Especialidades</a>.</p>
    <?php endif; ?>
    <?php foreach ($especialidades as $e): ?>
      <?php $idEsp = (int) $e['id_especialidad']; ?>
      <label class="opcion">
        <input type="checkbox" name="especialidades[]" value="<?= $idEsp ?>"
               <?= in_array($idEsp, $seleccionadas, true) ? 'checked' : '' ?>>
        <?= Plantilla::e($e['nombre']) ?>
      </label>
    <?php endforeach; ?>
    <?php if (isset($errores['especialidades'])): ?>
      <p class="mensaje">Error: <?= Plantilla::e($errores['especialidades']) ?></p>
    <?php endif; ?>
  </fieldset>

  <div class="acciones">
    <button class="btn btn-principal" type="submit">Guardar</button>
    <a class="btn btn-linea" href="medicos.php">Cancelar</a>
  </div>
</form>
<?php
Plantilla::fin();
