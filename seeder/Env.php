<?php
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit("Acceso denegado.\n");
}


/**
 * Carga variables de entorno desde un archivo .env sin depender de
 * ninguna libreria externa. Nunca hardcodear API keys en el codigo.
 *
 * Los valores del .env se guardan tambien en memoria (self::$vars) y get()
 * no depende solo de getenv(): en Apache para Windows (hilos) getenv()/putenv()
 * pueden devolver vacio entre pedidos y eso causaba conexiones a MySQL "sin
 * password". Prioridad: variable de entorno real NO vacia > valor del .env > default.
 */
class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key === '') {
                continue;
            }
            self::$vars[$key] = $value;
            $actual = getenv($key);
            if ($actual === false || $actual === '') {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        return $real === false ? $default : $real;
    }
}
