<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * POBLAR IMAGENES DE ESTADIOS DESDE WIKIPEDIA -- reemplaza las fotos
 * rotas de api-sports.io por fotos reales tomadas de Wikipedia /
 * Wikimedia Commons via el endpoint REST "summary" de Wikipedia.
 *
 * Por que Wikipedia y no api-sports para esto puntual: api-sports
 * entrega una URL de imagen "cuando esta disponible", pero muchos
 * estadios (sobre todo de ligas de perfil mas bajo como la MLS) tienen
 * ese campo apuntando a un archivo que nunca se subio al CDN -> URL
 * rota (404 al cargarla). No es un bug de poblar_estadios_liga_apisports.php,
 * es una limitacion de esa fuente. Wikipedia SI tiene fotos reales,
 * con licencia libre (CC BY-SA o dominio publico en su mayoria), para
 * practicamente todos los estadios profesionales de MLS/Champions/etc.
 *
 * Como lo resuelve:
 *   1. Toma los estadios YA vinculados a equipos de la liga pedida
 *      (join estadios <-> equipos por id_estadio -- correr primero
 *      poblar_estadios_liga_apisports.php si todavia no hay estadios
 *      para esta liga, este script no crea estadios nuevos).
 *   2. Por cada estadio, busca en Wikipedia (accion 'query'+'search')
 *      una pagina que matchee "<nombre_estadio>", y si no matchea bien
 *      reintenta con "<nombre_estadio> <ciudad>" para desambiguar
 *      nombres genericos.
 *   3. Si encuentra pagina, pide el resumen REST
 *      (/api/rest_v1/page/summary/<titulo>), que trae 'originalimage'
 *      (foto a resolucion completa) y 'thumbnail' (version chica).
 *   4. Si hay imagen, UPDATE estadios.imagen = url del thumbnail (mas
 *      liviano para produccion que la original). Si no matchea
 *      ninguna pagina o la pagina no tiene imagen, se omite y se
 *      loguea -- nunca inventa una URL.
 *
 * MODO POR DEFECTO: diagnostico (dry-run). Agregar --aplicar para
 * escribir de verdad.
 *
 * Uso:
 *   php poblar_imagenes_estadios_wikipedia.php <id_liga_bd> [--aplicar] [--idioma=en]
 *
 * Ejemplo (MLS, id_liga_bd=30):
 *   php poblar_imagenes_estadios_wikipedia.php 30
 *   php poblar_imagenes_estadios_wikipedia.php 30 --aplicar
 *
 * OJO orden de scripts: correr este DESPUES de
 * poblar_estadios_liga_apisports.php, nunca antes (si no hay estadios
 * vinculados a la liga, no hay nada que actualizar). Y si mas adelante
 * volves a correr poblar_estadios_liga_apisports.php --aplicar para
 * esta misma liga, ese script va a pisar de nuevo el campo 'imagen'
 * con la URL (posiblemente rota) de api-sports -- en ese caso hay que
 * volver a correr este script despues para recuperar la foto de
 * Wikipedia.
 *
 * OJO licencia: las imagenes de Wikipedia/Wikimedia Commons vienen con
 * licencias (mayormente CC BY-SA o dominio publico), no son 100% libres
 * de atribucion en todos los casos. Para un proyecto de portfolio/demo
 * enlazar la imagen (no redistribuirla) es practica estandar, pero si
 * el proyecto pasa a produccion comercial real conviene revisar la
 * licencia puntual de cada estadio via la Commons API.
 *
 * OJO variabilidad de la busqueda: buscarTituloWikipedia() pega contra
 * la API de busqueda de Wikipedia EN VIVO en cada corrida -- no es un
 * dato estatico guardado en ningun lado. Se confirmo esta sesion que
 * para al menos un estadio (Vidzemes Olimpiskais centrs, ver
 * $estadiosExcluidos mas abajo) el resultado de la busqueda puede
 * variar de una corrida a otra sin que haya cambiado nada en el
 * codigo ni en la base -- una vez no encontro nada (resultado
 * correcto por casualidad) y otra vez si matcheo con una pagina
 * ajena al estadio. Por eso, ademas de los overrides (que fuerzan un
 * titulo correcto conocido), existe la lista de exclusion para casos
 * donde NO hay ningun titulo confiable y es mejor no buscar en
 * absoluto en vez de arriesgarse a la inconsistencia.
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/HttpClient.php';

Env::load(__DIR__ . '/.env');

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$aplicar = in_array('--aplicar', $argv, true);
$idioma = 'en';
foreach ($argv as $arg) {
    if (strpos($arg, '--idioma=') === 0) {
        $idioma = substr($arg, 9);
    }
}

if (!$idLigaBd) {
    fwrite(STDERR, "Uso: php poblar_imagenes_estadios_wikipedia.php <id_liga_bd> [--aplicar] [--idioma=en]\n");
    fwrite(STDERR, "Ejemplo: php poblar_imagenes_estadios_wikipedia.php 30 --aplicar\n");
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$http = new HttpClient($logger);

/**
 * OVERRIDES MANUALES: nombres de estadio ambiguos donde la busqueda
 * automatica de Wikipedia matchea la pagina equivocada (o una pagina
 * de desambiguacion sin imagen) porque existe otro lugar con el mismo
 * nombre. Clave = nombre EXACTO tal cual esta en estadios.nombre en tu
 * base, valor = titulo EXACTO de la pagina correcta en Wikipedia.
 *
 * Caso confirmado esta sesion: 'Toyota Stadium' (FC Dallas, Frisco TX)
 * pierde contra el Toyota Stadium de Toyota City, Japon (sede de
 * Mundiales de Clubes), mucho mas conocido -- la busqueda trae esa
 * pagina (o una de desambiguacion) sin imagen util. El titulo correcto
 * en Wikipedia es 'Toyota Stadium (Texas)'.
 *
 * Si en otra liga aparece otro caso similar (estadio omitido con
 * 'pagina encontrada pero sin imagen' o de plano 'sin pagina'), se
 * agrega aca una entrada nueva en vez de tocar la logica de busqueda.
 */
$overridesTitulo = [
    'Estadio Presidente Juan Domingo Perón' => 'Estadio Presidente Perón',
    'Stadio Comunale Via del Mare' => 'Stadio Via del mare',
    'Allianz Stadium' => 'Juventus Stadium',
    'Stade Crédit Agricole de la Licorne' => 'Stade de la Licorne',
];

// Overrides que necesitan ciudad para desambiguar, porque el NOMBRE
// solo no alcanza -- hay mas de un estadio real con el mismo nombre
// literal en la base. Caso confirmado esta sesion: existen DOS
// estadios llamados 'Red Bull Arena' (Leipzig, Alemania Y
// Wals-Siezenheim/Salzburgo, Austria -- este ultimo aparecio recien al
// poblar Champions League). El override viejo (solo por nombre)
// forzaba el de Salzburgo a traer la foto de Leipzig. Clave =
// "nombre|ciudad" EXACTOS tal cual estan en la base.
//
// OJO importante sobre como funciona esto: el override con clave
// "nombre|ciudad" es el UNICO punto que se chequea ANTES de llamar a
// buscarTituloWikipedia(). Esa funcion, si no hay override, prueba
// primero "<nombre>" solo -- y si esa busqueda sola ya trae un
// resultado (aunque sea el equivocado, como la pagina mas conocida de
// Leipzig), lo devuelve inmediatamente sin llegar a intentar la
// variante con ciudad. Por eso Salzburgo necesita su propia entrada
// aca, con su propia ciudad como clave -- no alcanza con que la
// funcion "reciba" la ciudad como parametro de fallback.
$overridesTituloConCiudad = [
    'Red Bull Arena|Leipzig' => 'Red Bull Arena (Leipzig)',
    // Estadio de Red Bull Salzburg (Wals-Siezenheim, Austria).
    // Titulo confirmado en Wikipedia (en): 'Red Bull Arena (Salzburg)'.
    'Red Bull Arena|Wals-Siezenheim' => 'Red Bull Arena (Salzburg)',
];

/**
 * ESTADIOS EXCLUIDOS: nombres para los que directamente NO se debe
 * intentar buscar en Wikipedia, porque no existe una pagina confiable
 * con foto real del estadio y la busqueda automatica matchea de forma
 * inconsistente con una pagina ajena (a veces sin imagen -- resultado
 * correcto por casualidad -- y a veces con imagen de un evento sin
 * relacion, lo cual SI corrompe el dato).
 *
 * Caso confirmado esta sesion: 'Vidzemes Olimpiskais centrs' (Valmiera,
 * Champions League, club chico de clasificacion) matchea de forma
 * inconsistente entre corridas con la pagina de Wikipedia 'EuroBasket
 * Women 2009' (un evento de basquet sin relacion alguna con el
 * estadio). Se detecto que una corrida con --aplicar SI llego a
 * escribir esa imagen incorrecta en la base (se revirtio a mano con
 * un UPDATE puntual). Para que no vuelva a pasar, se excluye
 * directamente en vez de dejarlo a la suerte de la busqueda en vivo.
 *
 * Si en otra liga aparece un caso similar (estadio con resultados
 * inconsistentes entre corridas, o que matchea sistematicamente con
 * algo ajeno y sin un titulo de reemplazo confiable), se agrega el
 * nombre aca.
 */
$estadiosExcluidos = [
    'Vidzemes Olimpiskais centrs',
];

echo $aplicar
    ? "=== MODO APLICAR: esto SI va a escribir en la base ===\n\n"
    : "=== MODO DIAGNOSTICO (dry-run): nada se va a escribir. Agregar --aplicar para ejecutar de verdad ===\n\n";

// Estadios de esta liga, via los equipos que los tienen vinculados.
// DISTINCT por si dos equipos comparten estadio (poco comun, pero
// posible en ligas con estadios neutrales).
$stmt = $pdo->prepare(
    'SELECT DISTINCT e.id, e.nombre, e.ciudad
     FROM estadios e
     INNER JOIN equipos eq ON eq.id_estadio = e.id
     WHERE eq.id_liga = ?'
);
$stmt->execute([$idLigaBd]);
$estadios = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($estadios)) {
    echo "No se encontraron estadios vinculados a equipos de la liga $idLigaBd.\n";
    echo "Corre primero poblar_estadios_liga_apisports.php --aplicar para esta liga.\n";
    exit(0);
}

echo count($estadios) . " estadios encontrados para liga=$idLigaBd.\n\n";

$actualizados = 0;
$sinPagina = [];
$sinImagen = [];
$excluidos = [];
$errores = 0;

foreach ($estadios as $est) {
    $nombre = $est['nombre'];

    // Chequeo de exclusion PRIMERO, antes de gastar ninguna llamada a
    // Wikipedia -- ver comentario junto a $estadiosExcluidos arriba.
    if (in_array($nombre, $estadiosExcluidos, true)) {
        $excluidos[] = $nombre;
        $logger->skipped("estadio '$nombre': excluido a proposito, busqueda de Wikipedia da resultados inconsistentes entre corridas (ver comentario en el codigo)");
        continue;
    }

    $claveConCiudad = $nombre . '|' . ($est['ciudad'] ?? '');
    $titulo = $overridesTituloConCiudad[$claveConCiudad]
        ?? $overridesTitulo[$nombre]
        ?? buscarTituloWikipedia($http, $nombre, $est['ciudad'], $idioma);

    if ($titulo === null) {
        $sinPagina[] = $nombre;
        $logger->skipped("estadio '$nombre': no se encontro pagina de Wikipedia que matchee");
        continue;
    }

    $resumen = obtenerResumenWikipedia($http, $titulo, $idioma);
    $urlImagen = $resumen['originalimage']['source'] ?? $resumen['thumbnail']['source'] ?? null;

    // Wikipedia agrega parametros de tracking (?utm_source=...) a las
    // URLs de imagen que no hacen falta para mostrar la foto y solo
    // suman caracteres innecesarios contra el limite de la columna
    // 'imagen' -- se descartan quedandonos solo con la parte antes del '?'.
    if ($urlImagen !== null) {
        $urlImagen = strtok($urlImagen, '?');
    }

    if ($urlImagen === null) {
        $sinImagen[] = $nombre;
        $logger->skipped("estadio '$nombre': pagina '$titulo' de Wikipedia encontrada pero sin imagen");
        continue;
    }

    if (!$aplicar) {
        $logger->info("ACTUALIZARIA imagen de '$nombre' -> $urlImagen (pagina Wikipedia: $titulo)");
        $actualizados++;
        continue;
    }

    // try/catch a proposito: un solo estadio con URL fuera de rango
    // (o cualquier otro error puntual de fila) no debe tumbar el resto
    // del lote -- se omite ESE estadio, se loguea el motivo, y el
    // script sigue con los demas.
    try {
        $upd = $pdo->prepare('UPDATE estadios SET imagen = ? WHERE id = ?');
        $ok = $upd->execute([$urlImagen, $est['id']]);
        if ($ok) {
            $actualizados++;
            $logger->info("Imagen actualizada: '$nombre' -> $urlImagen");
        } else {
            $errores++;
            $logger->error("Fallo el UPDATE de imagen para '$nombre'");
        }
    } catch (PDOException $e) {
        $errores++;
        $logger->error("Excepcion al actualizar imagen de '$nombre': " . $e->getMessage());
    }
}

echo "\n[RESUMEN] estadios procesados: " . count($estadios);
echo "\nActualizados: $actualizados";
if (!empty($excluidos)) {
    echo "\nExcluidos a proposito (" . count($excluidos) . "): " . implode(', ', $excluidos);
}
if (!empty($sinPagina)) {
    echo "\nSin pagina de Wikipedia encontrada (" . count($sinPagina) . "): " . implode(', ', $sinPagina);
}
if (!empty($sinImagen)) {
    echo "\nPagina encontrada pero sin imagen (" . count($sinImagen) . "): " . implode(', ', $sinImagen);
}
if ($errores > 0) {
    echo "\nErrores: $errores";
}
echo "\n";

if (!$aplicar) {
    echo "\nEsto fue solo un diagnostico. Si se ve bien, correr de nuevo con --aplicar.\n";
} else {
    echo "\nListo. Cambios aplicados.\n";
}

$logger->resumen();

/**
 * Busca el titulo exacto de una pagina de Wikipedia usando el
 * endpoint de busqueda (accion 'query', list=search). Primero prueba
 * el nombre solo, y si no trae resultados reintenta con "<nombre>
 * <ciudad>" para desambiguar nombres genericos (ej. estadios que
 * comparten nombre con uno homonimo en otro pais).
 */
function buscarTituloWikipedia(HttpClient $http, string $nombreEstadio, ?string $ciudad, string $idioma): ?string
{
    $intentos = [$nombreEstadio];
    if ($ciudad) {
        $intentos[] = "$nombreEstadio $ciudad";
    }

    foreach ($intentos as $consulta) {
        $url = "https://$idioma.wikipedia.org/w/api.php?action=query&list=search"
            . "&srsearch=" . urlencode($consulta)
            . "&srlimit=1&format=json";

        $resp = $http->get($url, ['User-Agent: GoalBet2026-Seeder/1.0 (proyecto educativo CEUTEC)']);
        if ($resp === null) {
            continue;
        }

        $resultados = $resp['query']['search'] ?? [];
        if (!empty($resultados)) {
            return $resultados[0]['title'];
        }
    }

    return null;
}

/**
 * Pide el resumen REST de una pagina de Wikipedia, que trae
 * 'originalimage' (foto principal a resolucion completa) y
 * 'thumbnail' (version chica) cuando la pagina tiene infobox con
 * imagen. Devuelve el array decodificado completo (o [] si fallo).
 */
function obtenerResumenWikipedia(HttpClient $http, string $titulo, string $idioma): array
{
    $tituloUrl = rawurlencode(str_replace(' ', '_', $titulo));
    $url = "https://$idioma.wikipedia.org/api/rest_v1/page/summary/$tituloUrl";

    $resp = $http->get($url, ['User-Agent: GoalBet2026-Seeder/1.0 (proyecto educativo CEUTEC)']);
    return $resp ?? [];
}