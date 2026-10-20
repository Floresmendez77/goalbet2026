<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

// seeder/diagnostico_scoreboard_crudo.php
//
// POR QUE EXISTE: /teams/{id}/schedule (el que usa poblar_partidos_liga_espn.php
// hoy) le devolvio a Talleres y a LA Galaxy exactamente el mismo patron --
// todos los eventos con STATUS_FULL_TIME, corta en seco el dia de hoy, CERO
// STATUS_SCHEDULED -- pese a que MLS tiene el calendario completo de la
// temporada anunciado desde marzo (se puede ver en espn.com ahora mismo).
// Sospecha: ese endpoint devuelve la temporada partida en "mitades"
// (ambas respuestas traian "half": 1 dentro de "season") y nos estamos
// quedando solo con la mitad ya jugada.
//
// La forma que SI alimenta la pagina de fixtures de ESPN.com (donde se ven
// partidos futuros todos los dias) es el scoreboard de la LIGA filtrado
// por fecha, no el schedule de un equipo puntual. Este script prueba esa
// hipotesis contra la realidad antes de tocar poblar_partidos_liga_espn.php,
// siguiendo la regla del proyecto: nunca asumir el shape de un endpoint
// nuevo sin volcar el JSON crudo primero.
//
// Prueba 2 cosas por separado:
//   A) Un solo dia (hoy) -- para confirmar el shape basico del scoreboard.
//   B) Un RANGO de dias hacia adelante, dia por dia (el parametro 'dates'
//      de este endpoint no tiene rango documentado y no es confiable
//      asumir que 'dates=20260815-20260828' funcione sin probarlo -- por
//      eso el loop dia-por-dia es el metodo confiable por default; si
//      la prueba A2 (rango en un solo request) trae los mismos resultados
//      que sumar el loop, lo sabremos y se podra optimizar el script real
//      para pedir menos requests).
//
// Uso:
//   php diagnostico_scoreboard_crudo.php <slug_liga> [dias_adelante=14] [fecha_inicio=hoy AAAA-MM-DD]
//
// Ejemplos:
//   php diagnostico_scoreboard_crudo.php arg.1
//   php diagnostico_scoreboard_crudo.php arg.1 21
//   php diagnostico_scoreboard_crudo.php usa.1 14
//   php diagnostico_scoreboard_crudo.php arg.1 10 2026-08-14

require __DIR__ . '/HttpClient.php';
require __DIR__ . '/Logger.php';

$slug = $argv[1] ?? null;
$diasAdelante = isset($argv[2]) ? (int)$argv[2] : 14;
$fechaInicio = $argv[3] ?? date('Y-m-d');

if (!$slug) {
    echo "Uso: php diagnostico_scoreboard_crudo.php <slug_liga> [dias_adelante=14] [fecha_inicio=hoy AAAA-MM-DD]\n";
    echo "Ejemplo: php diagnostico_scoreboard_crudo.php arg.1 21\n";
    exit(1);
}

$logger = new Logger();
$http = new HttpClient($logger);

function resumenEventos(array $eventos): array
{
    $porStatus = [];
    foreach ($eventos as $ev) {
        $status = $ev['status']['type']['name']
            ?? $ev['competitions'][0]['status']['type']['name']
            ?? '(sin status)';
        $porStatus[$status] = ($porStatus[$status] ?? 0) + 1;
    }
    return $porStatus;
}

echo "=== PRUEBA A: un solo dia (hoy = $fechaInicio) ===\n";
$fechaCompacta = str_replace('-', '', $fechaInicio);
$urlUnDia = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$slug}/scoreboard?dates={$fechaCompacta}";
$respUnDia = $http->get($urlUnDia, []);
$eventosUnDia = $respUnDia['events'] ?? [];
echo "URL: $urlUnDia\n";
echo "Total eventos: " . count($eventosUnDia) . "\n";
echo "Por status: " . json_encode(resumenEventos($eventosUnDia)) . "\n";
if (!empty($eventosUnDia)) {
    echo "\n--- Evento[0] CRUDO completo (para confirmar el shape: competitors, homeAway, team.id, date, status) ---\n";
    echo json_encode($eventosUnDia[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n\n=== PRUEBA B: rango en un solo request (optimizacion, puede no funcionar) ===\n";
$fechaFin = (new DateTime($fechaInicio))->modify("+{$diasAdelante} days")->format('Ymd');
$urlRango = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$slug}/scoreboard?dates={$fechaCompacta}-{$fechaFin}";
$respRango = $http->get($urlRango, []);
$eventosRango = $respRango['events'] ?? [];
echo "URL: $urlRango\n";
echo "Total eventos: " . count($eventosRango) . "\n";
echo "Por status: " . json_encode(resumenEventos($eventosRango)) . "\n";

echo "\n\n=== PRUEBA C: loop dia por dia, {$diasAdelante} dias desde $fechaInicio (el metodo confiable) ===\n";
$totalEventosLoop = 0;
$statusGlobal = [];
$fechasConPartidos = [];
$cursor = new DateTime($fechaInicio);
for ($i = 0; $i <= $diasAdelante; $i++) {
    $fechaStr = $cursor->format('Y-m-d');
    $fechaC = $cursor->format('Ymd');
    $url = "https://site.api.espn.com/apis/site/v2/sports/soccer/{$slug}/scoreboard?dates={$fechaC}";
    $resp = $http->get($url, []);
    $eventos = $resp['events'] ?? [];
    if (!empty($eventos)) {
        $fechasConPartidos[$fechaStr] = count($eventos);
        $totalEventosLoop += count($eventos);
        foreach (resumenEventos($eventos) as $st => $cant) {
            $statusGlobal[$st] = ($statusGlobal[$st] ?? 0) + $cant;
        }
        foreach ($eventos as $ev) {
            $comp = $ev['competitions'][0] ?? null;
            $competidores = $comp['competitors'] ?? [];
            $home = null;
            $away = null;
            foreach ($competidores as $c) {
                if (($c['homeAway'] ?? '') === 'home') {
                    $home = $c['team']['displayName'] ?? $c['team']['name'] ?? '?';
                } else {
                    $away = $c['team']['displayName'] ?? $c['team']['name'] ?? '?';
                }
            }
            $status = $ev['status']['type']['name'] ?? $comp['status']['type']['name'] ?? '?';
            echo "  $fechaStr | $home vs $away | $status | espn:{$ev['id']}\n";
        }
    }
    $cursor->modify('+1 day');
}

echo "\n--- RESUMEN PRUEBA C ---\n";
echo "Total eventos encontrados en $diasAdelante dias: $totalEventosLoop\n";
echo "Fechas con partidos: " . count($fechasConPartidos) . " de " . ($diasAdelante + 1) . " dias consultados\n";
echo "Por status (global): " . json_encode($statusGlobal) . "\n";

echo "\n\n=== CONCLUSION A CHEQUEAR A MANO ===\n";
echo "Si PRUEBA C trae eventos con status STATUS_SCHEDULED en fechas futuras,\n";
echo "confirma que el scoreboard-por-fecha SI expone el calendario que falta\n";
echo "(y ahi reescribimos poblar_partidos_liga_espn.php para usar este\n";
echo "endpoint en vez de /teams/{id}/schedule). Si PRUEBA B trae el mismo\n";
echo "total que la suma manual de PRUEBA C, el rango en un solo request\n";
echo "funciona y el script real puede pedir muchos menos requests.\n";