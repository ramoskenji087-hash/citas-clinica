<?php
declare(strict_types=1);

namespace App\Servicios;

use RuntimeException;

/**
 * Envío de correos con la API de Brevo.
 *
 * Se usa HTTPS y no SMTP: las plataformas en la nube suelen bloquear el puerto SMTP.
 * La clave de la API viene de la configuración (variable BREVO_API_KEY o config/local.php).
 * Nunca se escribe la clave en el registro del servidor.
 */
final class CorreoService
{
    private const URL_API = 'https://api.brevo.com/v3/smtp/email';

    /**
     * Envía un correo con versión HTML y versión de texto.
     *
     * @throws RuntimeException si el correo no está configurado o Brevo no lo acepta
     */
    public static function enviar(string $destinatario, string $nombre, string $asunto, string $html, string $texto): void
    {
        $correo = self::configuracion();

        if ($correo['api_key'] === '' || $correo['remitente'] === '') {
            throw new RuntimeException('El envío de correos no está configurado. Revise la clave de Brevo y el correo remitente.');
        }

        $cuerpo = json_encode([
            'sender'      => ['name' => $correo['nombre_remitente'], 'email' => $correo['remitente']],
            'to'          => [['email' => $destinatario, 'name' => $nombre]],
            'subject'     => $asunto,
            'htmlContent' => $html,
            'textContent' => $texto,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $curl = curl_init(self::URL_API);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . $correo['api_key'],
                'content-type: application/json',
            ],
        ]);

        $respuesta = curl_exec($curl);
        $codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($respuesta === false || $codigo < 200 || $codigo > 299) {
            error_log('[citas-clinica] Brevo no aceptó el correo (HTTP ' . $codigo . '): ' . ($error !== '' ? $error : (string) $respuesta));
            throw new RuntimeException('No se pudo enviar el correo. Intente de nuevo más tarde.');
        }
    }

    /** @return array{api_key: string, remitente: string, nombre_remitente: string} */
    private static function configuracion(): array
    {
        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

        return $config['correo'];
    }
}
