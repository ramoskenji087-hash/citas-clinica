<?php
declare(strict_types=1);

/**
 * Pruebas automáticas del sistema: P1 a P10 y reglas adicionales de negocio.
 *
 * Ejecución, desde la carpeta del proyecto, en la consola de XAMPP:
 *   php tests\run_tests.php
 *
 * Antes de cada ejecución borra y vuelve a crear la base citas_clinica_pruebas con
 * sql/schema.sql y sql/triggers.sql. La base real (citas_clinica) no se toca.
 * P9 (acceso a una página sin sesión) necesita el navegador: está en tests/checklist_manual.md.
 */

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'arranque.php';

use App\Datos\Conexion;
use App\Seguridad\Roles;
use App\Servicios\AgendaService;
use App\Servicios\AutenticacionService;
use App\Servicios\CitaEstadoService;
use App\Servicios\CitaService;
use App\Servicios\EspecialidadService;
use App\Servicios\HorarioService;
use App\Servicios\MedicoService;
use App\Servicios\PacienteService;
use App\Servicios\UsuarioService;
use App\Validacion\Validador;
use App\Validacion\ValidadorAgenda;
use App\Validacion\ValidadorCita;
use App\Validacion\ValidadorUsuario;
use App\Servicios\AdministracionUsuariosService;
use App\Servicios\CuentaCorreoService;
use App\Servicios\ReglaException;
use App\Servicios\PortalPacienteService;
use App\Servicios\RegistroPacienteService;
use App\Servicios\RecordatorioService;
use App\Servicios\HistorialMedicoService;
use App\Validacion\ValidadorPortal;

const BASE_PRUEBAS = 'citas_clinica_pruebas';
const FECHA_LUNES = '2027-03-01';   // lunes: Juan Pérez atiende de 08:00 a 12:00
const FECHA_DOMINGO = '2027-02-28'; // domingo: ningún médico atiende

// ---------------------------------------------------------------------------
// Utilidades de prueba
// ---------------------------------------------------------------------------

/** Lanza una excepción si la condición es falsa. Así cada prueba falla con un mensaje claro. */
function afirmar(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

/**
 * Comprueba que la acción se rechaza. La rechazo puede venir de una excepción de negocio
 * (se busca un fragmento del mensaje) o de la base de datos (se busca el SQLSTATE, por ejemplo
 * 23P01 para superposición o VS003 para horario fuera de atención).
 *
 * @param string|string[] $pistas
 */
function esperarRechazo(callable $accion, string|array $pistas): void
{
    $pistas = (array) $pistas;

    try {
        $accion();
    } catch (\Throwable $e) {
        $codigo = $e instanceof \PDOException ? (string) ($e->errorInfo[0] ?? '') : '';

        foreach ($pistas as $pista) {
            if ($codigo === $pista || str_contains($e->getMessage(), $pista)) {
                return;
            }
        }

        throw new RuntimeException('Se rechazó, pero con otro mensaje: ' . $e->getMessage());
    }

    throw new RuntimeException('Debía rechazarse y se aceptó.');
}

/** Datos válidos de un paciente de prueba. */
function datosPaciente(string $ci, string $nombres, string $apellidos): array
{
    return [
        'ci' => $ci,
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'fecha_nacimiento' => '1995-03-10',
        'sexo' => 'Masculino',
        'telefono' => '70123456',
        'correo' => '',
        'direccion' => '',
    ];
}

/** Reserva una cita con Juan Pérez (médico 1, Medicina general), registrada por recepción. */
function reservar(int $idPaciente, string $fecha, string $hora, string $motivo = 'Consulta de prueba'): array
{
    global $ctx;

    return CitaService::registrar([
        'paciente' => $idPaciente,
        'especialidad' => 1,
        'medico' => 1,
        'fecha' => $fecha,
        'hora' => $hora,
        'motivo' => $motivo,
    ], $ctx->idRecepcion);
}

/**
 * Inserta una cita directamente en la base, saltándose los servicios de PHP.
 * Sirve para probar que la base también rechaza datos inválidos.
 */
function insertarCitaDirecta(int $idPaciente, string $inicio, string $fin): void
{
    global $ctx;

    $sql = "INSERT INTO cita (id_paciente, id_medico, id_especialidad, inicio, fin, estado, id_usuario_registro)
            VALUES (:paciente, 1, 1, :inicio, :fin, 'Programada', :usuario)";

    Conexion::obtener()->prepare($sql)->execute([
        ':paciente' => $idPaciente,
        ':inicio' => $inicio,
        ':fin' => $fin,
        ':usuario' => $ctx->idRecepcion,
    ]);
}

/** Inicia sesión con un usuario de demostración. Si falla, la prueba se detiene con un mensaje claro. */
function usuarioDemo(string $nombre, string $clave): array
{
    $usuario = AutenticacionService::autenticar($nombre, $clave);

    if ($usuario === null) {
        throw new RuntimeException("No se pudo iniciar sesión con el usuario de demostración '$nombre'. Revise los datos de prueba.");
    }

    return $usuario;
}

/** Crea una cuenta de prueba sin enviar correo (la contraseña la define el enlace, no el alta). */
function crearUsuarioDePrueba(string $nombre): int
{
    return AdministracionUsuariosService::crear([
        'nombre_usuario' => $nombre,
        'correo' => $nombre . '@ejemplo.com',
        'nombres' => 'Prueba',
        'apellidos' => 'Correo',
        'rol' => Roles::RECEPCIONISTA,
        'id_medico' => 0,
        'id_paciente' => 0,
        'puede_marcar_atendida' => false,
    ]);
}

/**
 * Reinstala la base de pruebas desde cero. Solo acepta nombres que terminen en "_pruebas",
 * para que nunca borre la base real por error.
 */
function reinstalarBasePruebas(): void
{
    if (substr(BASE_PRUEBAS, -8) !== '_pruebas') {
        throw new RuntimeException('El nombre de la base de pruebas debe terminar en "_pruebas".');
    }

    $config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
    $base = $config['base'];

    // Se conecta a la base "postgres" para poder borrar y crear la de pruebas.
    $administracion = new \PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', $base['host'], $base['puerto']),
        $base['usuario'],
        $base['clave'],
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
    $administracion->exec('DROP DATABASE IF EXISTS ' . BASE_PRUEBAS . ' WITH (FORCE)');
    $administracion->exec('CREATE DATABASE ' . BASE_PRUEBAS . " ENCODING 'UTF8' TEMPLATE template0");
    $administracion = null;

    Conexion::usarBase(BASE_PRUEBAS);
    $pdo = Conexion::obtener();
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/triggers.sql'));
}

// ---------------------------------------------------------------------------
// Preparación y pruebas
// ---------------------------------------------------------------------------

try {
    reinstalarBasePruebas();

    // Datos de referencia de la base de pruebas (los datos semilla de schema.sql).
    $ctx = new stdClass();
    $ctx->idRecepcion = (int) usuarioDemo('recepcion', 'Recep2026')['id_usuario'];
    $ctx->idMedicoUsuario = (int) usuarioDemo('medico', 'Medico2026')['id_usuario'];
    $ctx->idPaciente1 = (int) usuarioDemo('paciente', 'Pacien2026')['id_paciente'];
    $ctx->idPaciente2 = (int) Conexion::obtener()
        ->query('SELECT id_paciente FROM paciente WHERE id_paciente <> ' . $ctx->idPaciente1 . ' ORDER BY id_paciente LIMIT 1')
        ->fetchColumn();

    echo "Base de pruebas reinstalada: " . BASE_PRUEBAS . "\n\n";
} catch (\Throwable $e) {
    echo 'ERROR al preparar la base de pruebas: ' . $e->getMessage() . "\n";
    exit(1);
}

$pruebas = [];

// --- P1 a P10: requisitos de la sección 8 ---------------------------------

$pruebas[] = ['P1', 'Registrar un paciente nuevo lo guarda', function () {
    $id = PacienteService::registrar(datosPaciente('9900001', 'Carlos', 'Ruiz'));
    $paciente = PacienteService::obtener($id);

    afirmar($id > 0 && $paciente !== null, 'El paciente no quedó guardado.');
    afirmar($paciente['ci'] === '9900001', 'La CI guardada no coincide.');
}];

$pruebas[] = ['P2', 'Una CI que ya existe se rechaza', function () {
    global $ctx;

    $ci = PacienteService::obtener($ctx->idPaciente1)['ci'];

    esperarRechazo(
        fn () => PacienteService::registrar(datosPaciente((string) $ci, 'Otra', 'Persona')),
        'Ya existe un paciente con ese CI'
    );
}];

$pruebas[] = ['P10', 'Datos inválidos se detectan antes de guardar', function () {
    $errores = Validador::paciente([
        'ci' => '12-3',
        'nombres' => 'A',
        'apellidos' => 'Pérez',
        'fecha_nacimiento' => '2099-01-01',
        'sexo' => 'X',
        'telefono' => 'abc',
        'correo' => 'no-es-correo',
        'direccion' => '',
    ]);

    afirmar(count($errores) >= 4, 'Se esperaban al menos 4 errores y hubo ' . count($errores) . '.');
}];

$pruebas[] = ['P3', 'Una cita en horario disponible queda Programada', function () {
    global $ctx;

    $cita = reservar($ctx->idPaciente1, FECHA_LUNES, '09:00', 'Control');
    $fila = CitaEstadoService::obtener($cita['id']);

    afirmar($fila !== null && $fila['estado'] === 'Programada', 'La cita no quedó Programada.');
    afirmar(str_starts_with($cita['codigo'], 'CM-'), 'El código no empieza con CM-.');

    $ctx->citaNueve = $cita['id'];
    $ctx->codigoNueve = $cita['codigo'];
}];

$pruebas[] = ['P4', 'Una cita superpuesta se rechaza (servicio)', function () {
    global $ctx;

    esperarRechazo(
        fn () => reservar($ctx->idPaciente2, FECHA_LUNES, '09:00'),
        'ya no está disponible'
    );
}];

$pruebas[] = ['P4b', 'La base de datos rechaza citas superpuestas (23P01)', function () {
    global $ctx;

    esperarRechazo(
        fn () => insertarCitaDirecta($ctx->idPaciente2, FECHA_LUNES . ' 09:00:00', FECHA_LUNES . ' 09:30:00'),
        '23P01'
    );
}];

$pruebas[] = ['P5', 'Una cita fuera del horario se rechaza (servicio)', function () {
    global $ctx;

    esperarRechazo(
        fn () => reservar($ctx->idPaciente2, FECHA_DOMINGO, '09:00'),
        ['ya no está disponible', 'fuera del horario']
    );
}];

$pruebas[] = ['P5b', 'La base de datos rechaza una cita fuera del horario (VS003)', function () {
    global $ctx;

    esperarRechazo(
        fn () => insertarCitaDirecta($ctx->idPaciente2, FECHA_DOMINGO . ' 09:00:00', FECHA_DOMINGO . ' 09:30:00'),
        'VS003'
    );
}];

$pruebas[] = ['P6', 'Cancelar con motivo deja la cita Cancelada y conserva el registro', function () {
    global $ctx;

    CitaEstadoService::cancelar($ctx->citaNueve, 'El paciente avisó');
    $fila = CitaEstadoService::obtener($ctx->citaNueve);

    afirmar($fila !== null, 'La cita fue eliminada en lugar de cancelarse.');
    afirmar($fila['estado'] === 'Cancelada', 'El estado no es Cancelada.');
    afirmar($fila['motivo_cancelacion'] === 'El paciente avisó', 'El motivo de cancelación no se guardó.');
}];

$pruebas[] = ['P6b', 'Una cita cancelada libera el horario', function () {
    global $ctx;

    $nueva = reservar($ctx->idPaciente2, FECHA_LUNES, '09:00');

    afirmar(CitaEstadoService::obtener($nueva['id'])['estado'] === 'Programada', 'La nueva cita no quedó Programada.');

    $ctx->citaNueveBis = $nueva['id'];
}];

$pruebas[] = ['P7', 'El historial del paciente incluye también las citas canceladas', function () {
    global $ctx;

    $codigos = array_column(PacienteService::historial($ctx->idPaciente1), 'codigo');

    afirmar(in_array($ctx->codigoNueve, $codigos, true), 'Falta la cita cancelada en el historial.');
}];

$pruebas[] = ['P8', 'La agenda muestra solo las citas del médico y de la fecha indicadas', function () {
    $agenda = AgendaService::listar(FECHA_LUNES, 1);

    afirmar(count($agenda) >= 1, 'La agenda de Juan Pérez del lunes no muestra citas.');

    foreach ($agenda as $cita) {
        afirmar((int) $cita['id_medico'] === 1, 'La agenda incluye citas de otro médico.');
        afirmar(substr((string) $cita['inicio'], 0, 10) === FECHA_LUNES, 'La agenda incluye citas de otra fecha.');
    }

    afirmar(AgendaService::listar(FECHA_LUNES, 2) === [], 'Ana Gutiérrez no atiende los lunes.');
}];

// --- Transiciones de estado (RF10) y reprogramación (RF08) -----------------

$pruebas[] = ['E1', 'Una cita cancelada no puede pasar a Confirmada', function () {
    global $ctx;

    $cita = reservar($ctx->idPaciente1, FECHA_LUNES, '08:00');
    CitaEstadoService::cancelar($cita['id'], 'Prueba');

    esperarRechazo(fn () => CitaEstadoService::confirmar($cita['id']), 'no se puede pasar');
}];

$pruebas[] = ['E2', 'Una cita atendida no se registra antes de que empiece', function () {
    global $ctx;

    $cita = reservar($ctx->idPaciente1, FECHA_LUNES, '10:30');

    esperarRechazo(
        fn () => CitaEstadoService::marcarAtendida($cita['id'], 1, $ctx->idMedicoUsuario),
        'todavía no comenzó'
    );
}];

$pruebas[] = ['R1', 'Reprogramar mueve la cita y la deja Programada', function () {
    global $ctx;

    $cita = reservar($ctx->idPaciente1, FECHA_LUNES, '10:00');
    CitaEstadoService::reprogramar($cita['id'], FECHA_LUNES, '11:00');
    $fila = CitaEstadoService::obtener($cita['id']);

    afirmar($fila['estado'] === 'Programada', 'Al reprogramar la cita no quedó Programada.');
    afirmar(substr((string) $fila['inicio'], 11, 5) === '11:00', 'La hora no cambió a 11:00.');
}];

$pruebas[] = ['R2', 'Una cita cancelada no se puede reprogramar', function () {
    global $ctx;

    $cita = reservar($ctx->idPaciente1, FECHA_LUNES, '11:30');
    CitaEstadoService::cancelar($cita['id'], 'Prueba');

    esperarRechazo(
        fn () => CitaEstadoService::reprogramar($cita['id'], FECHA_LUNES, '09:30'),
        'Solo se reprograman'
    );
}];

// --- Estados de la persona y de las fichas (RN04, RN11) ----------------------

$pruebas[] = ['M1', 'Un médico inactivo no recibe citas', function () {
    global $ctx;

    MedicoService::cambiarActivo(1, false);

    try {
        esperarRechazo(
            fn () => reservar($ctx->idPaciente2, FECHA_LUNES, '08:00'),
            ['inactivo', 'ya no está disponible']
        );
    } finally {
        MedicoService::cambiarActivo(1, true);
    }
}];

$pruebas[] = ['M2', 'Un paciente inactivo no recibe citas', function () {
    global $ctx;

    PacienteService::cambiarActivo($ctx->idPaciente2, false);

    try {
        esperarRechazo(
            fn () => reservar($ctx->idPaciente2, FECHA_LUNES, '08:00'),
            ['inactivo', 'disponible']
        );
    } finally {
        PacienteService::cambiarActivo($ctx->idPaciente2, true);
    }
}];

$pruebas[] = ['M3', 'La cuenta de un paciente desactivado no puede entrar', function () {
    global $ctx;

    PacienteService::cambiarActivo($ctx->idPaciente1, false);

    try {
        afirmar(
            AutenticacionService::autenticar('paciente', 'Pacien2026') === null,
            'La cuenta de un paciente desactivado sí pudo entrar.'
        );
    } finally {
        PacienteService::cambiarActivo($ctx->idPaciente1, true);
    }

    afirmar(
        AutenticacionService::autenticar('paciente', 'Pacien2026') !== null,
        'Al reactivar al paciente, su cuenta debía volver a entrar.'
    );
}];

// --- Especialidades y horarios ------------------------------------------------

$pruebas[] = ['ESP1', 'Una especialidad repetida se rechaza aunque cambien mayúsculas y espacios', function () {
    esperarRechazo(
        fn () => EspecialidadService::guardar(null, ['nombre' => '  PEDIATRÍA  ', 'descripcion' => '']),
        'Ya existe una especialidad con ese nombre'
    );
}];

$pruebas[] = ['ESP2', 'La normalización de PHP coincide con la de la base de datos', function () {
    $php = ValidadorAgenda::nombreNormalizado('  Medicína   GENERAL ');

    $sql = <<<'SQL'
SELECT btrim(regexp_replace(lower(translate('  Medicína   GENERAL ', 'ÁÉÍÓÚÜÑáéíóúüñ', 'AEIOUUNaeiouun')), '\s+', ' ', 'g'))
SQL;
    $bd = (string) Conexion::obtener()->query($sql)->fetchColumn();

    afirmar($php === 'medicina general', "PHP normalizó a '$php' y se esperaba 'medicina general'.");
    afirmar($php === $bd, "PHP ('$php') y la base ('$bd') normalizan distinto.");
}];

$pruebas[] = ['H1', 'Un bloque de horario que termina antes de empezar se rechaza', function () {
    $errores = ValidadorAgenda::horario(['dia' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '09:00', 'duracion' => 30]);

    afirmar(count($errores) > 0, 'No se detectó que la hora final es anterior a la inicial.');
}];

$pruebas[] = ['H2', 'Un bloque de horario que se cruza con otro del mismo día se rechaza', function () {
    esperarRechazo(
        fn () => HorarioService::agregar(1, ['dia' => 1, 'hora_inicio' => '10:00', 'hora_fin' => '11:00', 'duracion' => 30]),
        ['se cruza', 'VS012']
    );
}];

// --- Integridad: nada se borra físicamente (RN11) ----------------------------

$pruebas[] = ['B1', 'No se puede borrar un paciente, solo desactivarlo', function () {
    global $ctx;

    esperarRechazo(
        fn () => Conexion::obtener()->exec('DELETE FROM paciente WHERE id_paciente = ' . $ctx->idPaciente2),
        'VS008'
    );
}];

// --- Permisos, cuentas y contraseñas -----------------------------------------

$pruebas[] = ['U1', 'El menú del paciente no incluye páginas de administración', function () {
    $paginas = array_column(Roles::menu(Roles::PACIENTE), 'pagina');

    foreach (['usuarios.php', 'reportes.php', 'medicos.php', 'especialidades.php', 'pacientes.php'] as $pagina) {
        afirmar(!in_array($pagina, $paginas, true), "El menú del paciente incluye $pagina.");
    }
}];

$pruebas[] = ['U2', 'Una cuenta con rol Médico sin ficha de médico se rechaza', function () {
    $errores = ValidadorUsuario::usuario([
        'nombre_usuario' => 'doc1',
        'correo' => 'doc1@ejemplo.com',
        'nombres' => 'Ana',
        'apellidos' => 'López',
        'rol' => Roles::MEDICO,
        'id_medico' => 0,
        'id_paciente' => 0,
        'puede_marcar_atendida' => false,
        'clave' => 'Doc2026x',
    ], true);

    afirmar(isset($errores['id_medico']), 'No se rechazó el médico sin ficha.');
}];

$pruebas[] = ['U3', 'Una contraseña débil se rechaza y una válida se acepta', function () {
    afirmar(Validador::clave('solonumeros') !== null, 'Se aceptó una contraseña solo con letras.');
    afirmar(Validador::clave('Clave2026') === null, 'Se rechazó una contraseña válida.');
}];

$pruebas[] = ['U4', 'Cambiar la contraseña exige la actual correcta', function () {
    global $ctx;

    esperarRechazo(
        fn () => UsuarioService::cambiarClave($ctx->idRecepcion, 'ClaveMal2026', 'Nueva2027'),
        'actual no es correcta'
    );
}];

$pruebas[] = ['U5', 'El inicio de sesión acepta solo la contraseña correcta', function () {
    afirmar(AutenticacionService::autenticar('admin', 'Admin2026') !== null, 'El administrador no pudo entrar.');
    afirmar(AutenticacionService::autenticar('admin', 'otra-clave') === null, 'Entró con una contraseña incorrecta.');
}];

// --- Reglas de fecha (RN06) ---------------------------------------------------

$pruebas[] = ['V1', 'No se puede reservar en una fecha pasada', function () {
    afirmar(ValidadorCita::fecha('2020-01-01') !== null, 'Se aceptó una fecha pasada.');
}];

// ---------------------------------------------------------------------------
// Ejecución y resultado
// ---------------------------------------------------------------------------

// --- Activación y recuperación por correo -----------------------------------

$pruebas[] = ['C1', 'El enlace define la contraseña y no sirve una segunda vez', function () {
    $id = crearUsuarioDePrueba('correo.uno');
    $token = CuentaCorreoService::crearToken($id, 'activacion');

    CuentaCorreoService::definirClave($token, 'Nueva2027');

    afirmar(AutenticacionService::autenticar('correo.uno', 'Nueva2027') !== null,
        'La contraseña definida por el enlace no sirve para entrar.');

    esperarRechazo(fn () => CuentaCorreoService::definirClave($token, 'Otra2028'), 'no es válido');
}];

$pruebas[] = ['C2', 'Un enlace vencido no define la contraseña', function () {
    $id = crearUsuarioDePrueba('correo.dos');
    $token = CuentaCorreoService::crearToken($id, 'activacion');

    Conexion::obtener()
        ->prepare("UPDATE token_usuario SET expira = now() - interval '1 day' WHERE id_usuario = :id")
        ->execute([':id' => $id]);

    esperarRechazo(fn () => CuentaCorreoService::definirClave($token, 'Nueva2027'), 'no es válido');
}];

$pruebas[] = ['C3', 'Una contraseña débil se rechaza sin gastar el enlace', function () {
    $id = crearUsuarioDePrueba('correo.tres');
    $token = CuentaCorreoService::crearToken($id, 'recuperacion');

    $rechazada = false;
    try {
        CuentaCorreoService::definirClave($token, 'solonumeros');
    } catch (ReglaException $e) {
        $rechazada = true;
    }

    afirmar($rechazada, 'Se aceptó una contraseña sin números y letras.');
    afirmar(CuentaCorreoService::validarToken($token) !== null,
        'La contraseña débil gastó el enlace; debía seguir sirviendo.');
}];

// --- Paquete 1: ficha médica, menú del administrador y permisos -------------

$pruebas[] = ['F1', 'El administrador tiene el menú de pacientes', function () {
    $paginas = array_column(Roles::menu(Roles::ADMINISTRADOR), 'pagina');
    afirmar(in_array('pacientes.php', $paginas, true), 'El menú del administrador no incluye Pacientes.');
}];

$pruebas[] = ['F2', 'La ficha médica guarda los datos y convierte los campos vacíos en nulos', function () {
    global $ctx;

    PortalPacienteService::actualizarFicha($ctx->idPaciente1, [
        'tipo_sangre' => 'O+',
        'alergias' => 'Penicilina',
        'enfermedades_cronicas' => '',
        'medicamentos' => '',
        'antecedentes' => '',
        'contacto_emergencia_nombre' => 'Ana Ruiz',
        'contacto_emergencia_parentesco' => 'Madre',
        'contacto_emergencia_telefono' => '70000000',
        'recibir_notificaciones' => true,
        'compartir_historial_medico' => false,
    ]);

    $ficha = PortalPacienteService::ficha($ctx->idPaciente1);
    afirmar($ficha['tipo_sangre'] === 'O+', 'El tipo de sangre no se guardó.');
    afirmar($ficha['alergias'] === 'Penicilina', 'Las alergias no se guardaron.');
    afirmar($ficha['enfermedades_cronicas'] === null, 'Un campo vacío debía quedar como NULL.');
    afirmar((int) $ficha['recibir_notificaciones'] === 1, 'La preferencia de notificaciones no se guardó.');
}];

$pruebas[] = ['F3', 'Un contacto de emergencia incompleto se rechaza', function () {
    $errores = ValidadorPortal::ficha([
        'tipo_sangre' => '', 'alergias' => '', 'enfermedades_cronicas' => '', 'medicamentos' => '', 'antecedentes' => '',
        'contacto_emergencia_nombre' => 'Ana Ruiz',
        'contacto_emergencia_parentesco' => '',
        'contacto_emergencia_telefono' => '',
    ]);

    afirmar(isset($errores['contacto_emergencia_parentesco']), 'No se pidió el parentesco.');
    afirmar(isset($errores['contacto_emergencia_telefono']), 'No se pidió el teléfono de emergencia.');
}];

$pruebas[] = ['F4', 'Por defecto, ningún médico ve el motivo de las citas', function () {
    global $ctx;

    afirmar(AdministracionUsuariosService::puedeVerMotivo($ctx->idMedicoUsuario) === false,
        'El médico vio el motivo sin tener el permiso.');
}];

// --- Paquete 2: modalidad de la cita y registro propio del paciente ----------

$pruebas[] = ['G1', 'La modalidad virtual de una cita se guarda', function () {
    global $ctx;

    $cita = CitaService::registrar([
        'paciente' => $ctx->idPaciente1, 'especialidad' => 1, 'medico' => 1,
        'fecha' => FECHA_LUNES, 'hora' => '08:30', 'motivo' => 'Consulta virtual', 'modalidad' => 'Virtual',
    ], $ctx->idRecepcion);

    $modalidad = Conexion::obtener()
        ->query('SELECT modalidad FROM cita WHERE id_cita = ' . (int) $cita['id'])
        ->fetchColumn();
    afirmar($modalidad === 'Virtual', 'La modalidad no se guardó como Virtual.');
}];

$pruebas[] = ['G2', 'Una modalidad que no es presencial ni virtual se rechaza', function () {
    global $ctx;

    esperarRechazo(fn () => CitaService::registrar([
        'paciente' => $ctx->idPaciente1, 'especialidad' => 1, 'medico' => 1,
        'fecha' => FECHA_LUNES, 'hora' => '09:30', 'modalidad' => 'Mixta',
    ], $ctx->idRecepcion), 'modalidad');
}];

$pruebas[] = ['G3', 'El registro propio crea la ficha y la cuenta vinculadas', function () {
    $resultado = RegistroPacienteService::registrar(array_merge(
        datosPaciente('9900888', 'Lucia', 'Mamani'),
        ['nombre_usuario' => 'lucia.mamani', 'correo' => 'lucia.mamani@ejemplo.com']
    ));

    $usuario = Conexion::obtener()
        ->query('SELECT id_paciente, id_rol FROM usuario WHERE id_usuario = ' . (int) $resultado['id_usuario'])
        ->fetch();
    afirmar((int) $usuario['id_paciente'] === $resultado['id_paciente'], 'La cuenta no quedó vinculada a la ficha.');
    afirmar((int) $usuario['id_rol'] === Roles::PACIENTE, 'La cuenta nueva no tiene el rol Paciente.');
}];

$pruebas[] = ['G4', 'Un registro con una CI que ya tiene cuenta se rechaza', function () {
    global $ctx;

    $ci = PacienteService::obtener($ctx->idPaciente1)['ci'];

    esperarRechazo(fn () => RegistroPacienteService::registrar(array_merge(
        datosPaciente((string) $ci, 'Otra', 'Persona'),
        ['nombre_usuario' => 'otra.persona', 'correo' => 'otra.persona@ejemplo.com']
    )), 'Ya existe una cuenta para esa CI');
}];

$pruebas[] = ['G5', 'Una ficha de Recepción sin cuenta solo se vincula con el mismo correo', function () {
    global $ctx;

    $ci = Conexion::obtener()
        ->query('SELECT ci FROM paciente WHERE id_paciente = ' . (int) $ctx->idPaciente2)
        ->fetchColumn();

    esperarRechazo(fn () => RegistroPacienteService::registrar(array_merge(
        datosPaciente((string) $ci, 'Luis', 'Herrera'),
        ['nombre_usuario' => 'luis.herrera', 'correo' => 'nadie@ejemplo.com']
    )), 'no coinciden');
}];

$pruebas[] = ['G6', 'Un médico no se puede reservar con una especialidad que no atiende', function () {
    global $ctx;

    $otra = Conexion::obtener()
        ->query('SELECT e.id_especialidad FROM especialidad e
                  WHERE e.activo AND e.id_especialidad NOT IN
                        (SELECT id_especialidad FROM medico_especialidad WHERE id_medico = 1)
                  ORDER BY e.id_especialidad LIMIT 1')
        ->fetchColumn();
    afirmar($otra !== false, 'No hay una especialidad activa que el médico 1 no atienda.');

    esperarRechazo(fn () => CitaService::registrar([
        'paciente' => $ctx->idPaciente2, 'especialidad' => (int) $otra, 'medico' => 1,
        'fecha' => FECHA_LUNES, 'hora' => '08:00', 'modalidad' => 'Presencial',
    ], $ctx->idRecepcion), 'no atiende');
}];

$pruebas[] = ['G7', 'El horario de atención de un médico se puede consultar', function () {
    $bloques = CitaService::horariosDelMedico(1);
    afirmar(count($bloques) >= 2, 'El médico 1 debería tener al menos dos bloques de atención.');
}];

// --- Paquete 3: recordatorios y ficha médica compartida ----------------------

$pruebas[] = ['I1', 'Una cita próxima de un paciente que acepta recordatorios aparece como pendiente', function () {
    $idCita = (int) Conexion::obtener()
        ->query("SELECT id_cita FROM cita WHERE modalidad = 'Virtual' AND estado = 'Programada' ORDER BY id_cita LIMIT 1")
        ->fetchColumn();
    afirmar($idCita > 0, 'No hay una cita virtual programada para probar.');

    $ids = array_map(static fn (array $c): int => (int) $c['id_cita'],
        RecordatorioService::pendientes(new DateTimeImmutable('2027-03-02 00:00:00')));
    afirmar(in_array($idCita, $ids, true), 'La cita próxima no aparece entre los recordatorios pendientes.');
}];

$pruebas[] = ['I2', 'Un recordatorio ya enviado no vuelve a aparecer como pendiente', function () {
    $idCita = (int) Conexion::obtener()
        ->query("SELECT id_cita FROM cita WHERE modalidad = 'Virtual' AND estado = 'Programada' ORDER BY id_cita LIMIT 1")
        ->fetchColumn();
    RecordatorioService::marcarEnviado($idCita);

    $ids = array_map(static fn (array $c): int => (int) $c['id_cita'],
        RecordatorioService::pendientes(new DateTimeImmutable('2027-03-02 00:00:00')));
    afirmar(!in_array($idCita, $ids, true), 'El recordatorio ya enviado sigue pendiente.');
}];

$pruebas[] = ['I3', 'El médico ve la ficha solo con cita y con permiso del paciente', function () {
    global $ctx;

    Conexion::obtener()
        ->prepare('UPDATE paciente SET compartir_historial_medico = TRUE WHERE id_paciente = :id')
        ->execute([':id' => $ctx->idPaciente1]);

    $ficha = HistorialMedicoService::fichaParaMedico(1, $ctx->idPaciente1);
    afirmar($ficha !== null && $ficha['alergias'] === 'Penicilina',
        'El médico con cita no ve la ficha que el paciente comparte.');
    afirmar(HistorialMedicoService::fichaParaMedico(2, $ctx->idPaciente1) === null,
        'Un médico sin cita con el paciente vio la ficha.');

    Conexion::obtener()
        ->prepare('UPDATE paciente SET compartir_historial_medico = FALSE WHERE id_paciente = :id')
        ->execute([':id' => $ctx->idPaciente1]);
    afirmar(HistorialMedicoService::fichaParaMedico(1, $ctx->idPaciente1) === null,
        'Sin permiso del paciente, el médico vio la ficha.');
}];

$pasaron = 0;

foreach ($pruebas as [$codigo, $descripcion, $prueba]) {
    try {
        $prueba();
        $pasaron++;
        echo "[OK]    $codigo  $descripcion\n";
    } catch (\Throwable $e) {
        echo "[FALLO] $codigo  $descripcion\n        " . $e->getMessage() . "\n";
    }
}

$total = count($pruebas);
echo "\n$pasaron de $total pruebas pasaron\n";

exit($pasaron === $total ? 0 : 1);
