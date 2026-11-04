# Índice del proyecto

Este documento describe qué hace cada carpeta y cada archivo del proyecto.
El código está organizado en capas: **datos**, **seguridad**, **servicios**, **validación**,
**vistas** y **páginas públicas**. Cada capa solo llama a la de abajo.

## Raíz

| Archivo | Función |
|---|---|
| `README.md` | Descripción, instalación, usuarios de demostración y cómo ejecutar las pruebas. |
| `.gitignore` | Excluye de GitHub los archivos privados, como `config/local.php`. |
| `.htaccess` | Bloquea el acceso desde el navegador a `app`, `config`, `sql`, `tests` y `docs`. |

## app/Datos: acceso a la base de datos

| Archivo | Función |
|---|---|
| `Conexion.php` | Conexión PDO única a PostgreSQL. Fija la zona horaria de PHP y de la base. Permite usar la base de pruebas. |
| `ErroresBD.php` | Traduce los errores de la base (disparadores VS001 a VS012, restricción de superposición 23P01 y duplicados 23505) en mensajes claros para el usuario. |

## app/Seguridad: sesión y roles

| Archivo | Función |
|---|---|
| `Sesion.php` | Inicio de sesión seguro: cookies HttpOnly y SameSite, regeneración del identificador y token CSRF. |
| `Roles.php` | Nombres de los roles (1 a 4), menú de cada rol y comprobación de permisos (RN12). |

## app/Servicios: reglas de negocio

| Archivo | Función |
|---|---|
| `AdministracionUsuariosService.php` | Alta, modificación y activación de cuentas. Una ficha de médico o de paciente solo puede tener una cuenta. |
| `AgendaService.php` | Consulta de citas por fecha, médico y estado. Define la lista de estados válidos. |
| `AutenticacionService.php` | Inicio de sesión por usuario o correo. Rechaza cuentas desactivadas y fichas desactivadas. |
| `CitaEstadoService.php` | Máquina de estados de las citas: confirmar, cancelar, reprogramar, marcar atendida o inasistencia. |
| `CitaService.php` | Horas libres del médico y registro de citas con bloqueo de fila (`FOR UPDATE`). |
| `EspecialidadService.php` | Alta y modificación de especialidades, sin repetir nombres normalizados. |
| `HorarioService.php` | Bloques de horario de cada médico, sin cruces dentro de un mismo día. |
| `MedicoService.php` | Alta, modificación y desactivación de médicos y sus especialidades, en una sola transacción. |
| `PacienteService.php` | Alta, modificación, búsqueda, desactivación e historial de citas de pacientes. |
| `PortalPacienteService.php` | Datos de contacto que el paciente puede cambiar desde su portal. |
| `ReglaException.php` | Excepción de negocio cuyo mensaje sí se muestra al usuario. |
| `ReporteService.php` | Totales por estado, citas por médico y especialidad, pacientes registrados y detalle para el CSV. |
| `UsuarioService.php` | Cambio de contraseña: verifica la actual y no permite reutilizarla. |

## app/Validacion: revisión de datos de entrada

| Archivo | Función |
|---|---|
| `Validador.php` | Reglas comunes: CI, teléfono, correo, nombres, fecha de nacimiento, hora y contraseña. |
| `ValidadorAgenda.php` | Datos de médicos, especialidades y horarios. Normaliza nombres de especialidad. |
| `ValidadorCita.php` | Datos de una cita nueva: paciente, especialidad, médico, fecha, hora y motivo. |
| `ValidadorGestionCita.php` | Datos de cancelación y reprogramación. |
| `ValidadorPortal.php` | Datos del portal del paciente: contacto y cambio de contraseña. |
| `ValidadorReporte.php` | Filtros de reportes: fechas válidas y rango coherente. |
| `ValidadorUsuario.php` | Datos de cuentas: nombre de usuario, correo, rol y vínculo obligatorio con su ficha. |

## app/Vistas: presentación común

| Archivo | Función |
|---|---|
| `Plantilla.php` | Encabezado, menú según el rol, pie de página y escape de HTML (`Plantilla::e`). |

## app/arranque.php

Carga las clases automáticamente, oculta los errores al usuario y los registra en el servidor.

## config

| Archivo | Función |
|---|---|
| `app.php` | Lee `local.php` y devuelve la configuración de la base y la zona horaria. |
| `local.ejemplo.php` | Plantilla de configuración sin contraseñas. Esta sí se sube a GitHub. |

`local.php` (con la contraseña real) se crea en tu computadora y **no se sube**.

## public: páginas

**Acceso y cuenta**

| Página | Función |
|---|---|
| `login.php` | Pantalla de inicio de sesión. |
| `logout.php` | Cierra la sesión. |
| `inicio.php` | Panel de inicio según el rol. |
| `cambiar_clave.php` | Cambio de contraseña para todos los roles. |

**Administrador**

| Página | Función |
|---|---|
| `medicos.php` | Lista de médicos: buscar, activar y desactivar. |
| `medico_form.php` | Registrar o modificar un médico y sus especialidades. |
| `medico_horarios.php` | Agregar y eliminar bloques de horario de un médico. |
| `especialidades.php` | Alta, modificación y desactivación de especialidades. |
| `usuarios.php` | Lista de cuentas: activar y desactivar. |
| `usuario_form.php` | Crear o modificar una cuenta, con su rol y su ficha vinculada. |
| `reportes.php` | Reportes por periodo, con descarga en CSV. |

**Recepcionista**

| Página | Función |
|---|---|
| `pacientes.php` | Lista de pacientes con búsqueda por CI, nombre o apellido. |
| `paciente_form.php` | Registrar o modificar un paciente. |
| `paciente_estado.php` | Activar o desactivar un paciente (solo por POST). |
| `paciente_historial.php` | Todas las citas de un paciente. |
| `citas.php` | Listado de citas con filtros y acciones según el estado. |
| `cita_nueva.php` | Reserva en dos pasos: primero se elige la hora libre, luego se confirma el paciente. |
| `cita_reprogramar.php` | Reprogramación en dos pasos. |
| `cita_accion.php` | Confirmar, cancelar con motivo o registrar inasistencia (solo por POST). |
| `agenda.php` | Agenda de todos los médicos, solo de lectura. |

**Médico**

| Página | Función |
|---|---|
| `mi_agenda.php` | Agenda del médico: solo sus citas. Marca como atendida si tiene el permiso. |

**Paciente**

| Página | Función |
|---|---|
| `mis_citas.php` | Historial de citas del paciente, según su sesión. |
| `mis_datos.php` | Ver su ficha y editar teléfono, correo y dirección. |

**Recursos**

| Archivo | Función |
|---|---|
| `assets/css/estilos.css` | Diseño general: colores, tipografía y menú. |
| `assets/css/responsivo.css` | Ajustes para celular: áreas de toque, foco visible y tablas que se desplazan. |
| `assets/js/menu.js` | Abre y cierra el menú en celular. |
| `assets/js/validacion.js` | Evita el doble envío de formularios y respeta los campos obligatorios. |

## sql

| Archivo | Función |
|---|---|
| `schema.sql` | Tablas, restricciones (incluida la que impide citas superpuestas) y datos de demostración. |
| `triggers.sql` | Disparadores VS001 a VS012 que aplican las reglas de negocio dentro de la base. |

## tests

| Archivo | Función |
|---|---|
| `run_tests.php` | 30 pruebas automáticas (P1 a P10 y reglas de negocio) sobre la base `citas_clinica_pruebas`. |
| `checklist_manual.md` | Lista de comprobación manual: acceso sin sesión, pantallas, celular y CSV. |
| `conexion.php` | Prueba rápida de conexión: muestra los roles de la base y la zona horaria. |

## docs

| Archivo | Función |
|---|---|
| `INDICE.md` | Este documento. |
| `GUIA_GITHUB_QR.md` | Cómo subir el proyecto a GitHub y generar el código QR del repositorio. |
| `vista_previa_diseno_v2.html` | Vista previa del diseño aprobado, sin conexión a la base. |
