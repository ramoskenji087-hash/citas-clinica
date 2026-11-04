# Lista de comprobación manual

Las pruebas automáticas (`php tests\run_tests.php`) cubren la lógica de negocio y la base de datos.
Lo que sigue necesita el navegador. Marque cada casilla cuando la haya comprobado.

Antes de empezar: inicie Apache y PostgreSQL en XAMPP, y abra `http://localhost/citas-clinica/public/login.php`.

## P1 a P10 en el navegador

- [ ] **P1 Registrar paciente.** Entre como `recepcion` / `Recep2026`. En *Pacientes*, pulse *Nuevo paciente*, llene los datos y guarde. El paciente aparece en la lista.
- [ ] **P2 CI duplicada.** Registre otro paciente con una CI ya existente (la de María López). Debe aparecer: *Ya existe un paciente con ese CI.*
- [ ] **P3 Cita disponible.** En *Nueva cita*, elija paciente, especialidad, médico, fecha (un lunes futuro) y una hora libre. Debe quedar en estado *Programada* con código `CM-000x`.
- [ ] **P4 Cita superpuesta.** Intente reservar la misma hora con el mismo médico. No debe aparecer en las horas disponibles, y si se envía igual, se rechaza.
- [ ] **P5 Fuera de horario.** Intente reservar un domingo. No debe haber horas disponibles.
- [ ] **P6 Cancelar.** En *Citas*, cancele una cita con motivo. Debe quedar *Cancelada* y seguir visible en el historial del paciente.
- [ ] **P7 Historial del paciente.** En *Pacientes*, abra el historial de un paciente. Aparecen todas sus citas, incluidas las canceladas.
- [ ] **P8 Agenda del médico.** Entre como `medico` / `Medico2026`. En *Mi agenda*, elija la fecha. Solo aparecen sus propias citas.
- [ ] **P9 Acceso sin sesión.** Cierre sesión. Abra en el navegador `http://localhost/citas-clinica/public/citas.php`. Debe redirigir a *login.php*, y no mostrar datos.
- [ ] **P10 Datos inválidos.** En cualquier formulario, deje vacío un campo obligatorio y escriba una CI con letras. Debe aparecer un resumen de errores al inicio del formulario, con enlaces a cada campo.

## Acceso por rol

- [ ] `recepcion`: no ve *Usuarios* ni *Reportes* en el menú. Si escribe la dirección de `usuarios.php`, aparece *No tiene permiso para ver esta página.*
- [ ] `medico`: solo ve sus propias citas. No puede marcar como atendida una cita futura.
- [ ] `paciente`: ve *Mis citas* y *Mis datos*. Puede cambiar su contraseña. No ve pacientes de otros.
- [ ] `admin`: entra a *Usuarios*, *Reportes* y *Especialidades*.

## Usuarios y cuentas (bloque 8)

- [ ] Crear una cuenta nueva y entrar con ella.
- [ ] Desactivar a un paciente que tiene cuenta. Esa cuenta ya no puede entrar.
- [ ] Al editar su propia cuenta, el administrador no tiene el botón *Desactivar*.

## Reportes (bloque 8)

- [ ] El reporte del mes muestra totales por estado, por médico y por especialidad.
- [ ] Con un solo día en *Desde* y *Hasta*, aparece la agenda diaria agrupada por médico.
- [ ] *Descargar CSV* abre en Excel con las tildes correctas y columnas separadas por punto y coma.

## Diseño y uso en celular

- [ ] Ventana de 1280 px: el menú horizontal se ve completo.
- [ ] Ventana menor de 900 px (o celular): aparece el botón *Menú* y despliega las opciones.
- [ ] Ningún formulario ni tabla obliga a desplazarse de lado.
- [ ] El texto se lee a 16 px o más, con buen contraste.

## Antes de entregar

- [ ] `php tests\run_tests.php` muestra "30 de 30 pruebas pasaron" (o el número actual).
- [ ] `config\local.php` no aparece en GitHub.
- [ ] La base real `citas_clinica` sigue con sus datos; las pruebas solo usan `citas_clinica_pruebas`.
