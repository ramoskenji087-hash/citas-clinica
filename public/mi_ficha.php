<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\PortalPacienteService;
use App\Validacion\ValidadorPortal;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::PACIENTE]);
Conexion::obtener();

// El id del paciente sale de la sesión, nunca de la dirección de la página.
$usuario = Sesion::usuarioActual();
$idPaciente = (int) ($usuario['id_paciente'] ?? 0);

if ($idPaciente <= 0) {
    Plantilla::inicio('Mi ficha médica');
    echo '<h1>Mi ficha médica</h1><p class="aviso aviso-error" role="alert">Su usuario no está vinculado a una ficha de paciente. Acuda a Recepción.</p>';
    Plantilla::fin();
    exit;
}

$textos = ['alergias', 'enfermedades_cronicas', 'medicamentos', 'antecedentes'];
$emergencia = ['contacto_emergencia_nombre', 'contacto_emergencia_parentesco', 'contacto_emergencia_telefono'];
$campos = array_merge(['tipo_sangre'], $textos, $emergencia);

$datos = array_fill_keys($campos, '');
$datos['recibir_notificaciones'] = true;
$datos['compartir_historial_medico'] = false;
$errores = [];
$aviso = null;
$errorGeneral = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        foreach ($campos as $campo) {
            $datos[$campo] = is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : '';
        }
        $datos['recibir_notificaciones'] = isset($_POST['recibir_notificaciones']);
        $datos['compartir_historial_medico'] = isset($_POST['compartir_historial_medico']);

        $errores = ValidadorPortal::ficha($datos);

        if ($errores === []) {
            try {
                PortalPacienteService::actualizarFicha($idPaciente, $datos);
                $aviso = 'Su ficha médica se guardó correctamente.';
            } catch (\RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
} else {
    try {
        $fila = PortalPacienteService::ficha($idPaciente);
        if ($fila !== null) {
            foreach ($campos as $campo) {
                $datos[$campo] = (string) ($fila[$campo] ?? '');
            }
            $datos['recibir_notificaciones'] = (int) $fila['recibir_notificaciones'] === 1;
            $datos['compartir_historial_medico'] = (int) $fila['compartir_historial_medico'] === 1;
        }
    } catch (\RuntimeException $e) {
        $errorGeneral = $e->getMessage();
    }
}

Plantilla::inicio('Mi ficha médica');
?>
<h1>Mi ficha médica</h1>
<p class="resumen">Estos datos ayudan al médico a atenderle con seguridad. Solo usted los modifica.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso aviso-ok"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<?php if ($errores !== []): ?>
  <div class="resumen-errores" role="alert">
    <p>Revise estos datos:</p>
    <ul>
      <?php foreach ($errores as $campo => $mensaje): ?>
        <li><a href="#<?= Plantilla::e($campo) ?>"><?= Plantilla::e($mensaje) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="mi_ficha.php">
  <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

  <fieldset class="grupo">
    <legend>Información médica</legend>

    <div class="campo" id="tipo_sangre">
      <label for="tipo_sangre_campo">Tipo de sangre</label>
      <select id="tipo_sangre_campo" name="tipo_sangre">
        <option value="">No indicar</option>
        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $tipo): ?>
          <option value="<?= $tipo ?>"<?= $datos['tipo_sangre'] === $tipo ? ' selected' : '' ?>><?= $tipo ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="campo" id="alergias">
      <label for="alergias_campo">Alergias</label>
      <textarea id="alergias_campo" name="alergias" rows="3" maxlength="1000"><?= Plantilla::e($datos['alergias']) ?></textarea>
    </div>

    <div class="campo" id="enfermedades_cronicas">
      <label for="enfermedades_cronicas_campo">Enfermedades crónicas</label>
      <textarea id="enfermedades_cronicas_campo" name="enfermedades_cronicas" rows="3" maxlength="1000"><?= Plantilla::e($datos['enfermedades_cronicas']) ?></textarea>
    </div>

    <div class="campo" id="medicamentos">
      <label for="medicamentos_campo">Medicamentos que toma</label>
      <textarea id="medicamentos_campo" name="medicamentos" rows="3" maxlength="1000"><?= Plantilla::e($datos['medicamentos']) ?></textarea>
    </div>

    <div class="campo" id="antecedentes">
      <label for="antecedentes_campo">Antecedentes relevantes</label>
      <textarea id="antecedentes_campo" name="antecedentes" rows="3" maxlength="1000"><?= Plantilla::e($datos['antecedentes']) ?></textarea>
    </div>
  </fieldset>

  <fieldset class="grupo">
    <legend>Contacto de emergencia</legend>
    <p class="resumen">Si llena un dato, complete los tres.</p>

    <div class="campo" id="contacto_emergencia_nombre">
      <label for="emergencia_nombre_campo">Nombre</label>
      <input id="emergencia_nombre_campo" name="contacto_emergencia_nombre" type="text" maxlength="120"
             value="<?= Plantilla::e($datos['contacto_emergencia_nombre']) ?>">
    </div>

    <div class="campo" id="contacto_emergencia_parentesco">
      <label for="emergencia_parentesco_campo">Parentesco</label>
      <input id="emergencia_parentesco_campo" name="contacto_emergencia_parentesco" type="text" maxlength="60"
             value="<?= Plantilla::e($datos['contacto_emergencia_parentesco']) ?>">
    </div>

    <div class="campo" id="contacto_emergencia_telefono">
      <label for="emergencia_telefono_campo">Teléfono</label>
      <input id="emergencia_telefono_campo" name="contacto_emergencia_telefono" type="tel" maxlength="20"
             value="<?= Plantilla::e($datos['contacto_emergencia_telefono']) ?>">
    </div>
  </fieldset>

  <fieldset class="grupo" id="preferencias">
    <legend>Preferencias</legend>
    <label class="opcion">
      <input type="checkbox" name="recibir_notificaciones" value="1"<?= $datos['recibir_notificaciones'] ? ' checked' : '' ?>>
      Quiero recibir recordatorios de mis citas por correo electrónico.
    </label>
    <label class="opcion">
      <input type="checkbox" name="compartir_historial_medico" value="1"<?= $datos['compartir_historial_medico'] ? ' checked' : '' ?>>
      Permitir que los médicos con una cita mía vean mi ficha médica.
    </label>
  </fieldset>

  <div class="acciones">
    <button class="btn btn-principal" type="submit">Guardar ficha</button>
  </div>
</form>
<?php
Plantilla::fin();
