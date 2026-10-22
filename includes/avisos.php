<?php

/**
 * Avisos por correo para el usuario (los dispara cron/avisos_cron.php):
 *   - Recordatorio de partidos de equipos favoritos: ~3 días, ~1 día y ~3 horas antes.
 *   - Alineación titular (fútbol) cuando ESPN la publica, antes del partido.
 *   - Resultado del partido (favoritos y/o apuestas) con lo que ganó o perdió.
 *   - Resultado de boletos combinados y reembolsos por partidos cancelados/pospuestos.
 *
 * Reglas comunes: solo cuentas activas, con correo verificado y avisos_email = 1.
 * Cada aviso se reserva en `notificaciones_enviadas` ANTES de enviarse (UNIQUE), así nunca
 * se duplica aunque el cron se solape; si el envío falla, la reserva se libera y se reintenta.
 */

declare(strict_types=1);

require_once __DIR__ . '/mail_templates_avisos.php';
require_once __DIR__ . '/DetallePartido.php';

const AVISOS_FILTRO_USUARIO = 'u.activo = 1 AND u.email_verificado = 1 AND u.avisos_email = 1';

/** Ventanas (minutos que faltan para el partido): tipo => [desde, hasta, texto]. */
const AVISOS_VENTANAS = [
    'rec_3d' => [4320 - 360, 4320, 'en 3 días'],
    'rec_1d' => [1440 - 360, 1440, 'mañana'],
    'rec_3h' => [90, 180, 'en unas horas'],
];

/** Clave para firmar los enlaces de baja (no se puede adivinar el enlace de otro usuario). */
function avisos_clave(): string
{
    $k = trim((string) Env::get('CRON_SECRET_KEY', ''));
    return $k !== '' ? $k : hash('sha256', 'goalbet|' . Env::get('DB_NAME', '') . '|' . Env::get('DB_PASS', ''));
}

function avisos_firma(int $uid): string
{
    return substr(hash_hmac('sha256', 'baja|' . $uid, avisos_clave()), 0, 32);
}

function avisos_firma_valida(int $uid, string $t): bool
{
    return $uid > 0 && hash_equals(avisos_firma($uid), $t);
}

function avisos_url_baja(int $uid): string
{
    return url_publica('/baja_avisos.php?u=' . $uid . '&t=' . avisos_firma($uid));
}

function avisos_reservar(PDO $pdo, int $uid, string $tipo, int $ref): bool
{
    $st = $pdo->prepare('INSERT IGNORE INTO notificaciones_enviadas (id_usuario, tipo, id_ref) VALUES (?, ?, ?)');
    $st->execute([$uid, $tipo, $ref]);
    return $st->rowCount() === 1;
}

function avisos_liberar(PDO $pdo, int $uid, string $tipo, int $ref): void
{
    $pdo->prepare('DELETE FROM notificaciones_enviadas WHERE id_usuario = ? AND tipo = ? AND id_ref = ?')
        ->execute([$uid, $tipo, $ref]);
}

/** Reserva + envía. $presupuesto limita correos por ejecución (evita cron larguísimos y topes del SMTP). */
function avisos_enviar(PDO $pdo, int $uid, string $tipo, int $ref, int &$presupuesto, callable $envio): bool
{
    if ($presupuesto <= 0 || !avisos_reservar($pdo, $uid, $tipo, $ref)) {
        return false;
    }
    $presupuesto--;
    $ok = false;
    try {
        $ok = (bool) $envio();
    } catch (Throwable $e) {
        error_log('[avisos] ' . $tipo . ' #' . $ref . ': ' . $e->getMessage());
    }
    if (!$ok) {
        avisos_liberar($pdo, $uid, $tipo, $ref);
    }
    return $ok;
}

function avisos_tablas_listas(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT avisos_email FROM usuarios LIMIT 1');
        $pdo->query('SELECT 1 FROM notificaciones_enviadas LIMIT 1');
        $pdo->query('SELECT 1 FROM usuarios_equipos_favoritos LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Datos base de un partido para armar correos. */
function avisos_partido(PDO $pdo, int $idPartido): ?array
{
    $st = $pdo->prepare(
        'SELECT p.*, el.nombre AS local, ev.nombre AS visita, lg.nombre AS liga, es.nombre AS estadio
         FROM partidos p
         JOIN equipos el ON el.id = p.id_equipo_local
         JOIN equipos ev ON ev.id = p.id_equipo_visita
         LEFT JOIN ligas_torneos lg ON lg.id = p.id_liga
         LEFT JOIN estadios es ON es.id = p.id_estadio
         WHERE p.id = ?'
    );
    $st->execute([$idPartido]);
    return $st->fetch() ?: null;
}

/** Usuarios (aptos para recibir avisos) que siguen a alguno de los dos equipos: uid => fila + 'favoritos'. */
function avisos_seguidores(PDO $pdo, array $partido): array
{
    $st = $pdo->prepare(
        'SELECT u.id AS uid, u.nombre, u.username, u.email, f.id_equipo, e.nombre AS equipo
         FROM usuarios_equipos_favoritos f
         JOIN usuarios u ON u.id = f.id_usuario
         JOIN equipos e ON e.id = f.id_equipo
         WHERE f.id_equipo IN (?, ?) AND ' . AVISOS_FILTRO_USUARIO
    );
    $st->execute([(int) $partido['id_equipo_local'], (int) $partido['id_equipo_visita']]);
    $res = [];
    foreach ($st->fetchAll() as $r) {
        $uid = (int) $r['uid'];
        $res[$uid] ??= ['uid' => $uid, 'nombre' => $r['nombre'], 'username' => $r['username'], 'email' => $r['email'], 'favoritos' => [], 'ids_equipo' => []];
        $res[$uid]['favoritos'][]  = $r['equipo'];
        $res[$uid]['ids_equipo'][] = (int) $r['id_equipo'];
    }
    return $res;
}

// ---------------------------------------------------------------------------
// 1) Recordatorios 3 días / 1 día / 3 horas
// ---------------------------------------------------------------------------
function avisos_recordatorios(PDO $pdo, int &$presupuesto): int
{
    $enviados = 0;
    foreach (AVISOS_VENTANAS as $tipo => [$desde, $hasta, $cuando]) {
        $partidos = $pdo->query(
            "SELECT p.id FROM partidos p
             WHERE p.estado = 'programado'
               AND TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), p.fecha_partido) BETWEEN {$desde} AND {$hasta}
             ORDER BY p.fecha_partido LIMIT 100"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($partidos as $idPartido) {
            $p = avisos_partido($pdo, (int) $idPartido);
            if (!$p) {
                continue;
            }
            foreach (avisos_seguidores($pdo, $p) as $u) {
                $pend = $pdo->prepare(
                    "SELECT tipo_apuesta, monto, cuota FROM apuestas
                     WHERE id_usuario = ? AND id_partido = ? AND estado = 'pendiente' AND id_boleto IS NULL"
                );
                $pend->execute([$u['uid'], (int) $idPartido]);
                $ok = avisos_enviar($pdo, $u['uid'], $tipo, (int) $idPartido, $presupuesto, fn () =>
                    correo_recordatorio_partido($u, $p, $tipo, $cuando, $pend->fetchAll(), avisos_url_baja($u['uid'])));
                $enviados += $ok ? 1 : 0;
            }
        }
    }
    return $enviados;
}

// ---------------------------------------------------------------------------
// 2) Alineación titular (solo fútbol: ESPN la publica ~1 h antes)
// ---------------------------------------------------------------------------

/** Orden de línea: arquero, defensas, medios, delanteros. */
function avisos_linea_pos(?string $pos): int
{
    $p = strtoupper((string) $pos);
    if ($p === '') {
        return 4;
    }
    if ($p[0] === 'G') {
        return 0;
    }
    if (str_contains($p, 'M')) {
        return 2;
    }
    if (str_contains($p, 'D') || str_contains($p, 'B') || str_contains($p, 'SW')) {
        return 1;
    }
    return 3;
}

function avisos_titulares(PDO $pdo, int $idPartido, int $idEquipo): array
{
    $st = $pdo->prepare(
        'SELECT nombre, dorsal, posicion FROM partido_alineacion
         WHERE id_partido = ? AND id_equipo = ? AND titular = 1 ORDER BY lugar, id'
    );
    $st->execute([$idPartido, $idEquipo]);
    $j = $st->fetchAll();
    usort($j, static fn ($a, $b) => avisos_linea_pos($a['posicion']) <=> avisos_linea_pos($b['posicion']));
    return $j;
}

function avisos_alineaciones(PDO $pdo, int &$presupuesto): int
{
    if (!DetallePartido::tablasListas($pdo)) {
        return 0;
    }
    $enviados = 0;
    $partidos = $pdo->query(
        "SELECT p.id FROM partidos p JOIN deportes d ON d.id = p.id_deporte
         WHERE p.estado = 'programado' AND d.slug = 'futbol'
           AND TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), p.fecha_partido) BETWEEN 0 AND 180
         ORDER BY p.fecha_partido LIMIT 60"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($partidos as $idPartido) {
        $idPartido = (int) $idPartido;
        $p = avisos_partido($pdo, $idPartido);
        if (!$p) {
            continue;
        }
        $seguidores = avisos_seguidores($pdo, $p);
        if (!$seguidores) {
            continue;
        }

        $idL = (int) $p['id_equipo_local'];
        $idV = (int) $p['id_equipo_visita'];
        $xi = [$idL => avisos_titulares($pdo, $idPartido, $idL), $idV => avisos_titulares($pdo, $idPartido, $idV)];

        // Si todavía no hay 11 titulares, pedirlos a ESPN (máx. 1 intento cada 10 min por partido).
        if (count($xi[$idL]) < 11 && count($xi[$idV]) < 11) {
            $st = $pdo->prepare('SELECT TIMESTAMPDIFF(MINUTE, intento_at, UTC_TIMESTAMP()) FROM partido_detalle WHERE id_partido = ?');
            $st->execute([$idPartido]);
            $hace = $st->fetchColumn();
            if ($hace === false || $hace === null || (int) $hace >= 10) {
                DetallePartido::importar($pdo, $idPartido);
                $xi = [$idL => avisos_titulares($pdo, $idPartido, $idL), $idV => avisos_titulares($pdo, $idPartido, $idV)];
            }
        }

        foreach ($seguidores as $u) {
            // Secciones: primero el equipo que sigue, luego el rival (si ya hay 11 de cada uno).
            $secciones = [];
            foreach ([$idL => $p['local'], $idV => $p['visita']] as $idEq => $nombreEq) {
                if (count($xi[$idEq]) >= 11) {
                    $secciones[] = ['equipo' => $nombreEq, 'favorito' => in_array($idEq, $u['ids_equipo'], true), 'jugadores' => $xi[$idEq]];
                }
            }
            // Solo se avisa cuando está el 11 del equipo que sigue.
            if (!array_filter($secciones, static fn ($s) => $s['favorito'])) {
                continue;
            }
            usort($secciones, static fn ($a, $b) => (int) $b['favorito'] <=> (int) $a['favorito']);
            $ok = avisos_enviar($pdo, $u['uid'], 'alineacion', $idPartido, $presupuesto, fn () =>
                correo_alineacion($u, $p, $secciones, avisos_url_baja($u['uid'])));
            $enviados += $ok ? 1 : 0;
        }
    }
    return $enviados;
}

// ---------------------------------------------------------------------------
// 3) Resultado del partido (favoritos y/o apuestas simples)
// ---------------------------------------------------------------------------
function avisos_resultados(PDO $pdo, int &$presupuesto): int
{
    $enviados = 0;
    $partidos = $pdo->query(
        "SELECT id FROM partidos
         WHERE estado = 'finalizado' AND marcador_local IS NOT NULL AND marcador_visita IS NOT NULL
           AND fecha_partido >= UTC_TIMESTAMP() - INTERVAL 3 DAY
         ORDER BY fecha_partido DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($partidos as $idPartido) {
        $idPartido = (int) $idPartido;
        $p = avisos_partido($pdo, $idPartido);
        if (!$p) {
            continue;
        }

        $destinos = avisos_seguidores($pdo, $p);
        $ap = $pdo->prepare(
            'SELECT DISTINCT u.id AS uid, u.nombre, u.username, u.email
             FROM apuestas a JOIN usuarios u ON u.id = a.id_usuario
             WHERE a.id_partido = ? AND a.id_boleto IS NULL AND ' . AVISOS_FILTRO_USUARIO
        );
        $ap->execute([$idPartido]);
        foreach ($ap->fetchAll() as $r) {
            $uid = (int) $r['uid'];
            $destinos[$uid] ??= ['uid' => $uid, 'nombre' => $r['nombre'], 'username' => $r['username'], 'email' => $r['email'], 'favoritos' => [], 'ids_equipo' => []];
        }

        foreach ($destinos as $u) {
            $q = $pdo->prepare(
                'SELECT tipo_apuesta, monto, cuota, ganancia_potencial, estado FROM apuestas
                 WHERE id_usuario = ? AND id_partido = ? AND id_boleto IS NULL ORDER BY id'
            );
            $q->execute([$u['uid'], $idPartido]);
            $apuestas = $q->fetchAll();

            // Si alguna apuesta sigue pendiente (aún no liquidada), esperar a la próxima corrida.
            if (array_filter($apuestas, static fn ($a) => $a['estado'] === 'pendiente')) {
                continue;
            }
            $apuestas = array_values(array_filter($apuestas, static fn ($a) => in_array($a['estado'], ['ganada', 'pagada', 'perdida'], true)));

            $neto = 0.0;
            foreach ($apuestas as $a) {
                $neto += $a['estado'] === 'perdida' ? -(float) $a['monto'] : (float) $a['ganancia_potencial'] - (float) $a['monto'];
            }
            $saldo = (float) $pdo->query('SELECT saldo FROM usuarios WHERE id = ' . (int) $u['uid'])->fetchColumn();

            $ok = avisos_enviar($pdo, $u['uid'], 'resultado', $idPartido, $presupuesto, fn () =>
                correo_resultado_partido($u, $p, $apuestas, $neto, $saldo, avisos_url_baja($u['uid'])));
            $enviados += $ok ? 1 : 0;
        }
    }
    return $enviados;
}

// ---------------------------------------------------------------------------
// 4) Reembolsos por partido cancelado / pospuesto / suspendido (apuestas simples)
// ---------------------------------------------------------------------------
function avisos_reembolsos(PDO $pdo, int &$presupuesto): int
{
    $enviados = 0;
    $filas = $pdo->query(
        "SELECT a.id_usuario AS uid, a.id_partido, SUM(a.monto) AS monto, COUNT(*) AS n,
                u.nombre, u.username, u.email
         FROM apuestas a
         JOIN partidos p ON p.id = a.id_partido
         JOIN usuarios u ON u.id = a.id_usuario
         WHERE a.estado = 'cancelada' AND a.id_boleto IS NULL
           AND p.estado IN ('cancelado','pospuesto','suspendido')
           AND a.resuelta_at >= NOW() - INTERVAL 3 DAY
           AND " . AVISOS_FILTRO_USUARIO . "
         GROUP BY a.id_usuario, a.id_partido LIMIT 200"
    )->fetchAll();

    foreach ($filas as $f) {
        $p = avisos_partido($pdo, (int) $f['id_partido']);
        if (!$p) {
            continue;
        }
        $u = ['uid' => (int) $f['uid'], 'nombre' => $f['nombre'], 'username' => $f['username'], 'email' => $f['email']];
        $ok = avisos_enviar($pdo, $u['uid'], 'reembolso', (int) $f['id_partido'], $presupuesto, fn () =>
            correo_reembolso($u, $p, (float) $f['monto'], (int) $f['n'], avisos_url_baja($u['uid'])));
        $enviados += $ok ? 1 : 0;
    }
    return $enviados;
}

// ---------------------------------------------------------------------------
// 5) Boletos combinados resueltos (ganado / perdido / reembolsado)
// ---------------------------------------------------------------------------
function avisos_boletos(PDO $pdo, int &$presupuesto): int
{
    $enviados = 0;
    $boletos = $pdo->query(
        "SELECT b.*, u.nombre, u.username, u.email, u.saldo
         FROM boletos b JOIN usuarios u ON u.id = b.id_usuario
         WHERE " . AVISOS_FILTRO_USUARIO . "
           AND (
                (b.estado IN ('ganado','perdido') AND b.resuelto_at >= NOW() - INTERVAL 3 DAY)
             OR (b.estado = 'cancelado' AND b.created_at >= NOW() - INTERVAL 30 DAY AND EXISTS (
                    SELECT 1 FROM apuestas a JOIN partidos p ON p.id = a.id_partido
                    WHERE a.id_boleto = b.id AND p.estado IN ('cancelado','pospuesto','suspendido')))
           )
         ORDER BY b.id DESC LIMIT 200"
    )->fetchAll();

    foreach ($boletos as $b) {
        $q = $pdo->prepare(
            'SELECT a.tipo_apuesta, a.cuota, a.estado, p.marcador_local, p.marcador_visita, p.estado AS estado_partido,
                    el.nombre AS local, ev.nombre AS visita
             FROM apuestas a
             JOIN partidos p ON p.id = a.id_partido
             JOIN equipos el ON el.id = p.id_equipo_local
             JOIN equipos ev ON ev.id = p.id_equipo_visita
             WHERE a.id_boleto = ? ORDER BY a.id'
        );
        $q->execute([(int) $b['id']]);
        $patas = $q->fetchAll();
        $u = ['uid' => (int) $b['id_usuario'], 'nombre' => $b['nombre'], 'username' => $b['username'], 'email' => $b['email']];

        $ok = avisos_enviar($pdo, $u['uid'], 'boleto', (int) $b['id'], $presupuesto, fn () =>
            correo_boleto($u, $b, $patas, (float) $b['saldo'], avisos_url_baja($u['uid'])));
        $enviados += $ok ? 1 : 0;
    }
    return $enviados;
}

/** Corre todos los avisos. @return array<string,int> */
function avisos_ejecutar(PDO $pdo, int $maxCorreos = 80): array
{
    $presupuesto = $maxCorreos;
    return [
        'recordatorios' => avisos_recordatorios($pdo, $presupuesto),
        'alineaciones'  => avisos_alineaciones($pdo, $presupuesto),
        'resultados'    => avisos_resultados($pdo, $presupuesto),
        'reembolsos'    => avisos_reembolsos($pdo, $presupuesto),
        'boletos'       => avisos_boletos($pdo, $presupuesto),
        'pendientes_por_limite' => $presupuesto <= 0 ? 1 : 0,
    ];
}
