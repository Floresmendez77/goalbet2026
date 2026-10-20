<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}


/**
 * Version GENERICA de recalcular_tablas_futbol.php -- ese script es un
 * one-off viejo con las ligas hardcodeadas adentro (15,16,17,21,22 --
 * Premier League, La Liga, Serie A, Bundesliga, Ligue 1) y no toma
 * argumentos por linea de comandos, asi que correrlo con "28" no hacia
 * nada distinto: siempre recalcula esas mismas 5 ligas de siempre, sin
 * importar lo que le pases. No se toco ese script (para no arriesgar lo
 * que ya funciona), este es uno nuevo que si acepta el id de liga.
 *
 * No le pega a ninguna API externa, no crea ni modifica `partidos` ni
 * `equipos`. Solo relee `partidos` (estado='finalizado' con marcador) y
 * reescribe `tabla_posiciones` para la liga pedida, via
 * Repository::recalcularTablaPosiciones().
 *
 * FIX (15/8): Repository.php ahora acepta un 4to parametro opcional
 * $filtroFaseContiene, para poder pedir la tabla de UN torneo puntual
 * (ej. "Apertura" o "Clausura") en vez de mezclar toda la fase de grupos
 * de ambos torneos en una tabla combinada -- que es lo que pasaba antes
 * y no representa ninguna tabla real (Argentina tiene Apertura y
 * Clausura como 2 campeonatos cortos, cada uno con su propia tabla).
 *
 * OJO temporada: `ligas_torneos.temporada` para las ligas sembradas por
 * api-sports quedo con '2023' (metadata vieja de cuando se sembraron los
 * equipos), no representa la temporada real de los partidos que estas
 * por calcular. Por eso este script NO lee ese campo -- pedimos la
 * temporada como argumento explicito. Si vas a separar por torneo, usa
 * un sufijo distinto por torneo (ej. '2026-apertura' / '2026-clausura')
 * -- la clave unica de tabla_posiciones es (id_liga, id_equipo,
 * temporada), asi que sufijos distintos = 2 tablas separadas sin que
 * una pise a la otra.
 *
 * Uso:
 *   php recalcular_tabla_liga.php <id_liga_bd> <temporada> [filtro_fase]
 *
 * Ejemplos (Liga Profesional Argentina):
 *   # Tabla combinada (Apertura + Clausura mezclados -- NO recomendado
 *   # para Argentina, se deja disponible por si otra liga si lo necesita
 *   # combinado):
 *   php recalcular_tabla_liga.php 28 2026
 *
 *   # Tabla del Apertura sola:
 *   php recalcular_tabla_liga.php 28 2026-apertura Apertura
 *
 *   # Tabla del Clausura sola:
 *   php recalcular_tabla_liga.php 28 2026-clausura Clausura
 */

require __DIR__ . '/Env.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Logger.php';
require __DIR__ . '/Repository.php';

Env::load(__DIR__ . '/.env');

$idLigaBd = isset($argv[1]) ? (int)$argv[1] : null;
$temporada = $argv[2] ?? null;
$filtroFase = $argv[3] ?? null;

if (!$idLigaBd || !$temporada) {
    fwrite(STDERR, "Uso: php recalcular_tabla_liga.php <id_liga_bd> <temporada> [filtro_fase]\n");
    fwrite(STDERR, "Ejemplo: php recalcular_tabla_liga.php 28 2026-apertura Apertura\n");
    exit(1);
}

$pdo = Database::connect();
$logger = new Logger();
$repo = new Repository($pdo, $logger);

echo "Recalculando liga $idLigaBd (temporada $temporada)" .
    ($filtroFase !== null ? " -- solo fase que contenga '$filtroFase'" : " -- TODAS las fases de grupos combinadas") .
    "...\n";
$repo->recalcularTablaPosiciones($idLigaBd, $temporada, $filtroFase);
echo "Listo.\n";