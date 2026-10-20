<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_jugadores_matching.php
// Segunda pasada del diagnostico de jugadores duplicados de Premier League.
// Compara por APELLIDO (ultima palabra del nombre), porque la base
// historica suele guardar nombres abreviados tipo "B. Saka" mientras
// que el duplicado trae el nombre completo "Bukayo Saka".
//
// NO escribe nada en la base. Solo reporta.
//
// Correr desde la carpeta seeder/ con:
//   & "C:\MAMP\bin\php\php8.3.1\php.exe" diagnostico_jugadores_matching.php

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$pdo = Database::connect();

$mapeo = [
    1  => 103, // Arsenal
    2  => 115, // Aston Villa
    18 => 112, // Brentford
    17 => 110, // Brighton
    16 => 111, // Crystal Palace
    4  => 105, // Everton
    5  => 100, // Fulham
    7  => 109, // Man City
    8  => 97,  // Man United
    9  => 98,  // Newcastle
    15 => 114, // Nottm Forest
    19 => 99,  // Bournemouth
];

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
        'ø'=>'o','Ø'=>'o',
    ];
    return strtr($texto, $mapa);
}

function normalizarPalabras(string $nombre): array
{
    $n = sacarAcentos($nombre);
    $n = strtolower($n);
    $n = str_replace(['.', ','], '', $n);
    $palabras = preg_split('/[\s\-]+/', $n);
    $palabras = array_filter($palabras, fn($p) => $p !== '');
    return array_values($palabras);
}

// Devuelve la ULTIMA palabra de 2+ letras (el apellido, ignorando
// sufijos de una sola letra que puedan quedar sueltos)
function apellido(array $palabras): string
{
    for ($i = count($palabras) - 1; $i >= 0; $i--) {
        if (strlen($palabras[$i]) >= 2) {
            return $palabras[$i];
        }
    }
    return end($palabras) ?: '';
}

function mejorCandidato(array $palabrasDup, array $indiceHist): ?array
{
    $apellidoDup = apellido($palabrasDup);
    if ($apellidoDup === '') {
        return null;
    }

    $mejor = null;
    $mejorScore = 0;

    foreach ($indiceHist as $existente) {
        $palabrasEx = normalizarPalabras($existente['nombre']);
        if (empty($palabrasEx)) {
            continue;
        }
        $apellidoEx = apellido($palabrasEx);

        // Criterio principal: el apellido tiene que matchear exacto
        if ($apellidoDup !== $apellidoEx) {
            continue;
        }

        // Score extra: cuantas palabras en comun hay ademas del apellido
        // (para desempatar si dos jugadores comparten apellido)
        $comunes = array_intersect($palabrasDup, $palabrasEx);
        $score = count($comunes) / max(count($palabrasDup), count($palabrasEx));

        if ($score > $mejorScore || $mejor === null) {
            $mejorScore = $score;
            $mejor = $existente;
        }
    }

    return $mejor !== null ? [$mejor, $mejorScore] : null;
}

echo "=== MATCHING FLEXIBLE POR APELLIDO - JUGADORES SIN COINCIDENCIA EXACTA ===\n\n";

foreach ($mapeo as $idDup => $idHist) {

    $stmt = $pdo->prepare("SELECT id, nombre FROM jugadores WHERE id_equipo = ?");
    $stmt->execute([$idDup]);
    $jugadoresDup = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($jugadoresDup)) {
        continue;
    }

    $stmt = $pdo->prepare("SELECT id, nombre FROM jugadores WHERE id_equipo = ?");
    $stmt->execute([$idHist]);
    $jugadoresHist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nombresHistExactos = array_column($jugadoresHist, 'nombre');

    $sinCoincidenciaExacta = array_filter(
        $jugadoresDup,
        fn($j) => !in_array($j['nombre'], $nombresHistExactos)
    );

    if (empty($sinCoincidenciaExacta)) {
        continue;
    }

    echo "--- Equipo duplicado #$idDup -> histórico #$idHist ---\n";

    foreach ($sinCoincidenciaExacta as $j) {
        $palabrasDup = normalizarPalabras($j['nombre']);
        $candidato = mejorCandidato($palabrasDup, $jugadoresHist);

        if ($candidato !== null) {
            [$existenteHist, $score] = $candidato;
            $pct = round($score * 100);
            echo "  MATCH POR APELLIDO ($pct%)  '{$j['nombre']}'  <->  '{$existenteHist['nombre']}' (histórico #{$existenteHist['id']})\n";
        } else {
            echo "  SIN PAREJA               '{$j['nombre']}'  -- posible alta genuina, revisar a mano\n";
        }
    }
    echo "\n";
}

echo "=== FIN (no se borró ni modificó nada) ===\n";