<?php

declare(strict_types=1);
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * COMPLETAR ESTADIOS FALTANTES — crea los estadios que no existen y los vincula a los equipos
 * que hoy tienen equipos.id_estadio = NULL (fútbol, NFL y NBA).
 *
 * De dónde sale el estadio: del "summary" de ESPN de partidos donde ese equipo fue LOCAL (los mismos
 * partidos que ya tenés vinculados en external_ids con fuente espn-*). Es el mismo dato que ya usa
 * partido.php ("Stade Bollaert-Delelis"). Por equipo se miran hasta 3 partidos de local y se elige el
 * estadio que más se repite, para no tomar uno neutral por error (final, partido en Londres, etc.).
 *
 * Por cada equipo:
 *   1. Obtiene nombre/ciudad/capacidad/foto del estadio (primero de partido_detalle si ya está importado;
 *      si no, consulta ESPN).
 *   2. Busca ese nombre en `estadios` (sin tildes ni mayúsculas): exacto, o "contiene" si hay UN solo candidato.
 *      Si existe, solo vincula el equipo. Si no existe, crea el estadio y vincula.
 *   3. UPDATE equipos SET id_estadio = ? WHERE id = ? AND id_estadio IS NULL (nunca pisa uno existente).
 *
 * Nunca toca estadios que ya existen, ni equipos que ya tienen estadio, ni la tabla partidos.
 * La foto: si ESPN la trae se guarda; si no, queda NULL y se completa con
 * poblar_imagenes_estadios_wikipedia.php (al final se imprime con qué ligas correrlo).
 *
 * MODO POR DEFECTO: diagnóstico (dry-run), no escribe nada. Agregar --aplicar para escribir.
 *
 * Uso (desde la carpeta seeder/):
 *   php completar_estadios_faltantes.php                      (todo, dry-run)
 *   php completar_estadios_faltantes.php --deporte=futbol     (futbol | futbol-americano | baloncesto)
 *   php completar_estadios_faltantes.php --max=5              (solo los primeros 5 equipos, para probar)
 *   php completar_estadios_faltantes.php --aplicar
 */

/** Minúsculas, sin tildes ni signos, espacios colapsados. */
function ce_norm(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s));
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
    ]);
    $s = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    return trim((string) preg_replace('/\s+/', ' ', $s));
}

/**
 * Busca en la lista de estadios existentes. Devuelve [id, 'exacto'|'parcial'] o null si no hay uno claro.
 *
 * @param array<int, array{id:int|string,nombre:string}> $existentes
 * @return array{0:int,1:string}|null
 */
function ce_buscar_existente(array $existentes, string $nombre): ?array
{
    $n = ce_norm($nombre);
    if ($n === '') {
        return null;
    }
    $exactos = [];
    $parciales = [];
    foreach ($existentes as $e) {
        $m = ce_norm((string) $e['nombre']);
        if ($m === '') {
            continue;
        }
        if ($m === $n) {
            $exactos[(int) $e['id']] = true;
        } elseif (strlen($m) >= 6 && strlen($n) >= 6 && (str_contains($m, $n) || str_contains($n, $m))) {
            $parciales[(int) $e['id']] = true;
        }
    }
    if ($exactos) {
        return count($exactos) === 1 ? [(int) array_key_first($exactos), 'exacto'] : null; // ambiguo: no elegir
    }
    return count($parciales) === 1 ? [(int) array_key_first($parciales), 'parcial'] : null;
}

/**
 * Del JSON de summary de ESPN saca el estadio.
 *
 * @return array{nombre:string,ciudad:?string,capacidad:?int,imagen:?string}|null
 */
function ce_venue_de_summary(array $json): ?array
{
    $v = $json['gameInfo']['venue'] ?? null;
    $nombre = is_array($v) ? trim((string) ($v['fullName'] ?? '')) : '';
    if ($nombre === '') {
        return null;
    }
    $city = trim((string) ($v['address']['city'] ?? ''));
    $state = trim((string) ($v['address']['state'] ?? ''));
    $ciudad = $city !== '' ? ($state !== '' ? "$city, $state" : $city) : null;
    $cap = (int) ($v['capacity'] ?? 0);
    $img = (string) ($v['images'][0]['href'] ?? '');
    return [
        'nombre' => $nombre,
        'ciudad' => $ciudad,
        'capacidad' => $cap > 0 ? $cap : null,
        'imagen' => preg_match('#^https?://#i', $img) ? $img : null,
    ];
}

/**
 * Elige el estadio que más se repite entre varios partidos de local (empate: el primero = el más reciente).
 *
 * @param array<int, array{nombre:string,ciudad:?string,capacidad:?int,imagen:?string}> $venues
 * @return array{nombre:string,ciudad:?string,capacidad:?int,imagen:?string}|null
 */
function ce_elegir_venue(array $venues): ?array
{
    $cuenta = [];
    $primero = [];
    foreach ($venues as $v) {
        $k = ce_norm($v['nombre']);
        $cuenta[$k] = ($cuenta[$k] ?? 0) + 1;
        $primero[$k] ??= $v;
    }
    if (!$cuenta) {
        return null;
    }
    arsort($cuenta); // estable: a igual cantidad queda primero el que apareció antes
    return $primero[(string) array_key_first($cuenta)];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    require __DIR__ . '/Env.php';
    require __DIR__ . '/Database.php';
    require __DIR__ . '/Logger.php';
    require __DIR__ . '/HttpClient.php';
    Env::load(__DIR__ . '/.env');

    $aplicar = in_array('--aplicar', $argv, true);
    $soloDeporte = null;
    $max = 0;
    foreach ($argv as $a) {
        if (str_starts_with($a, '--deporte=')) { $soloDeporte = substr($a, 10); }
        if (str_starts_with($a, '--max=')) { $max = (int) substr($a, 6); }
    }

    // slug del deporte en tu base => [ruta ESPN, fuente en external_ids de los partidos]
    $deportes = [
        'futbol'           => ['soccer/%s', 'espn-futbol'],      // %s = ligas_sync.slug_espn (eng.1, esp.1...)
        'futbol-americano' => ['football/nfl', 'espn-futbol-americano'],
        'baloncesto'       => ['basketball/nba', 'espn-baloncesto'],
    ];
    if ($soloDeporte !== null && !isset($deportes[$soloDeporte])) {
        fwrite(STDERR, "--deporte debe ser: " . implode(' | ', array_keys($deportes)) . "\n");
        exit(1);
    }

    $pdo = Database::connect();
    $http = new HttpClient(new Logger());
    echo $aplicar
        ? "=== MODO APLICAR: esto SI escribe en la base ===\n\n"
        : "=== MODO DIAGNOSTICO (dry-run): no se escribe nada. Agregar --aplicar para ejecutar ===\n\n";

    $existentes = $pdo->query('SELECT id, nombre FROM estadios')->fetchAll(PDO::FETCH_ASSOC);

    $slugs = array_keys($deportes);
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $q = $pdo->prepare(
        "SELECT e.id, e.nombre, e.id_liga, d.slug
         FROM equipos e JOIN deportes d ON d.id = e.id_deporte
         WHERE e.id_estadio IS NULL AND e.activo = 1 AND d.slug IN ($in)
         ORDER BY d.slug, e.nombre"
    );
    $q->execute($slugs);
    $equipos = array_values(array_filter(
        $q->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $e): bool => $soloDeporte === null || $e['slug'] === $soloDeporte
    ));
    if ($max > 0) {
        $equipos = array_slice($equipos, 0, $max);
    }
    echo count($equipos) . " equipos sin estadio a procesar.\n\n";

    $qPartidos = $pdo->prepare(
        "SELECT p.id, ei.external_id AS espn_id, ls.slug_espn
         FROM partidos p
         JOIN external_ids ei ON ei.entidad = 'partido' AND ei.entidad_id = p.id AND ei.fuente = ?
         JOIN ligas_sync ls ON ls.id_liga = p.id_liga AND ls.deporte = ? AND ls.activa = 1
         WHERE p.id_equipo_local = ?
         ORDER BY (p.estado = 'finalizado') DESC, p.fecha_partido DESC
         LIMIT 3"
    );
    $qDetalle = $pdo->prepare('SELECT estadio_nombre, estadio_ciudad FROM partido_detalle WHERE id_partido = ? AND estadio_nombre IS NOT NULL');
    $insEstadio = $pdo->prepare('INSERT INTO estadios (nombre, ciudad, id_pais, capacidad, imagen) VALUES (?, ?, NULL, ?, ?)');
    $updEquipo = $pdo->prepare('UPDATE equipos SET id_estadio = ? WHERE id = ? AND id_estadio IS NULL');

    $res = ['vinculados' => 0, 'creados' => 0, 'sin_datos' => 0, 'errores' => 0];
    $ligasNuevas = [];
    $sinDatos = [];

    foreach ($equipos as $eq) {
        [$ruta, $fuente] = $deportes[$eq['slug']];
        $qPartidos->execute([$fuente, $eq['slug'], (int) $eq['id']]);
        $partidos = $qPartidos->fetchAll(PDO::FETCH_ASSOC);

        $venues = [];
        foreach ($partidos as $pt) {
            $v = null;
            if ($eq['slug'] === 'futbol') { // ya importado: no hace falta pedirlo a ESPN
                $qDetalle->execute([(int) $pt['id']]);
                $d = $qDetalle->fetch(PDO::FETCH_ASSOC);
                if ($d) {
                    $v = ['nombre' => trim((string) $d['estadio_nombre']), 'ciudad' => $d['estadio_ciudad'] ?: null, 'capacidad' => null, 'imagen' => null];
                }
            }
            if ($v === null) {
                $url = 'https://site.api.espn.com/apis/site/v2/sports/' . sprintf($ruta, $pt['slug_espn']) . '/summary?event=' . rawurlencode((string) $pt['espn_id']);
                $json = $http->get($url, [], 2);
                usleep(300000);
                $v = is_array($json) ? ce_venue_de_summary($json) : null;
            }
            if ($v !== null) {
                $venues[] = $v;
            }
        }

        $venue = ce_elegir_venue($venues);
        if ($venue === null) {
            $res['sin_datos']++;
            $sinDatos[] = $eq['nombre'] . ($partidos ? '' : ' (sin partidos de local con id de ESPN)');
            echo sprintf("  [sin datos] %-32s\n", $eq['nombre']);
            continue;
        }

        $hit = ce_buscar_existente($existentes, $venue['nombre']);
        try {
            if ($hit !== null) {
                $idEstadio = $hit[0];
                $accion = "vincula a estadio existente #{$idEstadio} ({$hit[1]})";
                if ($aplicar) { $updEquipo->execute([$idEstadio, (int) $eq['id']]); }
                $res['vinculados']++;
            } else {
                $accion = 'CREA estadio nuevo' . ($venue['imagen'] ? ' (con foto de ESPN)' : ' (sin foto)');
                if ($aplicar) {
                    $pdo->beginTransaction();
                    $insEstadio->execute([$venue['nombre'], $venue['ciudad'], $venue['capacidad'] ?? 0, $venue['imagen']]);
                    $idEstadio = (int) $pdo->lastInsertId();
                    $updEquipo->execute([$idEstadio, (int) $eq['id']]);
                    $pdo->commit();
                    $existentes[] = ['id' => $idEstadio, 'nombre' => $venue['nombre']]; // el próximo equipo lo reutiliza
                } else {
                    $existentes[] = ['id' => -count($existentes) - 1, 'nombre' => $venue['nombre']]; // simulación
                }
                $res['creados']++;
                $ligasNuevas[(int) $eq['id_liga']] = true;
            }
            echo sprintf("  %-32s -> %-34s %s\n", $eq['nombre'], $venue['nombre'] . ($venue['ciudad'] ? ' (' . $venue['ciudad'] . ')' : ''), $accion);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $res['errores']++;
            echo "  [ERROR] {$eq['nombre']}: " . $e->getMessage() . "\n";
        }
    }

    echo "\n=== RESUMEN ===\n";
    echo "Equipos vinculados a estadio existente: {$res['vinculados']}\n";
    echo "Estadios nuevos creados (y vinculados): {$res['creados']}\n";
    echo "Sin datos de estadio:                   {$res['sin_datos']}\n";
    echo "Errores:                                {$res['errores']}\n";
    if ($sinDatos) {
        echo "\nSin datos (cargar a mano o con vincular_equipo_espn.php si les falta el id de ESPN):\n  - " . implode("\n  - ", $sinDatos) . "\n";
    }
    if ($aplicar && $ligasNuevas) {
        echo "\nSiguiente paso (fotos): corré para cada liga con estadios nuevos:\n";
        foreach (array_keys($ligasNuevas) as $idLiga) {
            echo "  php poblar_imagenes_estadios_wikipedia.php {$idLiga} --aplicar\n";
        }
    }
    if (!$aplicar) {
        echo "\nNo se escribió nada. Si el listado se ve bien, volvé a correr con --aplicar.\n";
    }
}