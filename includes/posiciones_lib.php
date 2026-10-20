<?php
declare(strict_types=1);

/**
 * Cálculo de tablas de posiciones EN VIVO desde `partidos`.
 *
 * Por qué no se lee de `tabla_posiciones`: esa tabla guarda UNA fila por
 * (liga, equipo) y cada sincronización la recalcula mezclando todas las
 * temporadas y fases de la liga. Aquí cada liga declara desde qué fecha
 * empieza su temporada actual y cómo se divide (zonas, conferencias...).
 */

/** Fases que NO cuentan para una tabla de posiciones (eliminatorias, repechajes). */
const POS_FASES_EXCLUIDAS = [
    '%Relegation%', '%Promotion%', '%Play-off%', '%Playoff%', '%Play off%', '%Barrage%',
    '%Round of%', '%Quarterfinal%', '%Quarter-final%', '%Semifinal%', '%Semi-final%', '%- Final%',
];

/** Configuración por id de liga (ligas_torneos.id). */
function pos_config(): array
{
    $nba_oeste = ['Denver Nuggets', 'Utah Jazz', 'Minnesota Timberwolves', 'Oklahoma City Thunder', 'Portland Trail Blazers',
        'Los Angeles Clippers', 'Los Angeles Lakers', 'Golden State Warriors', 'Phoenix Suns', 'Sacramento Kings',
        'Memphis Grizzlies', 'New Orleans Pelicans', 'Dallas Mavericks', 'San Antonio Spurs', 'Houston Rockets'];
    $nba_este = ['Chicago Bulls', 'Milwaukee Bucks', 'Indiana Pacers', 'Detroit Pistons', 'Cleveland Cavaliers',
        'Charlotte Hornets', 'Orlando Magic', 'Atlanta Hawks', 'Miami Heat', 'Washington Wizards',
        'Toronto Raptors', 'New York Knicks', 'Brooklyn Nets', 'Boston Celtics', 'Philadelphia 76ers'];

    return [
        1  => ['etiqueta' => '2026-27', 'desde' => '2026-08-01', 'vista' => 'liga'],
        2  => ['etiqueta' => '2026-27', 'desde' => '2026-08-01', 'vista' => 'liga'],
        3  => ['etiqueta' => '2026-27', 'desde' => '2026-08-01', 'vista' => 'liga'],
        4  => ['etiqueta' => '2026-27', 'desde' => '2026-08-01', 'vista' => 'liga'],
        5  => ['etiqueta' => '2026-27', 'desde' => '2026-08-01', 'vista' => 'liga'],
        9  => ['etiqueta' => '2026', 'desde' => '2026-01-01', 'vista' => 'liga'],
        25 => ['etiqueta' => '2026-27', 'desde' => '2026-08-01', 'vista' => 'liga'],           // Serie B
        26 => ['etiqueta' => '2026-27 · Fase de liga', 'desde' => '2026-09-01', 'hasta' => '2027-01-31', 'vista' => 'champions'],
        28 => ['etiqueta' => '2026', 'desde' => '2026-01-01', 'vista' => 'argentina'],
        29 => ['etiqueta' => 'Apertura 2026', 'desde' => '2026-07-01', 'vista' => 'liga'],      // Liga MX
        30 => ['etiqueta' => '2026', 'desde' => '2026-02-01', 'vista' => 'liga'],
        18 => ['etiqueta' => '2026-27', 'desde' => '2026-10-20', 'vista' => 'nba',
               'grupos' => ['Oeste' => $nba_oeste, 'Este' => $nba_este]],
        19 => ['etiqueta' => '2026', 'desde' => '2026-03-01', 'hasta' => '2026-09-28', 'vista' => 'liga',
               'inicio_real' => '2026-03-26'],
        20 => ['etiqueta' => '2026', 'desde' => '2026-09-01', 'vista' => 'nfl',
               'grupos' => [
                   'AFC' => [
                       'Este'  => ['Buffalo Bills', 'Miami Dolphins', 'New England Patriots', 'New York Jets'],
                       'Norte' => ['Baltimore Ravens', 'Cincinnati Bengals', 'Cleveland Browns', 'Pittsburgh Steelers'],
                       'Sur'   => ['Houston Texans', 'Indianapolis Colts', 'Jacksonville Jaguars', 'Tennessee Titans'],
                       'Oeste' => ['Denver Broncos', 'Kansas City Chiefs', 'Las Vegas Raiders', 'Los Angeles Chargers'],
                   ],
                   'NFC' => [
                       'Este'  => ['Dallas Cowboys', 'New York Giants', 'Philadelphia Eagles', 'Washington Commanders'],
                       'Norte' => ['Chicago Bears', 'Detroit Lions', 'Green Bay Packers', 'Minnesota Vikings'],
                       'Sur'   => ['Atlanta Falcons', 'Carolina Panthers', 'New Orleans Saints', 'Tampa Bay Buccaneers'],
                       'Oeste' => ['Arizona Cardinals', 'Los Angeles Rams', 'San Francisco 49ers', 'Seattle Seahawks'],
                   ],
               ]],
    ];
}

/** Ligas para el desplegable: solo las que tienen configuración (= temporada actual). */
function pos_ligas_disponibles(PDO $pdo): array
{
    $cfg = pos_config();
    $ids = implode(',', array_map('intval', array_keys($cfg)));
    $filas = $pdo->query(
        "SELECT lt.id, lt.nombre, d.slug, d.nombre AS deporte
         FROM ligas_torneos lt JOIN deportes d ON d.id = lt.id_deporte
         WHERE lt.id IN ($ids)
         ORDER BY FIELD(d.slug, 'futbol', 'baloncesto', 'beisbol', 'futbol-americano'), d.nombre, lt.nombre"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as &$f) {
        $f['cfg'] = $cfg[(int) $f['id']];
    }
    return $filas;
}

/**
 * Partidos de la temporada actual de una liga (cualquier estado válido),
 * sin eliminatorias. $faseLike opcional (ej. '%lausura%').
 */
function pos_partidos(PDO $pdo, int $idLiga, array $cfg, ?string $faseLike = null): array
{
    $sql = "SELECT id_equipo_local AS loc, id_equipo_visita AS vis, marcador_local AS ml, marcador_visita AS mv,
                   estado, fecha_partido
            FROM partidos
            WHERE id_liga = :liga AND fecha_partido >= :desde
              AND estado IN ('programado','en_curso','finalizado')";
    $params = [':liga' => $idLiga, ':desde' => $cfg['desde'] . ' 00:00:00'];
    if (!empty($cfg['hasta'])) {
        $sql .= ' AND fecha_partido < :hasta';
        $params[':hasta'] = date('Y-m-d', strtotime($cfg['hasta'] . ' +1 day')) . ' 00:00:00';
    }
    foreach (POS_FASES_EXCLUIDAS as $i => $patron) {
        $sql .= " AND (fase IS NULL OR fase NOT LIKE :f$i)";
        $params[":f$i"] = $patron;
    }
    if ($faseLike !== null) {
        $sql .= ' AND fase LIKE :fase';
        $params[':fase'] = $faseLike;
    }
    $sql .= ' ORDER BY fecha_partido, id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Acumula estadísticas por equipo. Incluye equipos con 0 PJ (que solo
 * tienen partidos programados) para que la tabla muestre a todos.
 * Devuelve [id_equipo => stats] con nombre/escudo/grupo de `equipos`.
 */
function pos_calcular(PDO $pdo, array $partidos, bool $empate = true): array
{
    $t = [];
    $nuevo = static fn(): array => [
        'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'pts' => 0,
        'loc' => [0, 0, 0], 'vis' => [0, 0, 0], 'res' => [],
    ];
    $primera = null;

    foreach ($partidos as $p) {
        $a = (int) $p['loc'];
        $b = (int) $p['vis'];
        $t[$a] ??= $nuevo();
        $t[$b] ??= $nuevo();
        if ($p['estado'] !== 'finalizado' || $p['ml'] === null || $p['mv'] === null) {
            continue;
        }
        $primera ??= $p['fecha_partido'];
        $gl = (int) $p['ml'];
        $gv = (int) $p['mv'];
        foreach ([[$a, $gl, $gv, 'loc'], [$b, $gv, $gl, 'vis']] as [$id, $gf, $gc, $lado]) {
            $s = &$t[$id];
            $s['pj']++;
            $s['gf'] += $gf;
            $s['gc'] += $gc;
            if ($gf > $gc) {
                $s['pg']++; $s['pts'] += 3; $s[$lado][0]++; $s['res'][] = 'G';
            } elseif ($gf < $gc) {
                $s['pp']++; $s[$lado][1]++; $s['res'][] = 'P';
            } else {
                $s['pe']++; $s['pts'] += 1; $s[$lado][2]++; $s['res'][] = 'E';
            }
            unset($s);
        }
    }

    if (!$t) {
        return [[], null];
    }
    $ids = implode(',', array_map('intval', array_keys($t)));
    $info = $pdo->query("SELECT id, nombre, escudo, grupo FROM equipos WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($info as $e) {
        $id = (int) $e['id'];
        $t[$id] += ['nombre' => $e['nombre'], 'escudo' => $e['escudo'], 'grupo' => $e['grupo']];
    }
    foreach ($t as $id => &$s) {
        $s['id'] = $id;
        $s += ['nombre' => "Equipo #$id", 'escudo' => null, 'grupo' => null];
        $s['dif'] = $s['gf'] - $s['gc'];
        $jug = $s['pg'] + $s['pe'] + $s['pp'];
        $s['pct'] = $jug > 0 ? ($s['pg'] + 0.5 * $s['pe']) / $jug : 0.0;
    }
    unset($s);
    return [array_values($t), $primera];
}

/** Orden de fútbol: PTS, DIF, GF, nombre. */
function pos_ordenar_futbol(array $filas): array
{
    usort($filas, static fn($x, $y) => [$y['pts'], $y['dif'], $y['gf']] <=> [$x['pts'], $x['dif'], $x['gf']]
        ?: strcmp($x['nombre'], $y['nombre']));
    return $filas;
}

/** Orden por porcentaje (básquet, béisbol, NFL). */
function pos_ordenar_pct(array $filas): array
{
    usort($filas, static fn($x, $y) => [$y['pct'], $y['dif']] <=> [$x['pct'], $x['dif']]
        ?: strcmp($x['nombre'], $y['nombre']));
    return $filas;
}

/** Racha actual, ej. "G3" / "P1". */
function pos_racha(array $res): string
{
    if (!$res) return '-';
    $u = end($res);
    $n = 0;
    for ($i = count($res) - 1; $i >= 0 && $res[$i] === $u; $i--) $n++;
    return $u . $n;
}

/** Últimos N resultados como "7-3" (G-P, los empates NFL no cuentan en P). */
function pos_ultimos(array $res, int $n = 10): string
{
    $x = array_slice($res, -$n);
    if (!$x) return '0-0';
    $c = array_count_values($x);
    return ($c['G'] ?? 0) . '-' . ($c['P'] ?? 0);
}

/** Reparte filas en grupos según un mapa nombre => grupo. */
function pos_filtrar_por_nombres(array $filas, array $nombres): array
{
    $set = array_flip($nombres);
    return array_values(array_filter($filas, static fn($f) => isset($set[$f['nombre']])));
}