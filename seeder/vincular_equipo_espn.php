<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * VINCULAR EQUIPO CON ESPN A MANO — para los casos donde el nombre en
 * tu base (ej. "Atletico Paranaense") no matchea >=60% contra el
 * nombre que usa ESPN (ej. "Athletico-PR") y por eso los seeders
 * automaticos (EspnSeeder / actualizar_resultados_espn.php) siempre
 * omiten ese equipo.
 *
 * Como funciona (y por que es un arreglo DEFINITIVO, no un parche):
 *   Repository::resolverExternalId() consulta la tabla `external_ids`
 *   ANTES de intentar matchear por nombre. Este script inserta el
 *   vinculo manual una sola vez (fuente='espn', entidad='equipo',
 *   entidad_id=<tu id>, external_id=<id numerico de ESPN>). De ahi en
 *   adelante, cualquier script que use EspnSeeder va a encontrar el
 *   equipo directo por ese vinculo, sin volver a intentar matchear
 *   por nombre.
 *
 * PASO 1 -- buscar (sin --espn-id, no escribe nada):
 *   Lista los equipos de ESPN mas parecidos al nombre de tu equipo,
 *   CON el umbral del 60% desactivado, para que puedas ver el
 *   candidato correcto aunque el score sea bajo.
 *
 *   php vincular_equipo_espn.php <deporte_slug_local> <liga_espn> <id_equipo_bd>
 *
 *   <deporte_slug_local> es el MISMO slug en espanol que le pasas a
 *   actualizar_resultados_espn.php como primer argumento ('futbol',
 *   'baloncesto', 'beisbol', 'futbol-americano') -- NO el nombre en
 *   ingles que usa la URL de ESPN. El script traduce uno al otro
 *   solo, y arma el mismo `fuente` ('espn-' . deporte_slug_local) que
 *   usa EspnSeeder internamente, para que el vinculo realmente
 *   lo encuentre.
 *
 *   Ejemplo (Atletico Paranaense = id 407 en tu tabla equipos):
 *   php vincular_equipo_espn.php futbol bra.1 407
 *
 * PASO 2 -- vincular (una vez que confirmaste el ID de ESPN):
 *   php vincular_equipo_espn.php futbol bra.1 407 --espn-id=XXXX --aplicar
 *
 * Slugs de deporte validos (deben coincidir con SPORT_PATHS de
 * EspnSeeder.php): futbol, baloncesto, beisbol, futbol-americano.
 * Slugs de liga de ESPN: bra.1 (Brasileirao), eng.1 (Premier League),
 * esp.1 (La Liga), nfl, nba, mlb, nhl, uefa.champions.
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';

Env::load(__DIR__ . '/.env');

/** debe ser identico a EspnSeeder::SPORT_PATHS -- si ese mapa cambia, cambiar aca tambien */
const SPORT_PATHS = [
    'futbol'           => 'soccer',
    'baloncesto'       => 'basketball',
    'beisbol'          => 'baseball',
    'futbol-americano' => 'football',
];

$deporteSlugLocal = $argv[1] ?? null;
$ligaEspn = $argv[2] ?? null;
$idEquipoBd = isset($argv[3]) ? (int)$argv[3] : null;
$aplicar = in_array('--aplicar', $argv, true);

$espnId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--espn-id=')) {
        $espnId = substr($arg, strlen('--espn-id='));
    }
}

if (!$deporteSlugLocal || !$ligaEspn || !$idEquipoBd) {
    fwrite(STDERR, "Uso: php vincular_equipo_espn.php <deporte_slug_local> <liga_espn> <id_equipo_bd> [--espn-id=XXXX --aplicar]\n");
    fwrite(STDERR, "Ejemplo: php vincular_equipo_espn.php futbol bra.1 407\n");
    fwrite(STDERR, "Deportes validos: " . implode(', ', array_keys(SPORT_PATHS)) . "\n");
    exit(1);
}

if (!isset(SPORT_PATHS[$deporteSlugLocal])) {
    fwrite(STDERR, "Deporte '$deporteSlugLocal' no reconocido. Usar uno de: " . implode(', ', array_keys(SPORT_PATHS)) . "\n");
    exit(1);
}

$deporteEspn = SPORT_PATHS[$deporteSlugLocal];
$fuente = 'espn-' . $deporteSlugLocal;

function sacarAcentosVinc(string $texto): string
{
    $mapa = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n','ø'=>'o','Ø'=>'o',
    ];
    return strtr($texto, $mapa);
}

function normalizarPalabrasVinc(string $nombre): array
{
    $n = strtolower(sacarAcentosVinc($nombre));
    $n = str_replace(['.', ',', '-'], ' ', $n);
    $ruido = ['fc', 'cf', 'afc', 'sad', 'club', 'the', 'ec'];
    $palabras = preg_split('/\s+/', trim($n));
    $palabras = array_filter($palabras, fn($p) => $p !== '' && !in_array($p, $ruido, true));
    return array_values($palabras);
}

/** score laxo: cuenta letras en comun tambien, no solo palabras completas */
function scoreLaxo(string $a, string $b): float
{
    $pa = normalizarPalabrasVinc($a);
    $pb = normalizarPalabrasVinc($b);
    if (empty($pa) || empty($pb)) {
        return 0.0;
    }
    $comunes = 0;
    foreach ($pa as $wa) {
        foreach ($pb as $wb) {
            similar_text($wa, $wb, $pct);
            if ($pct >= 70.0) {
                $comunes++;
                break;
            }
        }
    }
    $base = min(count($pa), count($pb));
    return $base === 0 ? 0.0 : $comunes / $base;
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);

$stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id = :id');
$stmt->execute([':id' => $idEquipoBd]);
$equipoBd = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$equipoBd) {
    echo "No existe ningun equipo con id = $idEquipoBd en tu base.\n";
    exit(1);
}

echo "Equipo en tu base: #{$equipoBd['id']} \"{$equipoBd['nombre']}\"\n\n";

$resp = $http->get("https://site.api.espn.com/apis/site/v2/sports/{$deporteEspn}/{$ligaEspn}/teams?limit=100", []);
$equiposEspn = $resp['sports'][0]['leagues'][0]['teams'] ?? null;
if (empty($equiposEspn)) {
    echo "No se pudo obtener la lista de equipos de ESPN para {$deporteEspn}/{$ligaEspn}.\n";
    exit(1);
}

if ($espnId === null) {
    // PASO 1: listar candidatos, umbral desactivado
    $candidatos = [];
    foreach ($equiposEspn as $item) {
        $eq = $item['team'];
        $nombreEspn = $eq['displayName'] ?? $eq['name'] ?? '';
        $score = scoreLaxo($equipoBd['nombre'], $nombreEspn);
        $candidatos[] = ['espnId' => $eq['id'], 'nombre' => $nombreEspn, 'score' => $score];
    }
    usort($candidatos, fn($a, $b) => $b['score'] <=> $a['score']);

    echo "Candidatos de ESPN mas parecidos (de mayor a menor similitud):\n\n";
    foreach (array_slice($candidatos, 0, 8) as $c) {
        printf("  espn-id=%-8s %-30s (similitud: %.0f%%)\n", $c['espnId'], $c['nombre'], $c['score'] * 100);
    }
    echo "\nSi el correcto esta en la lista, correr de nuevo asi:\n";
    echo "  php vincular_equipo_espn.php $deporteEspn $ligaEspn $idEquipoBd --espn-id=<espn-id de la lista> --aplicar\n";
    exit(0);
}

// PASO 2: vincular
$nombreEspnElegido = null;
foreach ($equiposEspn as $item) {
    if ((string)$item['team']['id'] === (string)$espnId) {
        $nombreEspnElegido = $item['team']['displayName'] ?? $item['team']['name'] ?? '(sin nombre)';
        break;
    }
}
if ($nombreEspnElegido === null) {
    echo "OJO: el espn-id=$espnId no aparece en la lista de equipos de {$deporteEspn}/{$ligaEspn}. Revisa el ID antes de continuar.\n";
    exit(1);
}

echo "Se va a vincular (fuente='$fuente'): tu equipo #{$idEquipoBd} \"{$equipoBd['nombre']}\" <-> ESPN #{$espnId} \"{$nombreEspnElegido}\"\n";

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si el nombre de ESPN de arriba es el correcto, agregar --aplicar.\n";
    exit(0);
}

$stmt = $pdo->prepare(
    'SELECT entidad_id FROM external_ids WHERE fuente = :fuente AND entidad = :entidad AND external_id = :ext'
);
$stmt->execute([':fuente' => $fuente, ':entidad' => 'equipo', ':ext' => (string)$espnId]);
$yaVinculado = $stmt->fetchColumn();

if ($yaVinculado) {
    echo "Ese espn-id ya esta vinculado a tu equipo #$yaVinculado. No se hace nada (para evitar 2 equipos con el mismo vinculo).\n";
    exit(0);
}

$stmt = $pdo->prepare(
    'INSERT INTO external_ids (entidad, entidad_id, fuente, external_id) VALUES (:entidad, :entidad_id, :fuente, :ext)'
);
$stmt->execute([
    ':entidad' => 'equipo',
    ':entidad_id' => $idEquipoBd,
    ':fuente' => $fuente,
    ':ext' => (string)$espnId,
]);

$logger->updated("equipo #{$idEquipoBd} \"{$equipoBd['nombre']}\" vinculado a ESPN #{$espnId} \"{$nombreEspnElegido}\"");
echo "\nListo. De ahora en adelante los seeders de ESPN van a encontrar este equipo directo, sin matchear por nombre.\n";