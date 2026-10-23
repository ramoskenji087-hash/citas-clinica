<?php
declare(strict_types=1);

namespace App\Vistas;

use App\Seguridad\Roles;
use App\Seguridad\Sesion;

/**
 * Plantilla común de todas las páginas (capa de presentación).
 *
 * Cada página llama a inicio() al principio y a fin() al final, así el diseño
 * es el mismo en todo el sistema: menú superior en computadora y botón "Menú"
 * en celular. El menú cambia según el rol del usuario (RN12).
 */
final class Plantilla
{
    /**
     * Escapa texto para mostrarlo en HTML.
     * Úsela siempre que imprima datos de la base de datos o datos que escribió el usuario.
     */
    public static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Imprime el encabezado de la página.
     *
     * @param bool $conMenu false en la pantalla de acceso, donde todavía no hay sesión.
     */
    public static function inicio(string $titulo, bool $conMenu = true): void
    {
        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $usuario = Sesion::usuarioActual();

        $menu = ($conMenu && $usuario !== null) ? Roles::menu((int) $usuario['id_rol']) : [];
        $actual = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $items = self::listaMenu($menu, $actual);

        $sistema = self::e($config['nombre_sistema']);
        $tituloPagina = self::e($titulo);
        $rol = $usuario !== null ? self::e(Roles::nombre((int) $usuario['id_rol'])) : '';

        $cabecera = '<a class="marca" href="inicio.php"><span class="marca-signo" aria-hidden="true">+</span>'
            . $sistema . '</a>';

        if ($menu !== []) {
            $cabecera .= '
  <nav class="nav-escritorio" aria-label="Principal"><ul>' . $items . '</ul></nav>
  <div class="sesion"><span class="rol">' . $rol . '</span><a href="logout.php">Salir</a></div>
  <button class="boton-menu" id="btn-menu" aria-expanded="false" aria-controls="nav-movil">Menú</button>
  <nav class="nav-movil" id="nav-movil" aria-label="Menú móvil"><ul>' . $items
                . '</ul><a href="logout.php">Salir</a></nav>';
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$tituloPagina} - {$sistema}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/estilos.css">
</head>
<body>
<header class="cabecera">
  {$cabecera}
</header>
<main class="pagina">

HTML;
    }

    /** Cierra la página: el contenido de main, el script del menú y el pie. */
    public static function fin(): void
    {
        echo "</main>\n<script src=\"assets/js/menu.js\"></script>\n</body>\n</html>\n";
    }

    /**
     * Convierte el menú de un rol en elementos de lista.
     * La opción de la página actual lleva la clase "activa" y aria-current.
     *
     * @param array<int, array{texto: string, pagina: string}> $menu
     */
    private static function listaMenu(array $menu, string $actual): string
    {
        $html = '';

        foreach ($menu as $opcion) {
            $esActiva = $opcion['pagina'] === $actual;

            $html .= '<li><a href="' . self::e($opcion['pagina']) . '"'
                . ($esActiva ? ' class="activa" aria-current="page"' : '') . '>'
                . self::e($opcion['texto']) . '</a></li>';
        }

        return $html;
    }
}
