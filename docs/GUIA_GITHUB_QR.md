# Guía: subir el proyecto a GitHub y generar el código QR

Repositorio: `https://github.com/ramoskenji087-hash/citas-clinica`

Esta guía usa la consola de Windows (cmd). Todos los comandos se ejecutan dentro de la carpeta del proyecto.

## Antes de empezar

**1. Instalar Git**

Descarga e instala **Git for Windows** desde `https://git-scm.com`. Deja las opciones por defecto.

**2. Verificar que el archivo privado no se va a subir**

El archivo `config\local.php` tiene la contraseña de la base de datos. El `.gitignore` ya lo excluye,
pero conviene comprobarlo siempre antes de subir cambios.

## Paso 1: Abrir la consola en la carpeta del proyecto

```
cd /d G:\general_to_me\xampp\htdocs\citas-clinica
```

## Paso 2: Configurar tu identidad (solo la primera vez)

```
git config --global user.name "Tu nombre"
git config --global user.email "tu-correo@ejemplo.com"
```

Usa el mismo correo que tienes en tu cuenta de GitHub.

## Paso 3: Preparar el repositorio local

Si la carpeta **no** tiene una carpeta oculta llamada `.git`, créala con:

```
git init
```

Si ya existe, omite este paso.

## Paso 4: Seleccionar los archivos

```
git add .
```

Luego revisa la lista de archivos que se van a subir:

```
git status
```

**Verifica** que no aparezca `config/local.php`. Para confirmarlo, escribe:

```
git ls-files | findstr local.php
```

La consola no debe mostrar ninguna línea. Solo debe aparecer `local.ejemplo.php`, que sí se sube.

## Paso 5: Guardar los cambios

```
git commit -m "Versión final: sistema de gestión de citas médicas"
```

## Paso 6: Conectar con GitHub

```
git branch -M main
git remote add origin https://github.com/ramoskenji087-hash/citas-clinica.git
```

Si el comando `remote add` responde que `origin` ya existe, usa este en su lugar:

```
git remote set-url origin https://github.com/ramoskenji087-hash/citas-clinica.git
```

## Paso 7: Subir el proyecto

```
git push -u origin main
```

Git te pedirá iniciar sesión en GitHub. Si el navegador abre una ventana para autorizar, acepta.
Si te pide una contraseña, **no** uses la contraseña de tu cuenta: GitHub ya no la acepta para esto.
Crea un **token de acceso personal** en GitHub (Settings → Developer settings → Personal access tokens)
y úsalo como contraseña.

## Paso 8: Comprobar en GitHub

Abre `https://github.com/ramoskenji087-hash/citas-clinica` en el navegador y verifica que:

- Aparecen las carpetas `app`, `config`, `public`, `sql`, `tests` y `docs`.
- El `README.md` se muestra en la página principal.
- **No** aparece `config/local.php`.

## Generar el código QR

El QR lleva a la página del repositorio. Así, quien lo escanee con el celular llega directamente al proyecto.

**1.** Copia este enlace:

```
https://github.com/ramoskenji087-hash/citas-clinica
```

**2.** Busca en Google **"generador de código QR"** y abre cualquier sitio de confianza.

**3.** Pega el enlace en el campo de texto o de URL y genera el código.

**4.** Descarga la imagen en formato PNG.

**5.** Escanea la imagen con la cámara del celular para comprobar que abre el repositorio.

**6.** Inserta la imagen en tu documento de entrega, con el texto: *"Repositorio del proyecto"*.

## Si algo falla

| Mensaje | Qué hacer |
|---|---|
| `fatal: not a git repository` | Vuelve al paso 3 y ejecuta `git init`. |
| `Permission denied` o `Authentication failed` | Usa un token de acceso personal como contraseña (paso 7). |
| `failed to push some refs` | Ejecuta `git pull origin main --allow-unrelated-histories` y luego `git push -u origin main`. |
| Aparece `config/local.php` en `git status` | Revisa que `.gitignore` contenga la línea `config/local.php` y vuelve a ejecutar `git add .`. |
