<?php

declare(strict_types=1);

/** Escapa para salida HTML (evitar XSS en cada echo de datos de usuario/BD). */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Formatea un monto como moneda (Lempiras, ajustar símbolo si hace falta). */
function money(float|string $amount): string
{
    return 'L. ' . number_format((float) $amount, 2);
}

/** Formatea una fecha DATETIME de MySQL a algo legible. */
function fecha_legible(?string $datetime, string $formato = 'd/m/Y h:i A'): string
{
    if (!$datetime) {
        return '—';
    }
    try {
        return (new DateTime($datetime))->format($formato);
    } catch (Exception) {
        return $datetime;
    }
}

/** Zona horaria de la app (APP_TZ en seeder/.env; por defecto Honduras, UTC-6). */
function app_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $nombre = class_exists('Env') ? trim((string) Env::get('APP_TZ', '')) : '';
        try {
            $tz = new DateTimeZone($nombre !== '' ? $nombre : 'America/Tegucigalpa');
        } catch (Exception) {
            $tz = new DateTimeZone('America/Tegucigalpa');
        }
    }
    return $tz;
}

/** Etiqueta corta de la zona de la app, ej. "UTC-6". */
function app_tz_etiqueta(): string
{
    $seg = app_tz()->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $h = intdiv(abs($seg), 3600);
    $m = intdiv(abs($seg) % 3600, 60);
    return 'UTC' . ($seg < 0 ? '-' : '+') . $h . ($m ? sprintf(':%02d', $m) : '');
}

/**
 * Formatea la fecha de un partido. `partidos.fecha_partido` se guarda en UTC;
 * aquí se convierte a la zona horaria de la app antes de mostrarla.
 * (Para otras columnas, como created_at, se sigue usando fecha_legible().)
 */
function fecha_partido_legible(?string $datetimeUtc, string $formato = 'd/m/Y h:i A'): string
{
    if (!$datetimeUtc) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($datetimeUtc, new DateTimeZone('UTC')))
            ->setTimezone(app_tz())
            ->format($formato);
    } catch (Exception) {
        return $datetimeUtc;
    }
}

/** ¿El partido (fecha UTC de la base) todavía no empezó? */
function partido_futuro(?string $datetimeUtc): bool
{
    return $datetimeUtc !== null && strtotime($datetimeUtc . ' UTC') > time();
}

/** Genera una referencia única corta para transacciones/apuestas/recargas. */
function generar_referencia(string $prefijo): string
{
    return strtoupper($prefijo) . '-' . strtoupper(bin2hex(random_bytes(4))) . '-' . time();
}

/** Redirige y termina la ejecución. */
/** Prefija una ruta interna ('/login.php') con APP_URL (soporta subcarpetas). */
function url(string $path): string
{
    return (str_starts_with($path, '/') && !str_starts_with($path, '//')) ? APP_URL . $path : $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/** Guarda un mensaje flash (se muestra una sola vez en la próxima página). */
function flash(string $tipo, string $mensaje): void
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

/** Devuelve y limpia los mensajes flash acumulados. */
function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/** Token CSRF: lo genera si no existe y lo devuelve. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Campo <input hidden> listo para pegar dentro de un <form>. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Valida el token CSRF recibido por POST; corta la ejecución si no coincide. */
function verificar_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        flash('danger', 'Tu sesión expiró o la solicitud no es válida. Intentá de nuevo.');
        redirect($_SERVER['HTTP_REFERER'] ?? '/index.php');
    }
}

/** Traduce el estado de una apuesta/boleto/recarga a un badge de Bootstrap. */
function badge_estado(string $estado): string
{
    $mapa = [
        'pendiente'  => 'warning text-dark',
        'ganada'     => 'success',
        'ganado'     => 'success',
        'pagada'     => 'success',
        'pagado'     => 'success',
        'perdida'    => 'danger',
        'perdido'    => 'danger',
        'cancelada'  => 'secondary',
        'cancelado'  => 'secondary',
        'aprobada'   => 'success',
        'rechazada'  => 'danger',
        'programado' => 'info text-dark',
        'en_curso'   => 'primary',
        'finalizado' => 'success',
        'pospuesto'  => 'warning text-dark',
        'suspendido' => 'secondary',
    ];
    $clase = $mapa[$estado] ?? 'secondary';
    return '<span class="badge bg-' . $clase . '">' . e(ucfirst(str_replace('_', ' ', $estado))) . '</span>';
}

/**
 * Insignia "EN VIVO 67'" para partidos en curso. $minuto puede ser null (solo "EN VIVO").
 */
function badge_en_vivo(?string $minuto): string
{
    $texto = 'EN VIVO' . (($minuto !== null && $minuto !== '') ? ' ' . $minuto : '');
    return '<span class="badge bg-danger">' . e($texto) . '</span>';
}

/**
 * Escudo de un equipo. Si no hay URL (o la imagen no carga) queda un escudo genérico.
 * $size: 'sm' (24px) | 'md' (40px) | 'lg' (56px)
 */
function escudo_equipo(?string $url, string $alt, string $size = 'md'): string
{
    $img = '';
    if ($url && preg_match('#^(https?:)?//#i', $url)) {
        $img = '<img src="' . e($url) . '" alt="' . e($alt) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'">';
    }
    return '<span class="crest crest-' . e($size) . '" title="' . e($alt) . '"><i class="bi bi-shield-fill"></i>' . $img . '</span>';
}
