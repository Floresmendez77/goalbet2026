<?php

/**
 * arreglar_escudos_mlb.php
 *
 * Arregla el campo `escudo` de los equipos de MLB (id_deporte=3, id_liga=19)
 * que quedaron apuntando a URLs de american-football por un bug de un
 * seeder anterior. Trae los 30 equipos reales de MLB con UNA sola llamada
 * a la API y actualiza por coincidencia de nombre normalizado.
 *
 * Uso: correr desde navegador o CLI:
 *   php arreglar_escudos_mlb.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
if (php_sapi_name() !== 'cli') {
    requerir_admin();
}

header('Content-Type: text/plain; charset=utf-8');

const LIGA_MLB_API = 1;      // id de la MLB en api-baseball.api-sports.io
const TEMPORADA    = 2023;
const ID_DEPORTE   = 3;      // beisbol
const ID_LIGA_LOCAL = 19;    // id interno de la liga MLB en ligas_torneos

$apiKey = Env::get('API_SPORTS_KEY', '') ?: 'TU_API_KEY_AQUI_NO_SETEADA';
if ($apiKey === 'TU_API_KEY_AQUI_NO_SETEADA') {
    echo "AVISO: no se encontro API_SPORTS_KEY en el entorno, usando placeholder (la llamada va a fallar).\n\n";
}

/**
 * Normaliza un nombre para poder comparar 'LA Dodgers' con 'Los Angeles Dodgers',
 * sacando acentos, mayusculas y espacios repetidos.
 */
function normalizarNombre(string $nombre): string
{
    // Se evita mb_strtolower() a proposito: esta instalacion de PHP no tiene
    // la extension mbstring cargada, y los nombres de equipos de MLB son
    // todos ASCII, asi que strtolower() nativo alcanza sin problema.
    $n = strtolower(trim($nombre));
    $n = strtr($n, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
    ]);
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? $n;
    return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
}

// ------------------------------------------------------------------
// 1) UNA sola llamada a la API: todos los equipos de MLB de un jalon
// ------------------------------------------------------------------
$url = "https://v1.baseball.api-sports.io/teams?league=" . LIGA_MLB_API . "&season=" . TEMPORADA;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["x-apisports-key: $apiKey"],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NO_REVOKE,
]);
$body = curl_exec($ch);

if ($body === false) {
    fwrite(STDERR, "ERROR cURL: " . curl_error($ch) . PHP_EOL);
    echo "✘ No se pudo conectar a la API: " . curl_error($ch) . PHP_EOL;
    curl_close($ch);
    exit(1);
}
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "✘ La respuesta de la API no es JSON valido (HTTP $httpStatus).\n";
    echo substr($body, 0, 500) . PHP_EOL;
    exit(1);
}

if (!empty($data['errors'])) {
    echo "✘ La API devolvio errores: " . json_encode($data['errors']) . PHP_EOL;
    exit(1);
}

$equiposApi = $data['response'] ?? [];
if (count($equiposApi) === 0) {
    echo "✘ La API devolvio 0 equipos. Revisa la temporada o el plan de tu API key.\n";
    exit(1);
}

echo "✔ API respondio " . count($equiposApi) . " equipos de MLB.\n\n";

// Mapa nombre_normalizado => logo, para comparar contra la base local
$mapaApiPorNombre = [];
foreach ($equiposApi as $eq) {
    $nombreApi = $eq['name'] ?? '';
    $logoApi   = $eq['logo'] ?? null;
    if ($nombreApi === '' || $logoApi === null) {
        continue;
    }
    $mapaApiPorNombre[normalizarNombre($nombreApi)] = [
        'nombre' => $nombreApi,
        'logo'   => $logoApi,
    ];
}

// ------------------------------------------------------------------
// 2) Equipos locales con el escudo corrupto (apuntando a american-football)
// ------------------------------------------------------------------
try {
    $stmt = db()->prepare(
        "SELECT id, nombre, escudo FROM equipos
         WHERE id_deporte = :dep AND id_liga = :liga AND escudo LIKE '%/american-football/%'"
    );
    $stmt->execute([':dep' => ID_DEPORTE, ':liga' => ID_LIGA_LOCAL]);
    $equiposLocales = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "✘ Error consultando la base de datos: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo "Equipos locales con escudo corrupto encontrados: " . count($equiposLocales) . "\n";
echo str_repeat('-', 60) . "\n\n";

if (count($equiposLocales) === 0) {
    echo "Nada que arreglar, no se encontraron equipos con escudo de american-football.\n";
    exit(0);
}

// ------------------------------------------------------------------
// 3) y 4) Comparar por nombre normalizado y actualizar
// ------------------------------------------------------------------
$updateStmt = db()->prepare("UPDATE equipos SET escudo = :escudo WHERE id = :id");

$actualizados = 0;
$sinCoincidencia = [];

foreach ($equiposLocales as $local) {
    $clave = normalizarNombre($local['nombre']);
    $match = $mapaApiPorNombre[$clave] ?? null;

    if ($match === null) {
        $sinCoincidencia[] = $local;
        echo "✘ SIN COINCIDENCIA: id={$local['id']} '{$local['nombre']}' (normalizado: '$clave')\n";
        continue;
    }

    try {
        $updateStmt->execute([
            ':escudo' => $match['logo'],
            ':id'     => $local['id'],
        ]);
        $actualizados++;
        echo "✔ ACTUALIZADO: id={$local['id']} '{$local['nombre']}' -> {$match['logo']}\n";
    } catch (PDOException $e) {
        echo "✘ ERROR guardando id={$local['id']} '{$local['nombre']}': " . $e->getMessage() . "\n";
    }
}

// ------------------------------------------------------------------
// 5) Resumen final
// ------------------------------------------------------------------
echo "\n" . str_repeat('=', 60) . "\n";
echo "RESUMEN\n";
echo "  Equipos procesados:     " . count($equiposLocales) . "\n";
echo "  Actualizados:           $actualizados\n";
echo "  Sin coincidencia:       " . count($sinCoincidencia) . "\n";

if (count($sinCoincidencia) > 0) {
    echo "\nRevisar manualmente estos (probable diferencia de nombre entre tu BD y la API):\n";
    foreach ($sinCoincidencia as $eq) {
        echo "  - id={$eq['id']} '{$eq['nombre']}'\n";
    }
}