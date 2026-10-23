<?php
/**
 * PLANTILLA DE CONFIGURACIÓN LOCAL
 *
 * Pasos para usarla:
 *   1. Copie este archivo dentro de la carpeta config/ y llámelo local.php
 *   2. En la copia, cambie los valores marcados con CAMBIAR.
 *
 * config/local.php contiene su clave real y NO se sube a GitHub (.gitignore lo excluye).
 * Este archivo, en cambio, sí se sube, porque no contiene claves reales.
 */
return [
    // Servidor y base de datos PostgreSQL
    'db_host'    => 'localhost',
    'db_puerto'  => '5432',
    'db_nombre'  => 'citas_clinica',
    'db_usuario' => 'postgres',
    'db_clave'   => 'CAMBIAR_POR_SU_CLAVE_DE_POSTGRES',

    // Zona horaria. Debe ser la misma en PHP y en PostgreSQL.
    // Ejemplos: 'America/Bogota', 'America/Argentina/Buenos_Aires', 'Europe/Madrid'.
    // Lista completa: https://www.php.net/manual/es/timezones.php
    'zona_horaria' => 'CAMBIAR_POR_SU_ZONA_HORARIA',
];
