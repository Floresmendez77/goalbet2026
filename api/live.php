<?php

declare(strict_types=1);

/**
 * API en vivo (solo lectura) — GoalBet2026.
 *
 *   GET /api/live.php?ids=12,15,18            -> estado de varios partidos (lista / dashboard)
 *   GET /api/live.php?ids=12&eventos=1        -> un partido + su línea de tiempo (goles, asistencias, tarjetas...)
 *
 * Lee SOLO de nuestra base de datos (la llena cron/live_cron.php): NUNCA llama a ESPN.
 * Requiere sesión iniciada (igual que partido.php / partidos.php), pero responde JSON:
 * 401 en vez de redirigir al login. Admite ETag: si nada cambió responde 304 sin cuerpo.
 *
 * Respuesta 200:
 *   { "ok": true, "poll": 15|null, "partidos": { "12": { estado, en_vivo, minuto, marcador, inicio_utc, poll, eventos? } },
 *     "v": "huella", "ahora": "2026-10-22T21:05:11Z" }
 *   poll = cada cuántos segundos conviene volver a preguntar; null = no hace falta seguir preguntando.
 */

// Captura cualquier salida accidental (avisos de PHP, BOM, etc.) para que no ensucie el JSON.
ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/live_data.php';

/** Envía solo JSON (descarta lo impreso antes) y termina. */
function live_responder(array $datos, int $codigo = 200, array $cabeceras = []): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-cache');
    foreach ($cabeceras as $h) {
        header($h);
    }
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        live_responder(['ok' => false, 'error' => 'metodo_no_permitido'], 405, ['Allow: GET']);
    }

    // --- Sesión: JSON 401 (requerir_login() redirige, no sirve para una API) ---------------
    if (!esta_logueado()) {
        live_responder(['ok' => false, 'error' => 'no_autenticado'], 401);
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT activo FROM usuarios WHERE id = ?');
    $st->execute([(int) usuario_actual()['id']]);
    $activo = $st->fetchColumn();
    if ($activo === false || (int) $activo === 0) {
        live_responder(['ok' => false, 'error' => 'cuenta_desactivada'], 401);
    }
    // Libera el bloqueo de la sesión: así un sondeo lento no frena las demás pestañas del usuario.
    session_write_close();

    // --- Parámetros -------------------------------------------------------------------------
    $raw = trim((string) ($_GET['ids'] ?? ''));
    if ($raw === '') {
        live_responder(['ok' => false, 'error' => 'ids_requeridos'], 400);
    }
    $ids = [];
    foreach (explode(',', $raw) as $trozo) {
        $trozo = trim($trozo);
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $trozo)) {
            live_responder(['ok' => false, 'error' => 'ids_invalidos'], 400);
        }
        $ids[(int) $trozo] = true;
    }
    $ids = array_keys($ids);
    if (count($ids) > LIVE_MAX_IDS) {
        live_responder(['ok' => false, 'error' => 'demasiados_ids', 'max' => LIVE_MAX_IDS], 400);
    }
    $conEventos = (string) ($_GET['eventos'] ?? '0') === '1';
    if ($conEventos && count($ids) !== 1) {
        live_responder(['ok' => false, 'error' => 'eventos_requiere_un_solo_partido'], 400);
    }

    // --- Lectura (1 consulta de estados + 1 de eventos si se piden) -------------------------
    $estados  = live_estados($pdo, $ids);
    $payload  = [
        'ok'       => true,
        'poll'     => live_poll_global($estados),
        'partidos' => (object) live_publicar($estados, $pdo, $conEventos),
    ];
    $version = live_version($payload);
    $etag = '"' . $version . '"';

    // --- ETag: si el navegador ya tiene esta versión, 304 sin cuerpo ------------------------
    $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($inm !== '' && trim(preg_replace('#^W/#', '', $inm) ?? '') === $etag) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache');
        exit;
    }

    $payload['v']     = $version;
    $payload['ahora'] = gmdate('Y-m-d\TH:i:s\Z');
    live_responder($payload, 200, ['ETag: ' . $etag, 'Vary: Cookie']);
} catch (Throwable $e) {
    $codigo = gb_codigo_incidente();
    error_log('[api/live ' . $codigo . '] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    live_responder(['ok' => false, 'error' => 'error_interno', 'codigo' => $codigo], 500);
}
