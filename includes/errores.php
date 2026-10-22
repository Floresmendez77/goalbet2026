<?php

/**
 * Manejo de errores 500: cualquier excepción o error fatal sin atrapar muestra una página
 * amable (sin detalles técnicos en producción) y deja el detalle en seeder/logs/php_errors.log
 * junto con un código de incidente que el usuario puede citar al pedir ayuda.
 */

declare(strict_types=1);

/** Código corto para relacionar lo que ve el usuario con la línea del log. */
function gb_codigo_incidente(): string
{
    return strtoupper(bin2hex(random_bytes(3)));
}

/** Página 500 autocontenida (no depende de la base de datos ni de la sesión). */
function gb_pagina_error_500(string $codigo, ?string $detalle = null): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $home = (defined('APP_URL') ? APP_URL : '') . '/index.php';
    $ayuda = (defined('APP_URL') ? APP_URL : '') . '/ayuda.php';

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Algo salió mal · GoalBet 2026</title>'
        . '<style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f1115;color:#e9ecef;font-family:Arial,Helvetica,sans-serif;padding:24px}'
        . '.box{max-width:520px;text-align:center}.cod{font-size:84px;font-weight:800;color:#ffc107;line-height:1;margin:0 0 8px}'
        . 'h1{font-size:24px;margin:0 0 12px}p{color:#adb5bd;line-height:1.55;margin:0 0 12px}'
        . '.btn{display:inline-block;margin:6px;padding:11px 24px;border-radius:6px;font-weight:bold;text-decoration:none}'
        . '.b1{background:#ffc107;color:#111}.b2{border:1px solid #495057;color:#e9ecef}'
        . '.inc{font-family:monospace;background:#1b1f27;border-radius:6px;padding:6px 12px;display:inline-block;color:#ffc107}'
        . 'pre{text-align:left;background:#1b1f27;color:#ff8a8a;border-radius:6px;padding:12px;overflow:auto;font-size:12px}'
        . '</style></head><body><div class="box">'
        . '<div class="cod">500</div><h1>⚽ Uy, algo salió mal</h1>'
        . '<p>Tuvimos un problema de nuestro lado y no pudimos completar lo que pediste. No hiciste nada mal; probá de nuevo en unos minutos.</p>'
        . '<p>Si el problema sigue, escribinos desde la página de ayuda y mencioná este código:<br><span class="inc">' . $h($codigo) . '</span></p>'
        . '<a class="btn b1" href="' . $h($home) . '">Volver al inicio</a><a class="btn b2" href="' . $h($ayuda) . '">Ayuda y contacto</a>';
    if ($detalle !== null) {
        echo '<pre>' . $h($detalle) . '</pre>';
    }
    echo '</div></body></html>';
}

function gb_instalar_manejo_errores(): void
{
    $local = Env::get('APP_ENV', 'production') === 'local';

    set_exception_handler(static function (Throwable $e) use ($local): void {
        $codigo = gb_codigo_incidente();
        error_log('[500 ' . $codigo . '] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString());
        gb_pagina_error_500($codigo, $local ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() : null);
    });

    // Errores fatales (memoria, tipos, etc.) que no pasan por el manejador de excepciones.
    register_shutdown_function(static function () use ($local): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $codigo = gb_codigo_incidente();
            error_log('[500 ' . $codigo . '] Error fatal: ' . $err['message'] . ' en ' . $err['file'] . ':' . $err['line']);
            gb_pagina_error_500($codigo, $local ? $err['message'] . "\n" . $err['file'] . ':' . $err['line'] : null);
        }
    });
}
