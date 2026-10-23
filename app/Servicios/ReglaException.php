<?php
declare(strict_types=1);

namespace App\Servicios;

/**
 * Error de regla de negocio cuyo mensaje sí se muestra al usuario tal cual.
 * Ejemplo: "Ya existe un paciente con ese CI".
 *
 * Los demás errores (base caída, consulta mal formada) no usan esta clase:
 * se registran en el log del servidor y el usuario recibe un mensaje genérico.
 */
final class ReglaException extends \DomainException
{
}
