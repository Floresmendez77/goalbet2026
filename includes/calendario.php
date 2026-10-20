<?php
declare(strict_types=1);

/**
 * Lógica del calendario de partidos y de los equipos favoritos.
 *
 * Las fechas de `partidos.fecha_partido` están guardadas en UTC (así las entrega
 * ESPN / football-data). El calendario las convierte a la zona horaria de la app
 * para ubicar cada partido en el día y la hora correctos del usuario.
 * Se puede cambiar con APP_TZ en seeder/.env (por defecto, Honduras).
 */

const CAL_MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const CAL_DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const CAL_DIAS_CORTO = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

function cal_tz(): DateTimeZone
{
    return app_tz();
}

function cal_utc(): DateTimeZone
{
    return new DateTimeZone('UTC');
}

/** DATETIME de la base (UTC) a la zona horaria de la app. */
function cal_local(string $dtUtc): DateTimeImmutable
{
    return (new DateTimeImmutable($dtUtc, cal_utc()))->setTimezone(cal_tz());
}

/** Etiqueta corta de la zona, ej. "UTC-6". */
function cal_tz_etiqueta(): string
{
    return app_tz_etiqueta();
}

/** ¿Existen las tablas nuevas? (si no se corrió el SQL, la página cae a la lista). */
function cal_tablas_listas(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        $n = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('usuarios_equipos_favoritos', 'usuarios_preferencias')"
        )->fetchColumn();
        $ok = $n === 2;
    }
    return $ok;
}

// ---------------------------------------------------------------- Preferencias

function cal_preferencias(PDO $pdo, int $uid): array
{
    $pdo->prepare('INSERT IGNORE INTO usuarios_preferencias (id_usuario) VALUES (?)')->execute([$uid]);
    $st = $pdo->prepare('SELECT favoritos_omitido, vista_partidos FROM usuarios_preferencias WHERE id_usuario = ?');
    $st->execute([$uid]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'favoritos_omitido' => (int) ($p['favoritos_omitido'] ?? 0),
        'vista_partidos'    => in_array($p['vista_partidos'] ?? '', ['calendario', 'lista'], true) ? $p['vista_partidos'] : 'calendario',
    ];
}

function cal_guardar_preferencia(PDO $pdo, int $uid, string $campo, int|string $valor): void
{
    if (!in_array($campo, ['favoritos_omitido', 'vista_partidos'], true)) {
        return;
    }
    $pdo->prepare('INSERT IGNORE INTO usuarios_preferencias (id_usuario) VALUES (?)')->execute([$uid]);
    $pdo->prepare("UPDATE usuarios_preferencias SET $campo = ? WHERE id_usuario = ?")->execute([$valor, $uid]);
}

// ------------------------------------------------------------------ Favoritos

function cal_favoritos(PDO $pdo, int $uid): array
{
    $st = $pdo->prepare(
        'SELECT e.id, e.nombre, e.escudo, d.nombre AS deporte
         FROM usuarios_equipos_favoritos f
         JOIN equipos e ON e.id = f.id_equipo
         JOIN deportes d ON d.id = e.id_deporte
         WHERE f.id_usuario = ?
         ORDER BY f.created_at, e.nombre'
    );
    $st->execute([$uid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cal_agregar_favorito(PDO $pdo, int $uid, int $idEquipo): bool
{
    $st = $pdo->prepare('SELECT 1 FROM equipos WHERE id = ?');
    $st->execute([$idEquipo]);
    if (!$st->fetchColumn()) {
        return false;
    }
    $pdo->prepare('INSERT IGNORE INTO usuarios_equipos_favoritos (id_usuario, id_equipo) VALUES (?, ?)')
        ->execute([$uid, $idEquipo]);
    return true;
}

function cal_quitar_favorito(PDO $pdo, int $uid, int $idEquipo): void
{
    $pdo->prepare('DELETE FROM usuarios_equipos_favoritos WHERE id_usuario = ? AND id_equipo = ?')
        ->execute([$uid, $idEquipo]);
}

/**
 * Equipos que se pueden elegir: solo los que tienen partidos cerca de hoy
 * (así no aparecen equipos viejos de temporadas pasadas).
 */
function cal_equipos_elegibles(PDO $pdo): array
{
    return $pdo->query(
        "SELECT e.id, e.nombre, e.escudo, d.nombre AS deporte, d.slug,
                GROUP_CONCAT(DISTINCT lt.nombre ORDER BY lt.nombre SEPARATOR ' · ') AS ligas
         FROM (
             SELECT id_equipo_local AS id_equipo, id_liga FROM partidos
              WHERE fecha_partido BETWEEN (UTC_TIMESTAMP() - INTERVAL 90 DAY) AND (UTC_TIMESTAMP() + INTERVAL 240 DAY)
             UNION
             SELECT id_equipo_visita, id_liga FROM partidos
              WHERE fecha_partido BETWEEN (UTC_TIMESTAMP() - INTERVAL 90 DAY) AND (UTC_TIMESTAMP() + INTERVAL 240 DAY)
         ) x
         JOIN equipos e ON e.id = x.id_equipo
         JOIN deportes d ON d.id = e.id_deporte
         LEFT JOIN ligas_torneos lt ON lt.id = x.id_liga
         GROUP BY e.id, e.nombre, e.escudo, d.nombre, d.slug
         ORDER BY e.nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------------------------------------------------------- Partidos

const CAL_SQL_PARTIDO = 'SELECT p.id, p.id_deporte, p.id_liga, p.id_equipo_local, p.id_equipo_visita, p.fecha_partido, p.estado,
               p.marcador_local, p.marcador_visita, p.cuota_local, p.cuota_empate, p.cuota_visita,
               d.nombre AS deporte, d.slug AS deporte_slug, lt.nombre AS liga,
               el.nombre AS equipo_local, el.escudo AS escudo_local,
               ev.nombre AS equipo_visita, ev.escudo AS escudo_visita
        FROM partidos p
        JOIN deportes d ON d.id = p.id_deporte
        LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
        JOIN equipos el ON el.id = p.id_equipo_local
        JOIN equipos ev ON ev.id = p.id_equipo_visita';

/**
 * Partidos entre dos instantes locales [desde, hasta). $equipoIds = null -> todos.
 * Cada fila trae '_local' (DateTimeImmutable) y '_dia' (Y-m-d en zona local).
 */
function cal_partidos_rango(PDO $pdo, DateTimeImmutable $desdeLocal, DateTimeImmutable $hastaLocal, ?array $equipoIds = null, ?int $deporte = null): array
{
    $sql = CAL_SQL_PARTIDO . ' WHERE p.fecha_partido >= ? AND p.fecha_partido < ?';
    $params = [
        $desdeLocal->setTimezone(cal_utc())->format('Y-m-d H:i:s'),
        $hastaLocal->setTimezone(cal_utc())->format('Y-m-d H:i:s'),
    ];
    if ($equipoIds !== null) {
        if (!$equipoIds) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($equipoIds), '?'));
        $sql .= " AND (p.id_equipo_local IN ($ph) OR p.id_equipo_visita IN ($ph))";
        $params = array_merge($params, $equipoIds, $equipoIds);
    }
    if ($deporte) {
        $sql .= ' AND p.id_deporte = ?';
        $params[] = $deporte;
    }
    $sql .= ' ORDER BY p.fecha_partido, p.id LIMIT 4000';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $filas = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as &$f) {
        $f['_local'] = cal_local($f['fecha_partido']);
        $f['_dia'] = $f['_local']->format('Y-m-d');
    }
    unset($f);
    return $filas;
}

/** Próximo partido (aún no jugado) de alguno de los equipos. */
function cal_proximo_partido(PDO $pdo, array $equipoIds): ?array
{
    if (!$equipoIds) {
        return null;
    }
    $ph = implode(',', array_fill(0, count($equipoIds), '?'));
    $st = $pdo->prepare(
        CAL_SQL_PARTIDO . " WHERE p.estado IN ('programado','en_curso')
           AND p.fecha_partido >= (UTC_TIMESTAMP() - INTERVAL 3 HOUR)
           AND (p.id_equipo_local IN ($ph) OR p.id_equipo_visita IN ($ph))
         ORDER BY p.fecha_partido LIMIT 1"
    );
    $st->execute(array_merge($equipoIds, $equipoIds));
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        return null;
    }
    $f['_local'] = cal_local($f['fecha_partido']);
    $f['_dia'] = $f['_local']->format('Y-m-d');
    return $f;
}

/** Agrupa partidos por día local: ['2026-10-12' => [partido, ...]]. */
function cal_agrupar_por_dia(array $partidos): array
{
    $g = [];
    foreach ($partidos as $p) {
        $g[$p['_dia']][] = $p;
    }
    return $g;
}

/** Perspectiva de un equipo en un partido: rival, si es local, resultado G/E/P. */
function cal_perspectiva(array $p, int $equipoId): array
{
    $esLocal = (int) $p['id_equipo_local'] === $equipoId;
    $mio = $esLocal ? $p['marcador_local'] : $p['marcador_visita'];
    $suyo = $esLocal ? $p['marcador_visita'] : $p['marcador_local'];
    $res = '';
    if ($p['estado'] === 'finalizado' && $mio !== null && $suyo !== null) {
        $res = $mio > $suyo ? 'G' : ($mio < $suyo ? 'P' : 'E');
    }
    return [
        'es_local'   => $esLocal,
        'rival'      => $esLocal ? $p['equipo_visita'] : $p['equipo_local'],
        'rival_esc'  => $esLocal ? $p['escudo_visita'] : $p['escudo_local'],
        'mio'        => $mio,
        'suyo'       => $suyo,
        'resultado'  => $res,
    ];
}

/** ¿Cuál de los equipos elegidos juega este partido? (el primero que coincida) */
function cal_equipo_foco(array $p, array $equipoIds): ?int
{
    foreach ($equipoIds as $id) {
        if ((int) $p['id_equipo_local'] === $id || (int) $p['id_equipo_visita'] === $id) {
            return $id;
        }
    }
    return null;
}

/** ¿Se puede apostar? (programado y todavía no empezó) */
function cal_apostable(array $p): bool
{
    return $p['estado'] === 'programado' && strtotime($p['fecha_partido'] . ' UTC') > time();
}

/** Marcador para mostrar. */
function cal_marcador(array $p): string
{
    return (int) $p['marcador_local'] . ' - ' . (int) $p['marcador_visita'];
}

/**
 * Cuadrícula del mes: semanas (lunes a domingo) con sus días.
 * Cada día: ['fecha' => 'Y-m-d', 'numero' => 12, 'en_mes' => bool, 'hoy' => bool, 'partidos' => [...]]
 */
function cal_grid(int $anio, int $mes, array $porDia, DateTimeImmutable $hoy): array
{
    $primero = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes), cal_tz());
    $ultimo = $primero->modify('last day of this month');
    $cursor = $primero->modify('-' . ((int) $primero->format('N') - 1) . ' days');
    $hoyStr = $hoy->format('Y-m-d');
    $semanas = [];
    while ($cursor <= $ultimo || $cursor->format('N') !== '1') {
        $semana = [];
        for ($i = 0; $i < 7; $i++) {
            $f = $cursor->format('Y-m-d');
            $semana[] = [
                'fecha'    => $f,
                'numero'   => (int) $cursor->format('j'),
                'en_mes'   => $cursor->format('n') === (string) $mes,
                'hoy'      => $f === $hoyStr,
                'partidos' => $porDia[$f] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }
        $semanas[] = $semana;
        if (count($semanas) >= 6) {
            break;
        }
    }
    return $semanas;
}

/** Resumen del mes para un conjunto de equipos: jugados, G-E-P y próximos. */
function cal_resumen(array $partidos, array $equipoIds): array
{
    $r = ['jugados' => 0, 'g' => 0, 'e' => 0, 'p' => 0, 'proximos' => 0];
    foreach ($partidos as $p) {
        $foco = cal_equipo_foco($p, $equipoIds);
        if ($foco === null) {
            continue;
        }
        if ($p['estado'] === 'finalizado') {
            $res = cal_perspectiva($p, $foco)['resultado'];
            if ($res !== '') {
                $r['jugados']++;
                $r[strtolower($res)]++;
            }
        } elseif (in_array($p['estado'], ['programado', 'en_curso'], true)) {
            $r['proximos']++;
        }
    }
    return $r;
}

/** Texto tipo "hoy", "mañana", "en 5 días" para un día local. */
function cal_relativo(DateTimeImmutable $dia, DateTimeImmutable $hoy): string
{
    $d = (int) $hoy->setTime(0, 0)->diff($dia->setTime(0, 0))->format('%r%a');
    return match (true) {
        $d === 0 => 'hoy',
        $d === 1 => 'mañana',
        $d === -1 => 'ayer',
        $d > 1   => "en $d días",
        default  => 'hace ' . abs($d) . ' días',
    };
}

/** Fecha larga en español: "Lunes 12 de octubre". */
function cal_fecha_larga(DateTimeImmutable $d): string
{
    return CAL_DIAS[(int) $d->format('N')] . ' ' . $d->format('j') . ' de ' . strtolower(CAL_MESES[(int) $d->format('n')]);
}

/** Hora corta "3:00 PM". */
function cal_hora(DateTimeImmutable $d): string
{
    return $d->format('g:i A');
}