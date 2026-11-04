<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\AdministracionUsuariosService;
use App\Servicios\CuentaCorreoService;
use App\Servicios\ReglaException;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::ADMINISTRADOR]);
Conexion::obtener();

$usuarioActual = Sesion::usuarioActual();

// Acciones desde la lista: solo con POST y token CSRF válido.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = 'error';
    $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;
    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

    if (Sesion::verificarCsrf($_POST['csrf'] ?? null) && $id > 0) {
        try {
            if (in_array($accion, ['activar', 'desactivar'], true)) {
                AdministracionUsuariosService::cambiarActivo($id, $accion === 'activar', (int) $usuarioActual['id']);
                $resultado = $accion === 'activar' ? 'activada' : 'desactivada';
            } elseif ($accion === 'enlace') {
                CuentaCorreoService::enviarActivacion($id);
                $resultado = 'enlace_enviado';
            }
        } catch (ReglaException $e) {
            $resultado = $accion === 'desactivar' ? 'propia' : 'error';
        } catch (\RuntimeException $e) {
            $resultado = $accion === 'enlace' ? 'enlace_error' : 'error';
        }
    }

    header('Location: usuarios.php?aviso=' . $resultado);
    exit;
}

$avisos = [
    'creada' => 'Cuenta creada. Se envió a la persona un correo para activarla.',
    'creada_sin_correo' => 'La cuenta se creó, pero no se pudo enviar el correo. Use "Enviar enlace" en la lista.',
    'modificada' => 'Cuenta actualizada.',
    'activada' => 'Cuenta activada.',
    'desactivada' => 'Cuenta desactivada. La persona ya no puede entrar al sistema.',
    'enlace_enviado' => 'Se envió el enlace a la persona.',
    'enlace_error' => 'No se pudo enviar el correo. Revise la configuración de Brevo o intente más tarde.',
    'propia' => 'No puede desactivar su propia cuenta.',
    'error' => 'No se pudo completar la acción. Intente de nuevo.',
];
$codigo = is_string($_GET['aviso'] ?? null) ? $_GET['aviso'] : '';
$aviso = $avisos[$codigo] ?? null;
$avisoError = in_array($codigo, ['creada_sin_correo', 'enlace_error', 'propia', 'error'], true);

$usuarios = [];
$errorGeneral = null;

try {
    $usuarios = AdministracionUsuariosService::listar();
} catch (\RuntimeException $e) {
    $errorGeneral = $e->getMessage();
}

Plantilla::inicio('Usuarios');
?>
<h1>Usuarios</h1>
<p class="resumen">Cree cuentas, asigne roles y vincule cada cuenta con su ficha de médico o de paciente.</p>

<?php if ($aviso !== null): ?>
  <p class="aviso <?= $avisoError ? 'aviso-error' : 'aviso-ok' ?>"><?= Plantilla::e($aviso) ?></p>
<?php endif; ?>

<?php if ($errorGeneral !== null): ?>
  <p class="aviso aviso-error" role="alert">Error: <?= Plantilla::e($errorGeneral) ?></p>
<?php endif; ?>

<div class="acciones">
  <a class="btn btn-principal" href="usuario_form.php">Nuevo usuario</a>
</div>

<?php if ($usuarios === []): ?>
  <p>No hay cuentas registradas.</p>
<?php else: ?>
  <ul class="lista">
    <?php foreach ($usuarios as $u): ?>
      <?php
      $activa = (int) $u['activo'] === 1;
      $esYo = (int) $u['id_usuario'] === (int) $usuarioActual['id'];
      $vinculo = $u['codigo_medico'] ?? $u['codigo_paciente'] ?? 'Sin ficha vinculada';
      ?>
      <li>
        <div class="cuerpo">
          <p class="nombre"><?= Plantilla::e($u['nombres'] . ' ' . $u['apellidos']) ?><?= $esYo ? ' (usted)' : '' ?></p>
          <p class="detalle">
            <?= Plantilla::e($u['nombre_usuario']) ?> · <?= Plantilla::e($u['correo']) ?> · <?= Plantilla::e($u['rol']) ?> · <?= Plantilla::e($vinculo) ?>
            <?php if ((int) $u['puede_marcar_atendida'] === 1): ?>
              · Puede marcar atendidas
            <?php endif; ?>
          </p>
        </div>

        <div class="acciones-fila">
          <?php if (!$activa): ?>
            <span class="etiqueta etiqueta-cancelada">Inactiva</span>
          <?php endif; ?>

          <a class="btn btn-linea" href="usuario_form.php?id=<?= (int) $u['id_usuario'] ?>">Modificar</a>

          <?php if ($activa): ?>
            <form method="post" action="usuarios.php">
              <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
              <input type="hidden" name="id" value="<?= (int) $u['id_usuario'] ?>">
              <input type="hidden" name="accion" value="enlace">
              <button class="btn btn-linea" type="submit">Enviar enlace</button>
            </form>
          <?php endif; ?>

          <?php if (!$esYo): ?>
            <form method="post" action="usuarios.php">
              <input type="hidden" name="csrf" value="<?= Plantilla::e(Sesion::tokenCsrf()) ?>">
              <input type="hidden" name="id" value="<?= (int) $u['id_usuario'] ?>">
              <input type="hidden" name="accion" value="<?= $activa ? 'desactivar' : 'activar' ?>">
              <button class="btn btn-linea" type="submit"><?= $activa ? 'Desactivar' : 'Activar' ?></button>
            </form>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php
Plantilla::fin();
