<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Seguridad\Sesion;
use App\Servicios\HistorialMedicoService;
use App\Vistas\Plantilla;

Sesion::iniciar();
Sesion::requerirRol([Roles::MEDICO]);
Conexion::obtener();

// El médico sale de la sesión; el paciente llega por la dirección, pero el servicio comprueba
// que el médico tenga una cita con ese paciente y que el paciente permita compartir su ficha.
$usuario = Sesion::usuarioActual();
$idMedico = (int) ($usuario['id_medico'] ?? 0);
$idPaciente = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : 0;

$ficha = null;
if ($idMedico > 0 && $idPaciente > 0) {
    $ficha = HistorialMedicoService::fichaParaMedico($idMedico, $idPaciente);
}

$noRegistrado = static fn (?string $valor): string => $valor === null || $valor === '' ? 'No registrado' : $valor;

Plantilla::inicio('Ficha del paciente');
?>
<p class="fecha"><a href="mi_agenda.php">&larr; Volver a mi agenda</a></p>
<h1>Ficha médica del paciente</h1>

<?php if ($ficha === null): ?>
  <p class="aviso aviso-error" role="alert">No puede ver esta ficha. Solo se muestra cuando el paciente tiene una cita con usted y permite compartir su ficha médica.</p>
<?php else: ?>
  <p class="nombre"><?= Plantilla::e($ficha['nombres'] . ' ' . $ficha['apellidos']) ?></p>
  <p class="detalle">CI <?= Plantilla::e($ficha['ci']) ?> · Fecha de nacimiento <?= Plantilla::e(date('d/m/Y', strtotime((string) $ficha['fecha_nacimiento']))) ?></p>

  <h2>Información médica</h2>
  <dl>
    <dt>Tipo de sangre</dt>
    <dd><?= Plantilla::e($noRegistrado($ficha['tipo_sangre'])) ?></dd>
    <dt>Alergias</dt>
    <dd><?= Plantilla::e($noRegistrado($ficha['alergias'])) ?></dd>
    <dt>Enfermedades crónicas</dt>
    <dd><?= Plantilla::e($noRegistrado($ficha['enfermedades_cronicas'])) ?></dd>
    <dt>Medicamentos que toma</dt>
    <dd><?= Plantilla::e($noRegistrado($ficha['medicamentos'])) ?></dd>
    <dt>Antecedentes relevantes</dt>
    <dd><?= Plantilla::e($noRegistrado($ficha['antecedentes'])) ?></dd>
  </dl>
<?php endif; ?>
<?php
Plantilla::fin();
