<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * PUENTE DE EQUIPOS — PASO 1: DIAGNOSTICO (solo lectura)
 *
 * Compara los equipos que devuelve football-data.org para una
 * competicion contra los equipos que YA existen en la base (sembrados
 * antes con api-sports, fuente 'api-sports-futbol') para la liga
 * historica equivalente. Busca coincidencias por nombre normalizado
 * (sin acentos, mayusculas, sufijos comunes como FC/CF/AFC).
 *
 * NO escribe nada en la base. Solo imprime en pantalla que equipo de
 * football-data matcheo con que equipo existente, y cuales NO
 * encontraron pareja (para revisión manual).
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" puente_equipos_diagnostico.php BSA 27
 *
 * Donde:
 *   BSA = codigo de la competicion en football-data.org
 *   27  = id interno (columna id de ligas_torneos) de la liga
 *         HISTORICA equivalente en tu base (la de api-sports)
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$codigoLiga = $argv[1] ?? null;
$idLigaHistorica = isset($argv[2]) ? (int)$argv[2] : null;

if (!$codigoLiga || !$idLigaHistorica) {
    echo "Uso: php puente_equipos_diagnostico.php <CODIGO_FOOTBALL_DATA> <ID_LIGA_HISTORICA>\n";
    echo "Ejemplo: php puente_equipos_diagnostico.php BSA 27\n";
    exit(1);
}

$apiKey = getenv('FOOTBALL_DATA_API_KEY') ?: ($_ENV['FOOTBALL_DATA_API_KEY'] ?? null);
if (!$apiKey) {
    echo "No se encontro FOOTBALL_DATA_API_KEY en el .env.\n";
    exit(1);
}

function sacarAcentos(string $texto): string
{
    // iconv//TRANSLIT tiene soporte incompleto en PHP para Windows (rompe
    // silenciosamente letras como e/o con acento en vez de convertirlas),
    // asi que usamos una tabla manual explicita en su lugar.
    $mapa = [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
        'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n',
    ];
    return strtr($texto, $mapa);
}

function normalizarPalabras(string $nombre): array
{
    $n = sacarAcentos($nombre);
    $n = strtolower($n);
    // Solo sacamos palabras genuinamente redundantes (formas societarias,
    // conectores). Las siglas cortas (CA, CR, SE, EC, SC, AF, etc.) NO se
    // sacan: aunque no sepamos que significan exactamente, suelen ser la
    // UNICA pista que distingue un club de otro con nombre parecido (ej.
    // "CA Mineiro" = Atletico Mineiro vs "America Mineiro" -- si borramos
    // "CA" quedan los dos con la sola palabra "Mineiro" y se confunden).
    $ruido = ['fc', 'cf', 'afc', 'sad', 'club', 'clube', 'de', 'da', 'do', 'dos', 'the'];
    $palabras = preg_split('/[\s\-\.]+/', $n);
    $palabras = array_filter($palabras, fn($p) => $p !== '' && !in_array($p, $ruido));
    return array_values($palabras);
}

function mejorCandidato(array $palabrasFD, array $indiceExistentes): ?array
{
    $mejor = null;
    $mejorScore = 0;
    foreach ($indiceExistentes as $existente) {
        $palabrasEx = normalizarPalabras($existente['nombre']);
        if (empty($palabrasEx) || empty($palabrasFD)) {
            continue;
        }
        $comunes = array_intersect($palabrasFD, $palabrasEx);
        // score = cuantas palabras del mas corto de los dos aparecen en el otro
        $base = min(count($palabrasFD), count($palabrasEx));
        if ($base === 0) {
            continue;
        }
        $score = count($comunes) / $base;
        if ($score > $mejorScore) {
            $mejorScore = $score;
            $mejor = $existente;
        }
    }
    return $mejor !== null && $mejorScore >= 0.5 ? [$mejor, $mejorScore] : null;
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);

// 1) Equipos ya existentes en la liga historica (api-sports)
$stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = ?');
$stmt->execute([$idLigaHistorica]);
$equiposExistentes = $stmt->fetchAll();

if (empty($equiposExistentes)) {
    echo "No hay equipos en la liga historica id=$idLigaHistorica. Revisa el ID.\n";
    exit(1);
}

$indiceExistentes = $equiposExistentes; // ahora comparamos contra la lista completa

// 2) Equipos de football-data para esa competicion
$resp = $http->get(
    "https://api.football-data.org/v4/competitions/$codigoLiga/teams",
    ["X-Auth-Token: $apiKey"]
);

if ($resp === null || empty($resp['teams'])) {
    echo "No se pudo obtener equipos de football-data para $codigoLiga.\n";
    exit(1);
}

echo "=== Comparando " . count($resp['teams']) . " equipos de football-data ($codigoLiga) ";
echo "contra " . count($equiposExistentes) . " equipos existentes (liga id=$idLigaHistorica) ===\n\n";

$matches = 0;
$sinMatch = [];

foreach ($resp['teams'] as $eqFD) {
    $palabrasFD = normalizarPalabras($eqFD['name']);
    $candidato = mejorCandidato($palabrasFD, $indiceExistentes);
    if ($candidato !== null) {
        [$existente, $score] = $candidato;
        $pct = round($score * 100);
        echo "OK ($pct%)  football-data #{$eqFD['id']} '{$eqFD['name']}'  <->  equipo interno #{$existente['id']} '{$existente['nombre']}'\n";
        $matches++;
    } else {
        $sinMatch[] = $eqFD;
    }
}

echo "\n=== Sin coincidencia automatica (" . count($sinMatch) . ") ===\n";
foreach ($sinMatch as $eqFD) {
    echo "?     football-data #{$eqFD['id']} '{$eqFD['name']}'\n";
}

echo "\nResumen: $matches / " . count($resp['teams']) . " equipos matcheados automaticamente.\n";
echo "Nada se escribio en la base todavia. Revisa la lista antes del proximo paso.\n";