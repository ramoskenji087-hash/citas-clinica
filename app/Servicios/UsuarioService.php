<?php
declare(strict_types=1);

namespace App\Servicios;

use App\Datos\Conexion;

/**
 * Gestión de la cuenta del propio usuario (RF01, RNF01).
 *
 * La administración de usuarios por parte del Administrador (crear, asignar rol, vincular
 * ficha y desactivar) se agrega en el bloque de usuarios.
 */
final class UsuarioService
{
    /**
     * Cambia la contraseña de un usuario activo. La contraseña actual debe ser correcta,
     * y la nueva no puede ser igual a la anterior. Solo se guarda el hash.
     *
     * @throws ReglaException si la contraseña actual no es correcta o la nueva es igual a la anterior
     */
    public static function cambiarClave(int $idUsuario, string $claveActual, string $claveNueva): void
    {
        $pdo = Conexion::obtener();

        $consulta = $pdo->prepare(
            'SELECT password_hash FROM usuario WHERE id_usuario = :id_usuario AND activo'
        );
        $consulta->execute([':id_usuario' => $idUsuario]);
        $hash = $consulta->fetchColumn();

        if ($hash === false || !password_verify($claveActual, (string) $hash)) {
            throw new ReglaException('La contraseña actual no es correcta.');
        }

        if (password_verify($claveNueva, (string) $hash)) {
            throw new ReglaException('La contraseña nueva debe ser distinta de la actual.');
        }

        $pdo->prepare('UPDATE usuario SET password_hash = :hash WHERE id_usuario = :id_usuario')
            ->execute([
                ':hash' => password_hash($claveNueva, PASSWORD_DEFAULT),
                ':id_usuario' => $idUsuario,
            ]);
    }
}
