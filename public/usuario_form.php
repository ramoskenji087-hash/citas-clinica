<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\AdministracionUsuariosService;
use App\Servicios\CuentaCorreoService;
use App\Servicios\ReglaException;
use App\Validacion\ValidadorUsuario;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::ADMINISTRADOR]);
Conexion::obtener();

$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;

$datos = [
    'nombre_usuario' => '', 'correo' => '', 'nombres' => '', 'apellidos' => '',
    'rol' => 0, 'id_medico' => 0, 'id_paciente' => 0, 'puede_marcar_atendida' => false,
];
$errores = [];
$errorGeneral = null;

try {
    if ($id !== null) {
        $cuenta = AdministracionUsuariosService::obtener($id);

        if ($cuenta === null) {
            header('Location: usuarios.php?aviso=error');
            exit;
        }

        $datos = [
            'nombre_usuario' => (string) $cuenta['nombre_usuario'],
            'correo' => (string) $cuenta['correo'],
            'nombres' => (string) $cuenta['nombres'],
            'apellidos' => (string) $cuenta['apellidos'],
            'rol' => (int) $cuenta['id_rol'],
            'id_medico' => (int) ($cuenta['id_medico'] ?? 0),
            'id_paciente' => (int) ($cuenta['id_paciente'] ?? 0),
            'puede_marcar_atendida' => (int) $cuenta['puede_marcar_atendida'] === 1,
        ];
    }
} catch (\RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Sesion::verificarCsrf($_POST['csrf'] ?? null)) {
        $errorGeneral = 'La sesión del formulario venció. Vuelva a intentarlo.';
    } else {
        $texto = static fn (string $clave): string => is_string($_POST[$clave] ?? null) ? trim($_POST[$clave]) : '';
        $numero = static fn (string $clave): int => ctype_digit($texto($clave)) ? (int) $texto($clave) : 0;

        $datos = [
            'nombre_usuario' => $texto('nombre_usuario'),
            'correo' => $texto('correo'),
            'nombres' => $texto('nombres'),
            'apellidos' => $texto('apellidos'),
            'rol' => $numero('rol'),
            'id_medico' => $numero('id_medico'),
            'id_paciente' => $numero('id_paciente'),
            'puede_marcar_atendida' => isset($_POST['puede_marcar_atendida']),
        ];

        $errores = ValidadorUsuario::usuario($datos);

        if ($errores === []) {
            try {
                if ($id === null) {
                    $nuevoId = AdministracionUsuariosService::crear($datos);

                    // La cuenta ya existe. Si el correo falla, se avisa y se puede reenviar desde la lista.
                    try {
                        CuentaCorreoService::enviarActivacion($nuevoId);
                        header('Location: usuarios.php?aviso=creada');
                    } catch (\RuntimeException $e) {
                        header('Location: usuarios.php?aviso=creada_sin_correo');
                    }
                } else {
                    AdministracionUsuariosService::modificar($id, $datos);
                    header('Location: usuarios.php?aviso=modificada');
                }
                exit;
            } catch (ReglaException $e) {
                $errorGeneral = $e->getMessage();
            } catch (\RuntimeException $e) {
                $errorGeneral = $e->getMessage();
            }
        }
    }
}

// Fichas disponibles para vincular (las que no tienen otra cuenta).
$medicos = [];
$pacientes = [];
try {
    $medicos = AdministracionUsuariosService::medicosDisponibles((int) ($id ?? 0));
    $pacientes = AdministracionUsuariosService::pacientesDisponibles((int) ($id ?? 0));
} catch (\RuntimeException $e) {
    $errorGeneral = $errorGeneral ?? $e->getMessage();
}

$titulo = $id === null ? 'Nuevo usuario' : 'Modificar usuario';
$accion = $id === null ? 'usuario_form.php' : 'usuario_form.php?id=' . $id;

Plantilla::inicio($titulo);
?>
<p class="fecha"><a href="usuarios.php">&larr; Volver a usuarios</a></p>
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

<?php if ($id === null): ?>
  <p class="resumen">Al guardar, el sistema envía a la persona un correo para activar su cuenta y elegir su contraseña. Usted no necesita conocer esa contraseña.</p>
<?php else: ?>
  <p class="resumen">Para cambiar la contraseña, use <em>Enviar enlace</em> en la lista de usuarios.</p>
<?php endif; ?>

<form method="post" action="<?= Plantilla::e($accion) ?>">
  <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">

  <div class="campos-dobles">
    <div class="campo<?= isset($errores['nombre_usuario']) ? ' con-error' : '' ?>">
      <label for="nombre_usuario">Nombre de usuario</label>
      <input id="nombre_usuario" name="nombre_usuario" type="text" maxlength="30" value="<?= Plantilla::e($datos['nombre_usuario']) ?>">
      <?php if (isset($errores['nombre_usuario'])): ?>
        <p class="mensaje">Error: <?= Plantilla::e($errores['nombre_usuario']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['correo']) ? ' con-error' : '' ?>">
      <label for="correo">Correo electrónico</label>
      <input id="correo" name="correo" type="email" maxlength="120" value="<?= Plantilla::e($datos['correo']) ?>">
      <?php if (isset($errores['correo'])): ?>
        <p class="mensaje">Error: <?= Plantilla::e($errores['correo']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['nombres']) ? ' con-error' : '' ?>">
      <label for="nombres">Nombres</label>
      <input id="nombres" name="nombres" type="text" maxlength="80" value="<?= Plantilla::e($datos['nombres']) ?>">
      <?php if (isset($errores['nombres'])): ?>
        <p class="mensaje">Error: <?= Plantilla::e($errores['nombres']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['apellidos']) ? ' con-error' : '' ?>">
      <label for="apellidos">Apellidos</label>
      <input id="apellidos" name="apellidos" type="text" maxlength="80" value="<?= Plantilla::e($datos['apellidos']) ?>">
      <?php if (isset($errores['apellidos'])): ?>
        <p class="mensaje">Error: <?= Plantilla::e($errores['apellidos']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['rol']) ? ' con-error' : '' ?>">
      <label for="rol">Rol</label>
      <select id="rol" name="rol">
        <option value="">Seleccione un rol</option>
        <?php foreach ([Roles::ADMINISTRADOR, Roles::RECEPCIONISTA, Roles::MEDICO, Roles::PACIENTE] as $r): ?>
          <option value="<?= $r ?>"<?= $r === $datos['rol'] ? ' selected' : '' ?>><?= Plantilla::e(Roles::nombre($r)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errores['rol'])): ?>
        <p class="mensaje">Error: <?= Plantilla::e($errores['rol']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['id_medico']) ? ' con-error' : '' ?>">
      <label for="id_medico">Ficha de médico (solo para el rol Médico)</label>
      <select id="id_medico" name="id_medico">
        <option value="">Sin ficha de médico</option>
        <?php foreach ($medicos as $m): ?>
          <option value="<?= (int) $m['id_medico'] ?>"<?= (int) $m['id_medico'] === $datos['id_medico'] ? ' selected' : '' ?>>
            <?= Plantilla::e($m['codigo'] . ' · Dr(a). ' . $m['nombres'] . ' ' . $m['apellidos']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errores['id_medico'])): ?>
        <p class="mensaje">Error: <?= Plantilla::e($errores['id_medico']) ?></p>
      <?php endif; ?>
    </div>

    <div class="campo<?= isset($errores['id_paciente']) ? ' con-error' : '' ?>">
      <label for="id_paciente">Ficha de paciente (solo para el rol Paciente)</label>
      <select id="id_paciente" name="id_paciente">
        <option value="">Sin ficha de paciente</option>
        <?php foreach ($pacientes as $p): ?>
          <option value="<?= (int) $p['id_paciente'] ?>"<?= (int) $p['id_paciente'] === $datos['id_paciente'] ? ' selected' : '' ?>>
            <?= Plantilla::e($p['codigo'] . ' · ' . $p['apellidos'] . ', ' . $p['nombres']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errores['id_paciente'])): ?>
        <p class="mensaje">Error: <?= Plantilla::e($errores['id_paciente']) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <fieldset class="grupo" id="permiso">
    <legend>Permiso del médico</legend>
    <label class="opcion">
      <input type="checkbox" name="puede_marcar_atendida" value="1"<?= $datos['puede_marcar_atendida'] ? ' checked' : '' ?>>
      Puede marcar citas como atendidas (solo para el rol Médico)
    </label>
  </fieldset>

  <div class="acciones">
    <button class="btn btn-principal" type="submit"><?= $id === null ? 'Crear y enviar correo' : 'Guardar cambios' ?></button>
    <a class="btn btn-linea" href="usuarios.php">Cancelar</a>
  </div>
</form>
<?php
Plantilla::fin();
