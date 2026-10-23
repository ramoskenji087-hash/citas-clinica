<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;

/**
 * Inicio de sesión (RF01).
 *
 * Busca al usuario por nombre de usuario o por correo, verifica la contraseña
 * con password_verify y rechaza las cuentas desactivadas.
 */
final class AutenticacionService
{
    /**
     * Devuelve los datos del usuario si la contraseña es correcta y la cuenta está activa.
     * Devuelve null en cualquier otro caso, sin decir cuál dato falló: así no se puede
     * averiguar qué usuarios existen.
     *
     * @return array<string, mixed>|null
     * @throws \RuntimeException si no hay conexión con la base de datos
     */
    public static function autenticar(string $identificador, string $clave): ?array
    {
        $pdo = Conexion::obtener();

        // PostgreSQL devuelve los booleanos como texto ('t' o 'f'), así que
        // "activo" se convierte a 1 o 0 dentro de la misma consulta.
        // Se usan dos nombres de parámetro porque el mismo valor se compara con dos columnas.
        $consulta = $pdo->prepare(
            'SELECT id_usuario, nombres, apellidos, password_hash, id_rol, id_medico, id_paciente,
                    activo::int AS activo
               FROM usuario
              WHERE lower(nombre_usuario) = lower(:identificador_usuario)
                 OR lower(correo)         = lower(:identificador_correo)
              LIMIT 1'
        );
        $consulta->execute([
            ':identificador_usuario' => $identificador,
            ':identificador_correo'  => $identificador,
        ]);
        $usuario = $consulta->fetch();

        if ($usuario === false || (int) $usuario['activo'] !== 1) {
            return null;
        }

        if (!password_verify($clave, $usuario['password_hash'])) {
            return null;
        }

        // El hash no debe viajar más allá de este método.
        unset($usuario['password_hash'], $usuario['activo']);

        return $usuario;
    }
}
