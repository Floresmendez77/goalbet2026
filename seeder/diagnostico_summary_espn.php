<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_summary_espn.php
//
// POR QUE EXISTE: para mostrar en la pagina de un partido el estadio, la
// alineacion con posiciones, y la linea de tiempo (quien hizo que y en que
// minuto) hace falta el endpoint "summary" de ESPN, que todavia no usamos
// (hoy solo usamos "scoreboard"). Siguiendo la regla del proyecto, primero se
// vuelca lo que devuelve de verdad y recien despues se escribe el importador.
//
// Uso:
//   php diagnostico_summary_espn.php <slug_liga> <id_evento_espn>
//   php diagnostico_summary_espn.php --archivo=ruta/al/summary.json
//
// Ejemplo (partido #9159 de Brasileirao, id de ESPN 401841255):
//   php diagnostico_summary_espn.php bra.1 401841255
//
// Guarda el JSON completo en seeder/logs/summary_<id>.json para poder revisarlo.

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/Logger.php';

function dd_titulo(string $t): void { echo "\n=== $t ===\n"; }

function dd_nombres(array $lista): string
{
    $n = [];
    foreach ($lista as $p) {
        $n[] = $p['athlete']['displayName'] ?? $p['displayName'] ?? '?';
    }
    return implode(', ', $n);
}

function dd_analizar(array $d): void
{
    dd_titulo('Claves de primer nivel');
    echo implode(', ', array_keys($d)) . "\n";

    dd_titulo('ESTADIO / INFO DEL PARTIDO (gameInfo)');
    $gi = $d['gameInfo'] ?? null;
    if (!$gi) {
        echo "(no viene gameInfo)\n";
    } else {
        $v = $gi['venue'] ?? [];
        echo 'Estadio:    ' . ($v['fullName'] ?? '(sin dato)') . "\n";
        echo 'Ciudad:     ' . ($v['address']['city'] ?? '(sin dato)') . ' ' . ($v['address']['country'] ?? '') . "\n";
        echo 'Capacidad:  ' . ($v['capacity'] ?? '(sin dato)') . "\n";
        echo 'Asistencia: ' . ($gi['attendance'] ?? '(sin dato)') . "\n";
        $arb = $gi['officials'] ?? [];
        echo 'Arbitros:   ' . ($arb ? dd_nombres($arb) : '(sin dato)') . "\n";
    }

    dd_titulo('ALINEACIONES (rosters)');
    $rosters = $d['rosters'] ?? [];
    if (!$rosters) {
        echo "(no viene rosters: ESPN no publica alineacion para este partido)\n";
    }
    foreach ($rosters as $r) {
        $equipo = $r['team']['displayName'] ?? '?';
        $jug = $r['roster'] ?? [];
        $titulares = array_filter($jug, fn($j) => !empty($j['starter']));
        echo "\n{$equipo} ({$r['homeAway']}) formacion: " . ($r['formation'] ?? '(sin dato)')
            . ' | jugadores: ' . count($jug) . ' | titulares: ' . count($titulares) . "\n";
        foreach (array_slice($jug, 0, 14) as $j) {
            printf(
                "  #%-3s %-26s pos=%-4s lugar=%-3s %s%s\n",
                $j['jersey'] ?? '-',
                $j['athlete']['displayName'] ?? '?',
                $j['position']['abbreviation'] ?? '-',
                $j['formationPlace'] ?? '-',
                !empty($j['starter']) ? 'TITULAR' : 'banca',
                (!empty($j['subbedIn']) ? ' [entro]' : '') . (!empty($j['subbedOut']) ? ' [salio]' : '')
            );
        }
        if ($jug) {
            echo '  Claves de stats del 1er jugador: '
                . implode(', ', array_map(fn($s) => $s['name'] ?? '?', $jug[0]['stats'] ?? [])) . "\n";
        }
    }

    dd_titulo('LINEA DE TIEMPO (quien hizo que y cuando)');
    $fuentes = [
        'keyEvents' => $d['keyEvents'] ?? [],
        'plays' => $d['plays'] ?? [],
        'header.competitions[0].details' => $d['header']['competitions'][0]['details'] ?? [],
    ];
    foreach ($fuentes as $nombre => $lista) {
        echo "\n[$nombre] " . count($lista) . " entradas\n";
        foreach (array_slice($lista, 0, 20) as $e) {
            $min = $e['clock']['displayValue'] ?? '?';
            $tipo = $e['type']['text'] ?? $e['type']['type'] ?? '?';
            $equipo = $e['team']['displayName'] ?? ($e['team']['id'] ?? '');
            $quienes = dd_nombres($e['participants'] ?? $e['athletesInvolved'] ?? []);
            echo "  $min  $tipo  $equipo  $quienes\n";
        }
    }

    dd_titulo('ESTADISTICAS DE EQUIPO (boxscore)');
    foreach ($d['boxscore']['teams'] ?? [] as $t) {
        echo ($t['team']['displayName'] ?? '?') . ': '
            . implode(', ', array_map(fn($s) => ($s['name'] ?? '?') . '=' . ($s['displayValue'] ?? '?'), $t['statistics'] ?? [])) . "\n";
    }
}

// ---- main ----
$archivo = null;
$pos = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--archivo=')) { $archivo = substr($a, 10); } else { $pos[] = $a; }
}

if ($archivo) {
    $data = json_decode((string) file_get_contents($archivo), true);
    if (!is_array($data)) { echo "No pude leer JSON de $archivo\n"; exit(1); }
    dd_analizar($data);
    exit(0);
}

if (count($pos) < 2) {
    echo "Uso: php diagnostico_summary_espn.php <slug_liga> <id_evento_espn>\n";
    echo "Ejemplo: php diagnostico_summary_espn.php bra.1 401841255\n";
    exit(1);
}
[$slug, $evento] = $pos;

$logger = new Logger();
$http = new HttpClient($logger);
$url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$slug}/summary?event={$evento}";
echo "URL: $url\n";
$data = $http->get($url, []);
if (!is_array($data)) { echo "ESPN no devolvio JSON valido.\n"; exit(1); }

$dir = __DIR__ . '/logs';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$ruta = "$dir/summary_{$evento}.json";
file_put_contents($ruta, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "JSON completo guardado en: $ruta\n";

dd_analizar($data);