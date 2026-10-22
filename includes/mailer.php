<?php

/**
 * Envío de correos por SMTP (PHPMailer).
 *
 * Configuración en seeder/.env (mismas claves que EcoTienda):
 *   MAIL_HOST, MAIL_PORT, MAIL_SECURE (tls|ssl|vacío), MAIL_USER, MAIL_PASS,
 *   MAIL_FROM, MAIL_FROM_NAME, APP_PUBLIC_URL
 *
 * enviar_correo() NUNCA lanza excepciones: devuelve true/false, para que un fallo
 * de SMTP no rompa el registro, la aprobación de una recarga, etc.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

/** ¿Hay un servidor SMTP configurado? */
function mail_configurado(): bool
{
    return trim((string) Env::get('MAIL_HOST', '')) !== ''
        && trim((string) Env::get('MAIL_USER', '')) !== ''
        && (string) Env::get('MAIL_PASS', '') !== '';
}

/**
 * URL absoluta (https://dominio/sub/ruta) para usar dentro de correos.
 * APP_URL solo guarda el PATH, así que el dominio sale de APP_PUBLIC_URL
 * (recomendado en producción). Si no está definido se intenta deducir del request.
 */
function url_publica(string $path = ''): string
{
    $origen = rtrim(trim((string) Env::get('APP_PUBLIC_URL', '')), '/');
    if ($origen === '' && PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host   = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string) $_SERVER['HTTP_HOST']);
        $origen = ($https ? 'https://' : 'http://') . $host;
    }
    return $origen . APP_URL . $path;
}

/** Quita HTML para la parte de texto plano del correo. */
function correo_a_texto(string $html): string
{
    $html = preg_replace('#<(style|head)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#</td>#i', ' ', $html) ?? $html;
    $html = preg_replace('#<br\s*/?>|</p>|</tr>|</h[1-6]>|</li>#i', "\n", $html) ?? $html;
    $html = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
    $txt  = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    return trim((string) preg_replace("/[ \t]+\n|\n{3,}/", "\n\n", $txt));
}

/**
 * Envía un correo HTML (con alternativa en texto plano).
 *
 * - Con SMTP configurado: envía de verdad.
 * - Sin SMTP y APP_ENV=local: no envía, guarda el correo en seeder/logs/mail_dev.log
 *   y devuelve true (así se puede desarrollar sin credenciales).
 * - Sin SMTP en producción: registra el error y devuelve false.
 */
function enviar_correo(string $para, string $nombre, string $asunto, string $html, ?string $texto = null, ?string $replyTo = null, string $replyNombre = ''): bool
{
    $texto ??= correo_a_texto($html);

    if (!mail_configurado()) {
        if (Env::get('APP_ENV', 'production') === 'local') {
            $log = SEEDER_PATH . '/logs/mail_dev.log';
            $linea = '[' . date('Y-m-d H:i:s') . "] PARA: {$para} | ASUNTO: {$asunto}\n" . $texto . "\n" . str_repeat('-', 70) . "\n";
            @file_put_contents($log, $linea, FILE_APPEND | LOCK_EX);
            return true;
        }
        error_log('[mailer] SMTP no configurado (MAIL_HOST/MAIL_USER/MAIL_PASS). No se envió: ' . $asunto . ' -> ' . $para);
        return false;
    }

    try {
        $host   = trim((string) Env::get('MAIL_HOST', ''));
        $pass   = (string) Env::get('MAIL_PASS', '');
        if (stripos($host, 'gmail.com') !== false) {
            $pass = str_replace(' ', '', $pass); // las claves de aplicación de Google vienen con espacios
        }
        $secure = strtolower(trim((string) Env::get('MAIL_SECURE', 'tls')));
        $puerto = (int) Env::get('MAIL_PORT', $secure === 'ssl' ? '465' : '587');
        $user   = trim((string) Env::get('MAIL_USER', ''));
        $from   = trim((string) Env::get('MAIL_FROM', '')) ?: $user;
        $fromNombre = trim((string) Env::get('MAIL_FROM_NAME', '')) ?: 'GoalBet 2026';

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $puerto;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS
                          : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->SMTPAutoTLS = $secure !== '';
        $mail->Timeout    = 12;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug  = 0;

        $mail->setFrom($from, $fromNombre);
        $mail->addAddress($para, $nombre);
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo, $replyNombre);
        }
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $html;
        $mail->AltBody = $texto;
        $mail->send();
        return true;
    } catch (Throwable $ex) {
        // Nunca registrar la contraseña; solo el mensaje de error.
        error_log('[mailer] Falló el envío "' . $asunto . '" a ' . $para . ': ' . $ex->getMessage());
        return false;
    }
}
