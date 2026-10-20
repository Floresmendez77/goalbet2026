<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * AGREGAR TODOS LOS EQUIPOS QUE FALTAN DE UNA LIGA (via ESPN).
 *
 * Pensado para Serie B, Liga MX y los equipos que faltan de la Champions:
 * baja el listado de equipos de la liga desde ESPN, revisa cuales NO existen
 * en tu base (con la misma regla de nombres que usa la sincronizacion) y
 * crea SOLO esos. Los que ya existen no se tocan.
 *
 * MODO POR DEFECTO: diagnostico (dry-run), no escribe nada. Agregar --aplicar.
 *
 * Uso:
 *   php agregar_equipos_faltantes_espn.php <id_liga_bd> <espn_slug> [id_pais] [--aplicar]
 *
 * Ejemplos (correr primero SIN --aplicar y revisar la lista):
 *   php agregar_equipos_faltantes_espn.php 25 ita.2 <id_pais_italia>
 *   php agregar_equipos_faltantes_espn.php 29 mex.1 <id_pais_mexico>
 *   php agregar_equipos_faltantes_espn.php 26 uefa.champions
 *
 * id_pais es opcional (puede quedar vacio; la Champions mezcla paises).
 * Despues de crear los equipos, correr la sincronizacion de esa liga desde
 * Admin > Refrescar partidos.
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

const FUENTE_FALTANTES = 'espn-futbol';

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--aplicar'));
$aplicar = in_array('--aplicar', $argv, true);
$idLigaBd = isset($args[0]) ? (int)$args[0] : 0;
$espnSlug = $args[1] ?? '';
$idPais = isset($args[2]) && $args[2] !== '' ? (int)$args[2] : null;

if (!$idLigaBd || $espnSlug === '') {
    fwrite(STDERR, "Uso: php agregar_equipos_faltantes_espn.php <id_liga_bd> <espn_slug> [id_pais] [--aplicar]\n");
    exit(1);
}

function sacarAcentosF(string $t): string
{
    return strtr($t, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
        'ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n','ø'=>'o','Ø'=>'o',
    ]);
}

/** Misma normalizacion que EspnSeeder::normalizarPalabras(). */
function palabrasF(string $nombre): array
{
    $n = strtolower(sacarAcentosF($nombre));
    $n = str_replace(['.', ','], '', $n);
    $ruido = ['fc', 'cf', 'afc', 'sad', 'club', 'clube', 'de', 'da', 'do', 'dos', 'the', 'red', 'bull'];
    $p = preg_split('/[\s\-]+/', $n);
    return array_values(array_filter($p, fn($x) => $x !== '' && !in_array($x, $ruido, true)));
}

/** Mejor coincidencia con el mismo criterio (y desempate) que la sincronizacion. */
function mejorCoincidenciaF(string $nombreEspn, array $equipos, int $idLiga): array
{
    $pe = palabrasF($nombreEspn);
    $mejor = null;
    $clave = [0.0, 0, 0.0];
    foreach ($equipos as $eq) {
        $pl = palabrasF($eq['nombre']);
        if (!$pl || !$pe) continue;
        $comunes = count(array_intersect($pe, $pl));
        $score = $comunes / min(count($pe), count($pl));
        $total = $comunes / max(count($pe), count($pl));
        $k = [$score, ((int)$eq['id_liga'] === $idLiga) ? 1 : 0, $total];
        if (($k <=> $clave) > 0) { $clave = $k; $mejor = $eq; }
    }
    return [$mejor, $clave[0]];
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);
$repo = new Repository($pdo, $logger);

echo $aplicar
    ? "=== MODO APLICAR: esto SI escribe en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): no se escribe nada. Agregar --aplicar para crear ===\n\n";

$liga = $pdo->prepare('SELECT nombre FROM ligas_torneos WHERE id = ?');
$liga->execute([$idLigaBd]);
$nombreLiga = $liga->fetchColumn();
if ($nombreLiga === false) {
    fwrite(STDERR, "No existe la liga id=$idLigaBd en ligas_torneos.\n");
    exit(1);
}

$resp = $http->get("https://site.api.espn.com/apis/site/v2/sports/soccer/{$espnSlug}/teams?limit=100", []);
$listado = $resp['sports'][0]['leagues'][0]['teams'] ?? [];
echo "Liga $idLigaBd ($nombreLiga) - ESPN '$espnSlug' devolvio " . count($listado) . " equipo(s).\n\n";
if (!$listado) {
    echo "ESPN no devolvio equipos. Revisar el slug o la conexion (cacert.pem).\n";
    exit(1);
}

$idDeporte = $repo->idDeportePorSlug('futbol');
$st = $pdo->prepare('SELECT id, nombre, id_liga FROM equipos WHERE id_deporte = ?');
$st->execute([$idDeporte]);
$equipos = $st->fetchAll(PDO::FETCH_ASSOC);

$existen = $faltan = 0;
foreach ($listado as $item) {
    $t = $item['team'] ?? [];
    $idExterno = (string)($t['id'] ?? '');
    $nombre = $t['displayName'] ?? $t['name'] ?? '';
    if ($idExterno === '' || $nombre === '') continue;

    $vinculado = $repo->resolverExternalId(FUENTE_FALTANTES, 'equipo', $idExterno);
    if ($vinculado !== null) {
        $existen++;
        echo str_pad($nombre, 32) . " YA VINCULADO (equipo #$vinculado)\n";
        continue;
    }

    [$mejor, $score] = mejorCoincidenciaF($nombre, $equipos, $idLigaBd);
    if ($mejor !== null && $score >= 0.6) {
        $existen++;
        echo str_pad($nombre, 32) . " existe como '{$mejor['nombre']}' (#{$mejor['id']}); la sincronizacion lo vincula sola\n";
        continue;
    }

    $faltan++;
    echo str_pad($nombre, 32) . " FALTA" . ($aplicar ? '' : ' (se crearia)') . "\n";
    if ($aplicar) {
        $logo = $t['logos'][0]['href'] ?? null;
        $id = $repo->upsertEquipo(
            FUENTE_FALTANTES, $idDeporte, $idLigaBd, $idPais, null,
            $nombre, null, null, $logo, null, null, $idExterno
        );
        echo $id !== null ? "   -> creado como equipo #$id\n" : "   -> FALLO, revisar seeder/logs/\n";
    }
}

echo "\nResumen: $existen ya existen, $faltan " . ($aplicar ? 'procesados' : 'faltan (no se creo nada)') . ".\n";
if (!$aplicar && $faltan > 0) {
    echo "Revisa la lista; si esta bien, correr de nuevo con --aplicar.\n";
}