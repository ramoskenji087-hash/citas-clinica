<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\PacienteService;
use App\Servicios\ReglaException;
use App\Validacion\Validador;
use App\Vistas\Plantilla;
use RuntimeException;

Sesion::iniciar();
Sesion::requerirRol([Roles::RECEPCIONISTA]);

// Con ?id= se modifica un paciente existente; sin id se registra uno nuevo.
$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;

$campos = ['ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion'];
$datos = array_fill_keys($campos, '');
$errores = [];
$errorGeneral = null;

// Al modificar, el formulario empieza con los datos guardados.
try {
    if ($id !== null) {
        $paciente = PacienteService::obtener($id);

        if ($paciente === null) {
            header('Location: pacientes.php?aviso=noexiste');
            exit;
        }

        foreach ($campos as $campo) {
            $datos[$campo] = (string) ($paciente[$campo] ?? '');
        }
    }
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
        $errores = Validador::paciente($datos);

        if ($errores === []) {
            try {
                if ($id === null) {
                    PacienteService::registrar($datos);
                    $resultado = 'registrado';
                } else {
                    PacienteService::modificar($id, $datos);
                    $resultado = 'modificado';
                }

                header('Location: pacientes.php?aviso=' . $resultado);
                exit;
            } catch (ReglaException $e) {
                // Única regla de negocio de este formulario: la CI ya existe.
                $errores['ci'] = $e->getMessage();
            } catch (RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

$titulo = $id === null ? 'Nuevo paciente' : 'Modificar paciente';
$accion = $id === null ? 'paciente_form.php' : 'paciente_form.php?id=' . $id;

Plantilla::inicio($titulo);
?>
<p class="fecha"><a href="pacientes.php">&larr; Volver a pacientes</a></p>
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
        'ci'        => ['CI (documento de identidad)', 'text', 15],
        'nombres'   => ['Nombres', 'text', 80],
        'apellidos' => ['Apellidos', 'text', 80],
        'telefono'  => ['Teléfono', 'tel', 16],
        'correo'    => ['Correo electrónico (opcional)', 'text', 120],
        'direccion' => ['Dirección (opcional)', 'text', 200],
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

    <div class="campo<?= isset($errores['fecha_nacimiento']) ? ' con-error' : '' ?>">
      <label for="fecha_nacimiento">Fecha de nacimiento</label>
      <input id="fecha_nacimiento" name="fecha_nacimiento" type="date"
             value="<?= Plantilla::e($datos['fecha_nacimiento']) ?>"
             <?= isset($errores['fecha_nacimiento']) ? 'aria-invalid="true" aria-describedby="fecha_nacimiento-error"' : '' ?>>
      <?php if (isset($errores['fecha_nacimiento'])): ?>
        <p class="mensaje" id="fecha_nacimiento-error">Error: <?= Plantilla::e($errores['fecha_nacimiento']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['sexo']) ? ' con-error' : '' ?>">
      <label for="sexo">Sexo</label>
      <select id="sexo" name="sexo"
              <?= isset($errores['sexo']) ? 'aria-invalid="true" aria-describedby="sexo-error"' : '' ?>>
        <option value="">Seleccione</option>
        <?php foreach (['Masculino', 'Femenino', 'Otro'] as $opcion): ?>
          <option value="<?= $opcion ?>"<?= $datos['sexo'] === $opcion ? ' selected' : '' ?>><?= $opcion ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errores['sexo'])): ?>
        <p class="mensaje" id="sexo-error">Error: <?= Plantilla::e($errores['sexo']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="acciones">
    <button class="btn btn-principal" type="submit">Guardar</button>
    <a class="btn btn-linea" href="pacientes.php">Cancelar</a>
  </div>
</form>
<?php
Plantilla::fin();
