<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/migrar_jugadores_premier.php
//
// Resuelve los jugadores de los 12 equipos duplicados de Premier League
// antes de poder borrar esos equipos (pendiente #2, Paso 5).
//
// Para cada jugador del equipo duplicado:
//   - Si coincide EXACTO por nombre con uno del historico -> es copia
//     pura, se BORRA del duplicado (ya existe el mismo jugador alla).
//   - Si coincide por APELLIDO (matching flexible) -> mismo jugador con
//     nombre escrito distinto, se BORRA del duplicado.
//   - Casos especiales de nombre compuesto confirmados a mano
//     ($overridesMatch) -> se BORRA del duplicado.
//   - Casos excluidos ($excluirRevisarManual, ej. cuerpo tecnico que
//     aparecio mezclado con jugadores) -> NO se toca, se lista para
//     revision manual.
//   - El resto (altas genuinas sin pareja) -> se MIGRA (UPDATE
//     id_equipo) al equipo historico, no se borra.
//
// MODO POR DEFECTO: solo diagnostico (dry-run), no escribe nada.
// Para aplicar los cambios de verdad, correr con --aplicar
//
// Correr desde la carpeta seeder/ con:
//   & "C:\MAMP\bin\php\php8.3.1\php.exe" migrar_jugadores_premier.php
//   & "C:\MAMP\bin\php\php8.3.1\php.exe" migrar_jugadores_premier.php --aplicar

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Repository.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$pdo = Database::connect();

$aplicar = in_array('--aplicar', $argv, true);

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

// Nombres del duplicado que sabemos, a mano, que SI tienen pareja pero
// con un nombre compuesto que el matching por apellido no detecta.
$overridesMatch = [
    'Adam Aznou' => 'Adam Aznou Ben Cheikh', // Everton, hist #105
];

// Nombres que NO se migran ni se borran en esta pasada -- probable
// cuerpo tecnico u otro caso dudoso, decidir a mano por separado.
$excluirRevisarManual = [
    'David Moyes',    // Everton -- entrenador, no jugador
    'Álvaro Arbeloa', // Fulham -- ex-jugador retirado, revisar origen del dato
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

function apellido(array $palabras): string
{
    for ($i = count($palabras) - 1; $i >= 0; $i--) {
        if (strlen($palabras[$i]) >= 2) {
            return $palabras[$i];
        }
    }
    return end($palabras) ?: '';
}

function buscarPorApellido(array $palabrasDup, array $indiceHist): ?array
{
    $apellidoDup = apellido($palabrasDup);
    if ($apellidoDup === '') {
        return null;
    }
    foreach ($indiceHist as $existente) {
        $palabrasEx = normalizarPalabras($existente['nombre']);
        if (apellido($palabrasEx) === $apellidoDup) {
            return $existente;
        }
    }
    return null;
}

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Correr con --aplicar para ejecutar de verdad ===\n\n";

$totalBorrarDuplicado = 0;
$totalMigrar = 0;
$totalExcluidos = 0;

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

    echo "--- Equipo duplicado #$idDup -> histórico #$idHist ---\n";

    foreach ($jugadoresDup as $j) {
        $nombre = $j['nombre'];
        $idJugadorDup = $j['id'];

        // 1) Excluido: no tocar, revisar a mano
        if (in_array($nombre, $excluirRevisarManual, true)) {
            echo "  EXCLUIDO (revisar a mano)  '{$nombre}' -- no se toca\n";
            $totalExcluidos++;
            continue;
        }

        // 2) Match exacto por nombre -> borrar duplicado
        if (in_array($nombre, $nombresHistExactos, true)) {
            echo "  BORRAR (copia exacta)      '{$nombre}'\n";
            if ($aplicar) {
                $pdo->prepare("DELETE FROM jugadores WHERE id = ?")->execute([$idJugadorDup]);
            }
            $totalBorrarDuplicado++;
            continue;
        }

        // 3) Override manual de nombre compuesto -> borrar duplicado
        if (isset($overridesMatch[$nombre])) {
            echo "  BORRAR (override manual)   '{$nombre}' <-> '{$overridesMatch[$nombre]}'\n";
            if ($aplicar) {
                $pdo->prepare("DELETE FROM jugadores WHERE id = ?")->execute([$idJugadorDup]);
            }
            $totalBorrarDuplicado++;
            continue;
        }

        // 4) Match por apellido -> borrar duplicado
        $palabrasDup = normalizarPalabras($nombre);
        $matchApellido = buscarPorApellido($palabrasDup, $jugadoresHist);
        if ($matchApellido !== null) {
            echo "  BORRAR (match apellido)    '{$nombre}' <-> '{$matchApellido['nombre']}'\n";
            if ($aplicar) {
                $pdo->prepare("DELETE FROM jugadores WHERE id = ?")->execute([$idJugadorDup]);
            }
            $totalBorrarDuplicado++;
            continue;
        }

        // 5) Sin pareja de ningun tipo -> alta genuina, migrar al historico
        echo "  MIGRAR (alta genuina)      '{$nombre}' -> equipo histórico #{$idHist}\n";
        if ($aplicar) {
            $pdo->prepare("UPDATE jugadores SET id_equipo = ? WHERE id = ?")->execute([$idHist, $idJugadorDup]);
        }
        $totalMigrar++;
    }
    echo "\n";
}

echo "=== RESUMEN ===\n";
echo "Jugadores a borrar por ser copia (exacta/apellido/override): {$totalBorrarDuplicado}\n";
echo "Jugadores a migrar (altas genuinas): {$totalMigrar}\n";
echo "Jugadores excluidos, revisar a mano: {$totalExcluidos}\n";

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Revisa la lista de arriba.\n";
    echo "Si todo se ve bien, corre de nuevo con --aplicar para ejecutar los cambios.\n";
} else {
    echo "\nListo. Cambios aplicados. Ahora se puede volver a correr migrar_partidos_premier.php\n";
    echo "(su Paso 5) para intentar borrar los equipos duplicados que ya deberian quedar sin jugadores.\n";
}