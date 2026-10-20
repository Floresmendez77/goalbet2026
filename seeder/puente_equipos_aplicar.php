<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * PUENTE DE EQUIPOS — PASO 2: APLICAR (este si escribe en la base)
 *
 * Registra en external_ids que un equipo de football-data ES el mismo
 * equipo que ya existe en la base (sembrado antes con api-sports), para
 * que al traer partidos futuros de football-data el sistema reutilice
 * el equipo existente en vez de crear uno duplicado.
 *
 * Estrategia (despues de que el matching 100% automatico probo estar
 * equivocado dos veces con clubes brasileños de siglas ambiguas, ver
 * CA Mineiro / EC Vitoria):
 *   1. Los pares en $overrides de abajo mandan siempre (confirmados a
 *      mano). Poner null como destino significa "NO vincular este equipo,
 *      dejar que se cree como equipo nuevo".
 *   2. Para el resto, SOLO se acepta automatico si el score es exactamente
 *      100% (1.0). Cualquier cosa por debajo se dejsa sin vincular y se
 *      lista al final para revision manual -- no se arriesga con dudas.
 *
 * Es idempotente: si un vinculo ya existe en external_ids, lo saltea sin
 * error. Se puede correr mas de una vez sin problema.
 *
 * Correr desde la carpeta seeder/ con:
 *   & "C:\MAMP\bin\php\php8.3.1\php.exe" puente_equipos_aplicar.php PL 15
 *
 * NOTA: este bloque de $overrides quedo configurado para PREMIER LEAGUE
 * (PL/15).
 *
 * IMPORTANTE sobre este caso: Arsenal(103) y Manchester City(109) ya
 * estan tageados con id_liga=26 (Champions League), porque ambos juegan
 * los dos torneos. Por eso el diagnostico no los encontro en la liga 15
 * (un equipo solo puede tener un id_liga a la vez). NO se hizo ningun
 * UPDATE para "moverlos" de vuelta a la liga 15 -- eso hubiera roto el
 * vinculo que ya tienen con Champions. En su lugar, se los vincula
 * directo por override: el override no depende de que el equipo este en
 * la lista de "existentes filtrados por liga", inserta el vinculo igual.
 *
 * Leeds United, Sunderland, Hull City, Ipswich Town y Coventry City NO
 * estan en los 20 equipos historicos de la liga 15 (temporada 2023) --
 * son altas nuevas genuinas (ascendidos/promovidos), no requieren UPDATE
 * ni tienen equipo previo que vincular.
 *
 * PENDIENTE DESPUES DE ESTO (no lo hace este script): la liga "futuro"
 * (id=1) ya tenia 380 partidos cargados de una sesion anterior, antes
 * de que existiera el puente, apuntando a equipos DUPLICADOS. Este
 * script solo arma el mapeo en external_ids -- falta un script de
 * migracion aparte que actualice esos 380 partidos.id_equipo_local /
 * id_equipo_visita para que apunten a los equipos correctos, y despues
 * limpiar los duplicados huerfanos.
 * ------------------------------------------------------------------
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$codigoLiga = $argv[1] ?? null;
$idLigaHistorica = isset($argv[2]) ? (int)$argv[2] : null;

if (!$codigoLiga || !$idLigaHistorica) {
    echo "Uso: php puente_equipos_aplicar.php <CODIGO_FOOTBALL_DATA> <ID_LIGA_HISTORICA>\n";
    exit(1);
}

// ------------------------------------------------------------------
// CORRECCIONES MANUALES (confirmadas por el usuario, no tocar sin
// confirmar antes). Clave: id de equipo en football-data.
// Valor: id interno del equipo en la base, o null para "no vincular".
// ------------------------------------------------------------------
$overrides = [
    57   => 103,  // 'Arsenal FC' -> Arsenal (ya tageado a Champions, se vincula igual)
    65   => 109,  // 'Manchester City FC' -> Manchester City (ya tageado a Champions, se vincula igual)
    341  => null, // 'Leeds United FC' -> no existe en la base 2023, no vincular (nuevo; OJO no confundir con Manchester United)
    71   => null, // 'Sunderland AFC' -> no existe en la base 2023, no vincular (nuevo)
    322  => null, // 'Hull City AFC' -> no existe en la base 2023, no vincular (nuevo)
    349  => null, // 'Ipswich Town FC' -> no existe en la base 2023, no vincular (nuevo)
    1076 => null, // 'Coventry City FC' -> no existe en la base 2023, no vincular (nuevo)
];

$apiKey = getenv('FOOTBALL_DATA_API_KEY') ?: ($_ENV['FOOTBALL_DATA_API_KEY'] ?? null);
if (!$apiKey) {
    echo "No se encontro FOOTBALL_DATA_API_KEY en el .env.\n";
    exit(1);
}

function sacarAcentos(string $texto): string
{
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
    return $mejor !== null ? [$mejor, $mejorScore] : null;
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);

$stmt = $pdo->prepare('SELECT id, nombre FROM equipos WHERE id_liga = ?');
$stmt->execute([$idLigaHistorica]);
$equiposExistentes = $stmt->fetchAll();

$resp = $http->get(
    "https://api.football-data.org/v4/competitions/$codigoLiga/teams",
    ["X-Auth-Token: $apiKey"]
);

if ($resp === null || empty($resp['teams'])) {
    echo "No se pudo obtener equipos de football-data para $codigoLiga.\n";
    exit(1);
}

$existeVinculo = $pdo->prepare(
    'SELECT entidad_id FROM external_ids WHERE fuente = ? AND entidad = ? AND external_id = ? LIMIT 1'
);
$insertarVinculo = $pdo->prepare(
    'INSERT INTO external_ids (entidad, entidad_id, fuente, external_id) VALUES (?, ?, ?, ?)'
);

$vinculados = 0;
$yaExistian = 0;
$omitidos = 0;
$sinVincular = [];

foreach ($resp['teams'] as $eqFD) {
    $idFD = (string)$eqFD['id'];

    // 1) Ya vinculado de antes -> no hacer nada
    $existeVinculo->execute(['football-data', 'equipo', $idFD]);
    if ($existeVinculo->fetchColumn() !== false) {
        echo "=     football-data #{$idFD} '{$eqFD['name']}' ya estaba vinculado, se omite\n";
        $yaExistian++;
        continue;
    }

    // 2) Override manual explicito
    if (array_key_exists($eqFD['id'], $overrides)) {
        $destino = $overrides[$eqFD['id']];
        if ($destino === null) {
            echo "-     football-data #{$idFD} '{$eqFD['name']}' -> override: NO vincular\n";
            $omitidos++;
            continue;
        }
        $insertarVinculo->execute(['equipo', $destino, 'football-data', $idFD]);
        echo "OK    football-data #{$idFD} '{$eqFD['name']}'  ->  equipo interno #{$destino} (override manual)\n";
        $vinculados++;
        continue;
    }

    // 3) Automatico, solo si es 100% exacto
    $palabrasFD = normalizarPalabras($eqFD['name']);
    $candidato = mejorCandidato($palabrasFD, $equiposExistentes);
    if ($candidato !== null && $candidato[1] >= 1.0) {
        $existente = $candidato[0];
        $insertarVinculo->execute(['equipo', $existente['id'], 'football-data', $idFD]);
        echo "OK    football-data #{$idFD} '{$eqFD['name']}'  ->  equipo interno #{$existente['id']} '{$existente['nombre']}' (100% automatico)\n";
        $vinculados++;
    } else {
        $sinVincular[] = $eqFD;
    }
}

echo "\n=== Sin vincular (" . count($sinVincular) . ") -- se crearan como equipos nuevos al traer partidos ===\n";
foreach ($sinVincular as $eqFD) {
    echo "?     football-data #{$eqFD['id']} '{$eqFD['name']}'\n";
}

echo "\nResumen: $vinculados vinculados ahora | $yaExistian ya existian | $omitidos omitidos por override | " . count($sinVincular) . " sin vincular.\n";