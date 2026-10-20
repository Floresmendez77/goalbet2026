<?php

/**
 * Bootstrap de la aplicación.
 * Reutiliza TAL CUAL las clases Env.php y Database.php del módulo
 * seeder/ (misma conexión, mismo .env) en vez de duplicar lógica de
 * conexión a la base de datos. No se modifica nada dentro de seeder/.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('SEEDER_PATH', BASE_PATH . '/seeder');

require_once SEEDER_PATH . '/Env.php';
require_once SEEDER_PATH . '/Database.php';

Env::load(SEEDER_PATH . '/.env');

// Errores: visibles solo en local. En producción se ocultan y se guardan en un
// archivo dentro de seeder/logs/ (carpeta bloqueada por .htaccess).
$appEnv = Env::get('APP_ENV', 'production');
error_reporting(E_ALL);
ini_set('display_errors', $appEnv === 'local' ? '1' : '0');
ini_set('log_errors', '1');
$logDir = SEEDER_PATH . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

// Sesión: cookie httponly + SameSite, y Secure si se sirve por HTTPS.
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

/**
 * Devuelve la conexión PDO compartida (singleton via Database::connect()).
 */
function db(): PDO
{
    return Database::connect();
}

// Ruta base para URLs/links (solo el PATH, sin host ni puerto).
// Se detecta sola: /goalbet2026_app_fixed/goalbet2026 bajo MAMP, vacío con php -S.
// Opcional: APP_BASE_PATH en el .env para forzarla (ej. /goalbet2026). APP_URL del
// .env (ej. http://localhost:8000) se IGNORA a propósito: es una URL completa y
// rompía los links al abrir la app por otro puerto o subcarpeta.
$appUrl = trim((string) Env::get('APP_BASE_PATH', ''));
if ($appUrl === '' && PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $doc  = str_replace('\\', '/', (string) realpath((string) $_SERVER['DOCUMENT_ROOT']));
    $base = str_replace('\\', '/', (string) realpath(BASE_PATH));
    if ($doc !== '' && $base !== '' && stripos($base, $doc) === 0) {
        $appUrl = substr($base, strlen($doc));
    }
}
define('APP_URL', rtrim($appUrl, '/'));