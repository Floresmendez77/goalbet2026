<?php

/**
 * reparar_equipos_mlb.php
 *
 * El import original de equipos de MLB (id_liga=19) se comio 4 entradas
 * que NO son equipos reales ("American League", "National League",
 * "Northeastern", "Sugar Land" -- vienen mezcladas en la respuesta de
 * /teams?league=1&season=2023 de api-baseball junto con los 30 equipos
 * reales) y por eso quedaron afuera 4 equipos reales: St. Louis Cardinals,
 * Tampa Bay Rays, Texas Rangers y Toronto Blue Jays.
 *
 * Este script:
 *  1. Vuelve a pedir la lista completa a la API (UNA sola llamada).
 *  2. Inserta los equipos reales que faltan (con su external_id, igual
 *     que hace Repository::upsertEquipo(), para que el seeder no los
 *     duplique si se vuelve a correr).
 *  3. Desactiva (activo=0, no borra) las 4 entradas que no son equipos.
 *
 * Despues de correr esto, hay que volver a correr el seeder de beisbol
 * para que traiga de nuevo los partidos: ahora que los 30 equipos reales
 * existen, los juegos que antes se saltaban por no poder resolver el
 * equipo van a poder crearse.
 *
 * Uso: php reparar_equipos_mlb.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
if (php_sapi_name() !== 'cli') {
    requerir_admin();
}

const LIGA_MLB_API  = 1;
const TEMPORADA     = 2023;
const ID_DEPORTE    = 3;   // beisbol
const ID_LIGA_LOCAL = 19;  // id interno de ligas_torneos para MLB
// OJO: se usa un fuente ESPECIFICO de deporte, no el generico 'api-sports'.
// El seeder original usa 'api-sports' para futbol/basquet/beisbol/NFL a la
// vez, pero cada deporte tiene su PROPIO catalogo de ids en la API real
// (el equipo id=34 de basquet no tiene nada que ver con el id=34 de
// beisbol). Compartir el mismo 'fuente' hace que external_ids choque
// entre deportes distintos -- por eso este script usa un fuente propio
// para no pisar datos de otros deportes.
const FUENTE = 'api-sports-baseball';

// Lista oficial de los 30 equipos de MLB, tal como los nombra api-baseball.
const EQUIPOS_MLB_OFICIALES = [
    'Arizona Diamondbacks', 'Atlanta Braves', 'Baltimore Orioles', 'Boston Red Sox',
    'Chicago Cubs', 'Chicago White Sox', 'Cincinnati Reds', 'Cleveland Guardians',
    'Colorado Rockies', 'Detroit Tigers', 'Houston Astros', 'Kansas City Royals',
    'Los Angeles Angels', 'Los Angeles Dodgers', 'Miami Marlins', 'Milwaukee Brewers',
    'Minnesota Twins', 'New York Mets', 'New York Yankees', 'Oakland Athletics',
    'Philadelphia Phillies', 'Pittsburgh Pirates', 'San Diego Padres', 'San Francisco Giants',
    'Seattle Mariners', 'St. Louis Cardinals', 'Tampa Bay Rays', 'Texas Rangers',
    'Toronto Blue Jays', 'Washington Nationals',
];

function normalizarNombre(string $nombre): string
{
    $n = strtolower(trim($nombre));
    $n = strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    // El punto se reemplaza por ESPACIO, no por nada -- asi 'St.Louis' (sin
    // espacio, como lo devuelve la API) y 'St. Louis' (con espacio, nombre
    // oficial) terminan en el mismo 'st louis' normalizado.
    $n = str_replace('.', ' ', $n);
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? $n;
    return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
}

$apiKey = Env::get('API_SPORTS_KEY', '');
if ($apiKey === '') {
    echo "✘ Falta API_SPORTS_KEY en el .env\n";
    exit(1);
}

// ------------------------------------------------------------------
// 1) UNA sola llamada a la API para traer los 34 "equipos" (30 reales + 4 impostores)
// ------------------------------------------------------------------
$ch = curl_init("https://v1.baseball.api-sports.io/teams?league=" . LIGA_MLB_API . "&season=" . TEMPORADA);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["x-apisports-key: $apiKey"],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NO_REVOKE,
]);
$body = curl_exec($ch);
if ($body === false) {
    echo "✘ Error cURL: " . curl_error($ch) . PHP_EOL;
    exit(1);
}
curl_close($ch);

$data = json_decode($body, true);
if (json_last_error() !== JSON_ERROR_NONE || !empty($data['errors'])) {
    echo "✘ Respuesta invalida de la API: " . $body . PHP_EOL;
    exit(1);
}

$equiposApi = $data['response'] ?? [];
echo "✔ API respondio " . count($equiposApi) . " entradas.\n\n";

// Mapa nombre normalizado => datos del equipo, segun la API
$mapaApi = [];
foreach ($equiposApi as $eq) {
    $nombre = $eq['name'] ?? '';
    if ($nombre === '') {
        continue;
    }
    $mapaApi[normalizarNombre($nombre)] = $eq;
}

// Set de nombres oficiales normalizados, para comparar rapido
$oficialesNormalizados = array_map('normalizarNombre', EQUIPOS_MLB_OFICIALES);

try {
    $pdo = db();

    // ------------------------------------------------------------------
    // 2) Insertar los equipos reales que faltan
    // ------------------------------------------------------------------
    $stmtExistente = $pdo->prepare(
        "SELECT id FROM equipos WHERE id_deporte = :dep AND id_liga = :liga AND nombre = :nombre LIMIT 1"
    );
    $stmtInsertEquipo = $pdo->prepare(
        "INSERT INTO equipos (id_deporte, id_liga, nombre, escudo, activo)
         VALUES (:dep, :liga, :nombre, :escudo, 1)"
    );
    $stmtInsertExternalId = $pdo->prepare(
        "INSERT INTO external_ids (entidad, entidad_id, fuente, external_id)
         VALUES ('equipo', :entidad_id, :fuente, :external_id)"
    );

    echo "== Agregando equipos reales que faltaban ==\n";
    $agregados = 0;
    foreach (EQUIPOS_MLB_OFICIALES as $nombreOficial) {
        $clave = normalizarNombre($nombreOficial);

        $stmtExistente->execute([':dep' => ID_DEPORTE, ':liga' => ID_LIGA_LOCAL, ':nombre' => $nombreOficial]);
        if ($stmtExistente->fetchColumn() !== false) {
            continue; // ya existe, no hacer nada
        }

        $datosApi = $mapaApi[$clave] ?? null;
        if ($datosApi === null) {
            echo "✘ '$nombreOficial' no esta ni local ni en la respuesta de la API (raro, revisar a mano)\n";
            continue;
        }

        $pdo->beginTransaction();
        $stmtInsertEquipo->execute([
            ':dep'    => ID_DEPORTE,
            ':liga'   => ID_LIGA_LOCAL,
            ':nombre' => $nombreOficial,
            ':escudo' => $datosApi['logo'] ?? null,
        ]);
        $nuevoId = (int)$pdo->lastInsertId();
        $stmtInsertExternalId->execute([
            ':entidad_id'  => $nuevoId,
            ':fuente'      => FUENTE,
            ':external_id' => (string)($datosApi['id'] ?? ''),
        ]);
        $pdo->commit();

        $agregados++;
        echo "✔ AGREGADO: '$nombreOficial' -> id $nuevoId (api-sports:{$datosApi['id']})\n";
    }
    if ($agregados === 0) {
        echo "(no faltaba ninguno)\n";
    }

    // ------------------------------------------------------------------
    // 3) Desactivar las entradas que NO son equipos reales de MLB
    // ------------------------------------------------------------------
    echo "\n== Desactivando entradas que no son equipos reales ==\n";
    $stmtLocales = $pdo->prepare("SELECT id, nombre FROM equipos WHERE id_deporte = :dep AND id_liga = :liga");
    $stmtLocales->execute([':dep' => ID_DEPORTE, ':liga' => ID_LIGA_LOCAL]);
    $stmtDesactivar = $pdo->prepare("UPDATE equipos SET activo = 0 WHERE id = :id");

    $desactivados = 0;
    foreach ($stmtLocales->fetchAll() as $local) {
        $clave = normalizarNombre($local['nombre']);
        if (in_array($clave, $oficialesNormalizados, true)) {
            continue; // es un equipo real, no tocar
        }
        $stmtDesactivar->execute([':id' => $local['id']]);
        $desactivados++;
        echo "✔ DESACTIVADO (no es equipo real): id={$local['id']} '{$local['nombre']}'\n";
    }
    if ($desactivados === 0) {
        echo "(no habia ninguna entrada falsa)\n";
    }

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "RESUMEN\n";
    echo "  Equipos reales agregados: $agregados\n";
    echo "  Entradas falsas desactivadas: $desactivados\n";
    echo "\nSiguiente paso: volve a correr el seeder de beisbol para que\n";
    echo "traiga de nuevo los partidos -- ahora los 30 equipos reales\n";
    echo "existen y los juegos que antes se salteaban van a poder crearse:\n\n";
    echo "  php run_seeder.php --fuente=api-sports --deporte=beisbol --liga=1 --temporada=2023\n";
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "✘ Error de base de datos: " . $e->getMessage() . PHP_EOL;
    exit(1);
}