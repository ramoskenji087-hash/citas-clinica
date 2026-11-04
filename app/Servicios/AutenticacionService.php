<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;

/**
 * Inicio de sesión (RF01).
 *
 * Busca al usuario por nombre de usuario o por correo, verifica la contraseña con password_verify,
 * y rechaza la cuenta si está desactivada o si su ficha de médico o de paciente está desactivada
 * (RN04, RN11). Nunca indica cuál dato falló, para no revelar qué usuarios existen.
 */
final class AutenticacionService
{
    /**
     * Devuelve los datos del usuario si puede entrar, o null en cualquier otro caso.
     *
     * @return array<string, mixed>|null
     * @throws \RuntimeException si no hay conexión con la base de datos
     */
    public static function autenticar(string $identificador, string $clave): ?array
    {
        $pdo = Conexion::obtener();

        // PostgreSQL devuelve los booleanos como texto ('t' o 'f'), así que "activo" se convierte
        // a 1 o 0 dentro de la consulta. Se usan dos nombres de parámetro porque el mismo valor
        // se compara con dos columnas.
        $consulta = $pdo->prepare(
            'SELECT u.id_usuario, u.nombres, u.apellidos, u.password_hash, u.id_rol,
                    u.id_medico, u.id_paciente, u.activo::int AS activo
               FROM usuario u
               LEFT JOIN medico m ON m.id_medico = u.id_medico
               LEFT JOIN paciente p ON p.id_paciente = u.id_paciente
              WHERE (lower(u.nombre_usuario) = lower(:identificador_usuario)
                     OR lower(u.correo) = lower(:identificador_correo))
                AND (u.id_medico IS NULL OR m.activo)
                AND (u.id_paciente IS NULL OR p.activo)
              LIMIT 1'
        );
        $consulta->execute([
            ':identificador_usuario' => $identificador,
            ':identificador_correo' => $identificador,
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
