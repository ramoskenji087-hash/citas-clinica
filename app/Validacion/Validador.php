<?php
declare(strict_types=1);

namespace App\Validacion;

use DateTime;

/**
 * Validación de datos en el servidor (RF01, RF02, RF05 y sección 7).
 *
 * Cada regla devuelve null si el dato es válido, o un mensaje en español si no lo es.
 * La validación del navegador (JavaScript) solo ayuda al usuario: el servidor
 * siempre vuelve a validar todo, porque el navegador puede saltarse el JavaScript.
 */
final class Validador
{
    /** CI: de 5 a 15 caracteres alfanuméricos o guion (RF02). */
    public static function ci(string $valor): ?string
    {
        return preg_match('/^[A-Za-z0-9-]{5,15}$/', $valor) === 1
            ? null
            : 'El CI debe tener entre 5 y 15 caracteres: letras, números o guion.';
    }

    /** Quita espacios y guiones del teléfono. Se usa antes de validarlo y de guardarlo. */
    public static function limpiarTelefono(string $valor): string
    {
        return str_replace([' ', '-'], '', $valor);
    }

    /** Teléfono: de 7 a 15 dígitos, puede empezar con +. Recibe el valor ya limpio. */
    public static function telefono(string $valor): ?string
    {
        return preg_match('/^\+?[0-9]{7,15}$/', $valor) === 1
            ? null
            : 'El teléfono debe tener de 7 a 15 dígitos y puede empezar con +.';
    }

    /** Correo: opcional, salvo que se indique lo contrario. Si se escribe, debe tener formato válido. */
    public static function correo(string $valor, bool $obligatorio = false): ?string
    {
        if ($valor === '') {
            return $obligatorio ? 'Escriba el correo electrónico.' : null;
        }

        return filter_var($valor, FILTER_VALIDATE_EMAIL) === false
            ? 'El correo electrónico no tiene un formato válido.'
            : null;
    }

    /**
     * Nombre o apellido: de 2 a 80 caracteres, con letras, espacios, puntos,
     * apóstrofes y guiones (RF02). $campo es el nombre que se muestra, por ejemplo "El nombre".
     */
    public static function nombre(string $valor, string $campo): ?string
    {
        $longitud = self::longitud($valor);

        if ($longitud < 2 || $longitud > 80) {
            return $campo . ' debe tener entre 2 y 80 caracteres.';
        }

        return preg_match('/^\p{L}[\p{L} .\'\-]*\p{L}$/u', $valor) === 1
            ? null
            : $campo . ' solo puede tener letras, espacios, puntos, apóstrofes y guiones.';
    }

    /**
     * Fecha de nacimiento: fecha real con formato AAAA-MM-DD, y que no sea futura (RF02).
     * El prefijo "!" hace que la hora sea medianoche, para comparar solo las fechas.
     */
    public static function fechaNacimiento(string $valor): ?string
    {
        $fecha = DateTime::createFromFormat('!Y-m-d', $valor);
        $avisos = DateTime::getLastErrors();

        $invalida = $fecha === false
            || (is_array($avisos) && ($avisos['warning_count'] > 0 || $avisos['error_count'] > 0));

        if ($invalida) {
            return 'La fecha de nacimiento no es válida.';
        }

        return $fecha > new DateTime('today')
            ? 'La fecha de nacimiento no puede ser futura.'
            : null;
    }

    /** Sexo: solo los valores de la lista del formulario (RF02). */
    public static function sexo(string $valor): ?string
    {
        return in_array($valor, ['Masculino', 'Femenino', 'Otro'], true)
            ? null
            : 'Seleccione un sexo de la lista.';
    }

    /** Dirección: opcional, hasta 200 caracteres. */
    public static function direccion(string $valor): ?string
    {
        return self::longitud($valor) > 200
            ? 'La dirección no puede tener más de 200 caracteres.'
            : null;
    }

    /** Hora en formato HH:MM de 24 horas (RF05). */
    public static function hora(string $valor): ?string
    {
        return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $valor) === 1
            ? null
            : 'La hora debe tener el formato HH:MM.';
    }

    /** Contraseña: mínimo 8 caracteres, con al menos una letra y un número (RF01). */
    public static function clave(string $valor): ?string
    {
        if (self::longitud($valor) < 8) {
            return 'La contraseña debe tener al menos 8 caracteres.';
        }

        return preg_match('/[A-Za-z]/', $valor) === 1 && preg_match('/[0-9]/', $valor) === 1
            ? null
            : 'La contraseña debe tener letras y números.';
    }

    /**
     * Valida todos los campos del formulario de paciente (RF02).
     * Los datos deben llegar sin espacios sobrantes y con el teléfono ya limpio.
     *
     * @param array<string, string> $datos
     * @return array<string, string> errores por campo; vacío si todo es válido
     */
    public static function paciente(array $datos): array
    {
        $reglas = [
            'ci'               => self::ci($datos['ci']),
            'nombres'          => self::nombre($datos['nombres'], 'El nombre'),
            'apellidos'        => self::nombre($datos['apellidos'], 'El apellido'),
            'fecha_nacimiento' => $datos['fecha_nacimiento'] === ''
                ? 'Indique la fecha de nacimiento.'
                : self::fechaNacimiento($datos['fecha_nacimiento']),
            'sexo'             => self::sexo($datos['sexo']),
            'telefono'         => self::telefono($datos['telefono']),
            'correo'           => self::correo($datos['correo']),
            'direccion'        => self::direccion($datos['direccion']),
        ];

        return array_filter($reglas, static fn (?string $error): bool => $error !== null);
    }

    /** Cuenta caracteres, no bytes. Funciona sin la extensión mbstring. */
    private static function longitud(string $texto): int
    {
        return (int) preg_match_all('/./us', $texto);
    }
}
