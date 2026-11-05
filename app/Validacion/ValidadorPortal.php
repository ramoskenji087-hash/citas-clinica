<?php
declare(strict_types=1);

namespace App\Validacion;

/**
 * Validación de las acciones del portal (RF01, RF02, RF12): contacto del paciente, ficha médica,
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
     * Ficha médica y contacto de emergencia (sección 3.4 del documento).
     * Todos los campos son opcionales. Pero si se llena algún dato del contacto de emergencia,
     * el nombre, el parentesco y el teléfono son obligatorios.
     *
     * @param array<string, mixed> $d
     * @return array<string, string>
     */
    public static function ficha(array $d): array
    {
        $errores = [];

        $tiposSangre = ['', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        if (!in_array((string) $d['tipo_sangre'], $tiposSangre, true)) {
            $errores['tipo_sangre'] = 'Seleccione un tipo de sangre de la lista.';
        }

        $textos = [
            'alergias' => 'Alergias',
            'enfermedades_cronicas' => 'Enfermedades crónicas',
            'medicamentos' => 'Medicamentos',
            'antecedentes' => 'Antecedentes',
        ];
        foreach ($textos as $campo => $titulo) {
            if (mb_strlen((string) $d[$campo]) > 1000) {
                $errores[$campo] = $titulo . ': máximo 1000 caracteres.';
            }
        }

        $algunDatoDeEmergencia = (string) $d['contacto_emergencia_nombre'] !== ''
            || (string) $d['contacto_emergencia_parentesco'] !== ''
            || (string) $d['contacto_emergencia_telefono'] !== '';

        if ($algunDatoDeEmergencia) {
            $nombre = Validador::nombre((string) $d['contacto_emergencia_nombre'], 'El nombre del contacto de emergencia');
            if ($nombre !== null) {
                $errores['contacto_emergencia_nombre'] = $nombre;
            }

            if ((string) $d['contacto_emergencia_parentesco'] === '') {
                $errores['contacto_emergencia_parentesco'] = 'Indique el parentesco del contacto de emergencia.';
            } elseif (mb_strlen((string) $d['contacto_emergencia_parentesco']) > 60) {
                $errores['contacto_emergencia_parentesco'] = 'El parentesco no puede pasar de 60 caracteres.';
            }

            $telefono = Validador::telefono((string) $d['contacto_emergencia_telefono']);
            if ($telefono !== null) {
                $errores['contacto_emergencia_telefono'] = $telefono;
            }
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
