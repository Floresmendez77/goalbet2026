<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * COMPLETAR id_pais DE ESTADIOS -- SIN TOCAR API-SPORTS.
 *
 * POR QUE ESTE SCRIPT Y NO poblar_estadios_liga_apisports.php:
 *
 *   1) Ese script escribe SIEMPRE 'imagen' (y 'ciudad'/'capacidad') con
 *      lo que traiga api-sports en ese momento, sin importar si el
 *      estadio ya tenia una foto mejor cargada a mano (caso real: los
 *      estadios de Ligue 2/Serie B/Champions League tienen fotos de
 *      Wikipedia curadas en la sesion anterior -- volver a correr ese
 *      script se las pisaria con lo que traiga api-sports, que puede
 *      ser peor o venir vacio).
 *
 *   2) Peor todavia: ese script asigna el id_pais del estadio usando
 *      SIEMPRE el pais de la liga que le pasas como parametro, sin la
 *      salvaguarda que SI tiene para el equipo (esa que evita pisar el
 *      pais de un equipo que ya pertenece a otra liga). Si se corriera
 *      con la liga Champions (id=26, pais="World" desde que se corrio
 *      poblar_paises.php), le asignaria "World" a estadios como Old
 *      Trafford -- que en realidad tienen que quedar con el pais de la
 *      liga domestica del equipo (England), no con el pseudo-pais
 *      multinacional de Champions.
 *
 * QUE HACE ESTE SCRIPT EN CAMBIO:
 *   El dato que falta (a que pais pertenece un estadio) YA esta en tu
 *   propia base, sin necesidad de preguntarle a ninguna API: cada
 *   estadio tiene equipos vinculados por equipos.id_estadio, y cada
 *   equipo tiene una liga domestica (equipos.id_liga) con su pais ya
 *   resuelto en ligas_torneos.id_pais (gracias a poblar_paises.php).
 *   Entonces:
 *
 *     1. Por cada estadio con id_pais IS NULL, busca los equipos que
 *        juegan ahi (equipos.id_estadio = estadio.id).
 *     2. Junta los id_pais de las ligas domesticas de esos equipos
 *        (ligas_torneos.id_pais via equipos.id_liga).
 *     3. Si TODOS los equipos coinciden en un unico pais -> UPDATE
 *        estadios SET id_pais = ese pais. Nada mas se toca.
 *     4. Si hay CERO equipos vinculados, o si dos equipos del mismo
 *        estadio apuntan a paises distintos (deberia ser rarisimo,
 *        canchas compartidas entre paises no existen en la practica),
 *        se omite y se loguea para revisar a mano -- nunca se adivina.
 *
 * No llama a ninguna API externa: 100% determinístico, cero riesgo de
 * variabilidad entre corridas (a diferencia de las busquedas en vivo,
 * ver leccion de poblar_imagenes_estadios_wikipedia.php). Y no toca
 * 'imagen', 'ciudad' ni 'capacidad' bajo ninguna circunstancia -- este
 * script hace un UPDATE manual de una sola columna, no pasa por
 * Repository::upsertEstadio().
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php poblar_pais_estadios_domestico.php
 *   php poblar_pais_estadios_domestico.php --aplicar
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';

Env::load(__DIR__ . '/.env');

$aplicar = in_array('--aplicar', $argv, true);

$pdo = Database::connect();
$logger = new Logger();

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base (solo columna id_pais de estadios) ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

$stmtEstadios = $pdo->query(
    'SELECT id, nombre, ciudad FROM estadios WHERE id_pais IS NULL ORDER BY id'
);
$estadiosSinPais = $stmtEstadios->fetchAll();

if (empty($estadiosSinPais)) {
    echo "No hay estadios con id_pais NULL. Nada que hacer.\n";
    exit(0);
}

echo count($estadiosSinPais) . " estadios con id_pais NULL.\n\n";

$stmtEquipos = $pdo->prepare(
    'SELECT eq.id AS id_equipo, eq.nombre AS nombre_equipo, lt.id_pais
     FROM equipos eq
     JOIN ligas_torneos lt ON lt.id = eq.id_liga
     WHERE eq.id_estadio = ?'
);

$actualizados = 0;
$sinEquipo = [];
$conConflicto = [];

foreach ($estadiosSinPais as $estadio) {
    $stmtEquipos->execute([$estadio['id']]);
    $vinculos = $stmtEquipos->fetchAll();

    if (empty($vinculos)) {
        $sinEquipo[] = $estadio['nombre'];
        $logger->skipped("estadio '{$estadio['nombre']}' (id={$estadio['id']}): ningun equipo vinculado por id_estadio -- no se puede inferir el pais localmente");
        continue;
    }

    $paisesDistintos = array_unique(array_map(
        fn($v) => $v['id_pais'] !== null ? (int)$v['id_pais'] : null,
        $vinculos
    ));
    // Descarta los NULL (equipo cuya liga todavia no tiene pais) para
    // el chequeo de consistencia -- si al menos uno trae un pais real,
    // se usa ese.
    $paisesReales = array_values(array_filter($paisesDistintos, fn($p) => $p !== null));

    if (empty($paisesReales)) {
        $sinEquipo[] = $estadio['nombre'] . ' (equipos vinculados, pero sus ligas tampoco tienen id_pais todavia)';
        $logger->skipped("estadio '{$estadio['nombre']}' (id={$estadio['id']}): equipos vinculados pero ninguno tiene pais resuelto todavia -- se omite");
        continue;
    }

    if (count($paisesReales) > 1) {
        $nombresEquipos = implode(', ', array_map(fn($v) => $v['nombre_equipo'], $vinculos));
        $conConflicto[] = "{$estadio['nombre']} (equipos: $nombresEquipos)";
        $logger->error("estadio '{$estadio['nombre']}' (id={$estadio['id']}): equipos vinculados apuntan a paises DISTINTOS ($nombresEquipos) -- revisar a mano, no se adivina");
        continue;
    }

    $idPaisResuelto = $paisesReales[0];
    $actualizados++;

    if (!$aplicar) {
        $logger->info("ACTUALIZARIA estadio '{$estadio['nombre']}' (id={$estadio['id']}, {$estadio['ciudad']}) -> id_pais=$idPaisResuelto");
        continue;
    }

    $stmtUpdate = $pdo->prepare('UPDATE estadios SET id_pais = ? WHERE id = ?');
    $stmtUpdate->execute([$idPaisResuelto, $estadio['id']]);
    $logger->updated("estadio '{$estadio['nombre']}' (id={$estadio['id']}) -> id_pais=$idPaisResuelto");
}

echo "\n[RESUMEN] estadios " . ($aplicar ? "actualizados" : "que se actualizarian") . ": $actualizados";
if (!empty($sinEquipo)) {
    echo "\nSin equipo vinculado o sin pais resoluble (" . count($sinEquipo) . "): " . implode(' | ', $sinEquipo);
}
if (!empty($conConflicto)) {
    echo "\nCON CONFLICTO -- revisar a mano (" . count($conConflicto) . "): " . implode(' | ', $conConflicto);
}
echo "\n";

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados -- solo se toco la columna id_pais, imagen/ciudad/capacidad quedaron intactas.\n";
}

$logger->resumen();