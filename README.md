# Clínica Vida Salud: Sistema de gestión de citas médicas

Aplicación web para que una clínica registre pacientes, médicos y especialidades, y administre
las citas: reservar, confirmar, cancelar, reprogramar y registrar la atención o la inasistencia.
Cada rol ve solo lo que le corresponde.

Proyecto del examen parcial de la materia **Ingeniería de Requisitos**
(docente: MSc. Ing. Angélica Gazón Cuéllar).

## Tecnologías

- PHP 8.2, sin frameworks (PDO y páginas PHP organizadas en capas)
- PostgreSQL 17, con restricciones y disparadores que protegen las reglas de negocio
- XAMPP en Windows para ejecutarlo en local
- HTML, CSS y JavaScript simple, con diseño adaptable para celular

## Roles y funciones

| Rol | Qué puede hacer |
|---|---|
| Administrador | Médicos, horarios, especialidades, usuarios y reportes. Cambiar su contraseña. |
| Recepcionista | Pacientes, citas (crear, confirmar, cancelar, reprogramar, inasistencia) y agenda de los médicos. |
| Médico | Su propia agenda. Marcar como atendida la cita de la que es responsable, si tiene el permiso. |
| Paciente | Sus citas y sus datos de contacto. Cambiar su contraseña. |

## Requisitos

- Windows 10 u 11
- XAMPP con PHP 8.2, con la extensión `pdo_pgsql` activada
- PostgreSQL 17, en la misma computadora
- Un navegador moderno (Chrome, Edge o Firefox)

## Instalación

**1. Copiar el proyecto**

Copia la carpeta `citas-clinica` dentro de `htdocs` de XAMPP:

```
G:\general_to_me\xampp\htdocs\citas-clinica
```

**2. Crear la base de datos**

En pgAdmin, crea una base de datos llamada exactamente `citas_clinica`.

**3. Crear las tablas y los datos de demostración**

En pgAdmin, abre el *Query Tool* sobre la base `citas_clinica` y ejecuta, en este orden:

1. `sql/schema.sql` (tablas, datos de demostración y restricciones)
2. `sql/triggers.sql` (disparadores que aplican las reglas de negocio)

**4. Configurar la conexión**

Copia `config/local.ejemplo.php` a `config/local.php` y completa estos valores:

- `db_clave`: la contraseña de tu usuario de PostgreSQL
- `zona_horaria`: `America/Argentina/Buenos_Aires` (o la de tu país)

El archivo `config/local.php` contiene datos privados y **no debe subirse a GitHub**. El archivo `.gitignore` ya lo excluye.

**5. Iniciar el sistema**

Inicia Apache y PostgreSQL en XAMPP, y abre en el navegador:

```
http://localhost/citas-clinica/public/login.php
```

## Usuarios de demostración

| Usuario | Contraseña | Rol |
|---|---|---|
| `admin` | `Admin2026` | Administrador |
| `recepcion` | `Recep2026` | Recepcionista |
| `medico` | `Medico2026` | Médico (Juan Pérez) |
| `paciente` | `Pacien2026` | Paciente (María López) |

Estas contraseñas son solo para demostrar el sistema. Antes de usarlo con datos reales, cámbialas.

## Pruebas

Desde la carpeta del proyecto:

```
php tests\run_tests.php
```

El script reinstala la base `citas_clinica_pruebas` y ejecuta 30 pruebas automáticas:
los requisitos P1 a P10 y las reglas de negocio. La base real `citas_clinica` no se modifica.

Las pruebas que requieren el navegador (por ejemplo, el acceso a una página sin sesión)
están en `tests/checklist_manual.md`.

## Seguridad

- Las contraseñas se guardan con `password_hash` (bcrypt), nunca en texto plano.
- Todas las consultas a la base usan sentencias preparadas (PDO), para evitar la inyección SQL.
- Los formularios tienen token CSRF, y la sesión usa cookies `HttpOnly` y `SameSite`.
- Ningún registro se borra físicamente: se desactiva. La base lo impide con un disparador.
- Las carpetas `app`, `config`, `sql`, `tests` y `docs` están bloqueadas desde el navegador por `.htaccess`.
- Los mensajes de error al usuario no muestran credenciales ni detalles internos.

## Alcance

Incluido: gestión de usuarios, pacientes, médicos, especialidades y horarios; citas con sus estados;
reprogramación y cancelación con motivo; agenda por médico; portal del paciente; reportes con descarga en CSV.

No incluido: registro público de pacientes, reserva en línea sin recepción, historial clínico,
recordatorios por correo o SMS, pagos, aplicación móvil nativa y calendario de feriados.

## Estructura

La descripción de cada carpeta y archivo está en [`docs/INDICE.md`](docs/INDICE.md).

## Autoría

Proyecto académico. Ver `docs/INDICE.md` para el detalle de cada módulo.
