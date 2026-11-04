<?php
declare(strict_types=1);

namespace App\Validacion;

/**
 * Validación de las acciones del portal (RF01, RF02, RF12): datos de contacto del paciente
 * y cambio de contraseña para cualquier rol.
 *
 * Cada método devuelve un arreglo de errores por campo. Si está vacío, los datos son válidos.
 */
final class ValidadorPortal
{
    /**
     * Datos de contacto que el paciente puede cambiar: teléfono (obligatorio), correo y dirección.
     * El teléfono debe llegar ya limpio de espacios y guiones.
     *
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function contacto(array $d): array
    {
        $errores = [];

        $telefono = Validador::telefono((string) $d['telefono']);
        if ($telefono !== null) {
            $errores['telefono'] = $telefono;
        }

        $correo = Validador::correo((string) $d['correo']);
        if ($correo !== null) {
            $errores['correo'] = $correo;
        }

        $direccion = Validador::direccion((string) $d['direccion']);
        if ($direccion !== null) {
            $errores['direccion'] = $direccion;
        }

        return $errores;
    }

    /**
     * Nueva contraseña: mínimo 8 caracteres con letras y números (RF01), y que coincida
     * con la repetición.
     *
     * @return array<string, string>
     */
    public static function cambioClave(string $actual, string $nueva, string $repetir): array
    {
        $errores = [];

        if ($actual === '') {
            $errores['clave_actual'] = 'Escriba su contraseña actual.';
        }

        $clave = Validador::clave($nueva);
        if ($clave !== null) {
            $errores['clave_nueva'] = $clave;
        } elseif ($nueva !== $repetir) {
            $errores['clave_repetir'] = 'Las contraseñas nuevas no coinciden.';
        }

        return $errores;
    }
}
