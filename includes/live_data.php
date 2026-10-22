<?php

/**
 * Datos "en vivo" de partidos, LEÍDOS DE NUESTRA BASE DE DATOS (nunca de ESPN).
 *
 * Quien actualiza la base es un solo proceso: cron/live_cron.php. Los usuarios solo leen
 * esto a través de api/live.php, así que el costo de la API de ESPN depende de cuántos
 * partidos hay en vivo, no de cuántos usuarios están conectados.
 *
 * Compatible con MySQL 5.7 (sin funciones de ventana).
 */

declare(strict_types=1);

const LIVE_MAX_IDS      = 50;   // partidos por consulta
const LIVE_MAX_EVENTOS  = 400;  // eventos por partido
const LIVE_POLL_EN_VIVO = 15;   // seg. sugeridos al cliente: partido en juego
const LIVE_POLL_VENTANA = 30;   // partido a punto de empezar / recién empezado (ventana del cron)
const LIVE_POLL_CERCANO = 60;   // programado que empieza en menos de 2 horas

/**
 * Cada cuántos segundos debería volver a preguntar el navegador por este partido.
 * NULL = no hace falta seguir preguntando (finalizado, suspendido, o falta mucho).
 */
function live_poll_partido(string $estado, string $fechaPartidoUtc, ?int $ahora = null): ?int
{
    if ($estado === 'en_curso') {
        return LIVE_POLL_EN_VIVO;
    }
    if ($estado !== 'programado') {
        return null;
    }
    $ahora ??= time();
    $falta = (int) strtotime($fechaPartidoUtc . ' UTC') - $ahora; // segundos hasta el inicio (negativo = ya debió empezar)

    // Misma ventana que usa el cron en vivo para considerar "activo" un partido: -4 h .. +15 min.
    if ($falta >= -4 * 3600 && $falta <= 15 * 60) {
        return LIVE_POLL_VENTANA;
    }
    if ($falta > 15 * 60 && $falta <= 2 * 3600) {
        return LIVE_POLL_CERCANO;
    }
    return null;
}

/**
 * Estado actual de varios partidos con UNA sola consulta.
 *
 * @param  int[] $ids
 * @return array<int,array> id => ['estado','en_vivo','minuto','marcador','inicio_utc','poll', '_eq' => [local, visita]]
 *                          (la clave '_eq' es interna: live_publicar() la quita)
 */
function live_estados(PDO $pdo, array $ids, ?int $ahora = null): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        "SELECT id, estado, marcador_local, marcador_visita, minuto_actual, fecha_partido, id_equipo_local, id_equipo_visita
         FROM partidos WHERE id IN ($in)"
    );
    $st->execute($ids);

    $res = [];
    foreach ($st->fetchAll() as $r) {
        $enVivo = $r['estado'] === 'en_curso';
        $minuto = trim((string) ($r['minuto_actual'] ?? ''));
        $res[(int) $r['id']] = [
            'estado'     => (string) $r['estado'],
            'en_vivo'    => $enVivo,
            'minuto'     => ($enVivo && $minuto !== '') ? $minuto : null,
            'marcador'   => ($r['marcador_local'] === null || $r['marcador_visita'] === null)
                ? null
                : ['local' => (int) $r['marcador_local'], 'visita' => (int) $r['marcador_visita']],
            'inicio_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($r['fecha_partido'] . ' UTC')),
            'poll'       => live_poll_partido((string) $r['estado'], (string) $r['fecha_partido'], $ahora),
            '_eq'        => [(int) $r['id_equipo_local'], (int) $r['id_equipo_visita']],
        ];
    }
    return $res;
}

/**
 * Línea de tiempo del partido (goles con asistente, tarjetas, cambios...) desde partido_eventos.
 *
 * - gol / gol_penal / autogol: 'jugador' = quien anota, 'asistente' = quien dio el pase.
 * - cambio: 'entra' = quien entra, 'sale' = quien sale.
 * - 'lado' = 'local' | 'visita' | null según el equipo del evento.
 */
function live_eventos(PDO $pdo, int $idPartido, int $idLocal, int $idVisita): array
{
    try {
        $st = $pdo->prepare(
            'SELECT id, orden, periodo, minuto, tipo, id_equipo, jugador, jugador2, detalle, marcador_local, marcador_visita
             FROM partido_eventos WHERE id_partido = ? ORDER BY orden, id LIMIT ' . LIVE_MAX_EVENTOS
        );
        $st->execute([$idPartido]);
    } catch (PDOException) {
        return []; // la tabla todavía no existe en esta instalación
    }

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $idEq = $r['id_equipo'] === null ? null : (int) $r['id_equipo'];
        $e = [
            'id'      => (int) $r['id'],
            'orden'   => (int) $r['orden'],
            'minuto'  => $r['minuto'] !== null ? (string) $r['minuto'] : null,
            'tipo'    => (string) $r['tipo'],
            'lado'    => $idEq === $idLocal ? 'local' : ($idEq === $idVisita ? 'visita' : null),
            'jugador' => $r['jugador'] !== null ? (string) $r['jugador'] : null,
        ];
        if (in_array($r['tipo'], ['gol', 'gol_penal'], true)) {
            $e['asistente'] = $r['jugador2'] !== null ? (string) $r['jugador2'] : null;
        }
        if ($r['tipo'] === 'cambio') {
            $e['entra'] = $e['jugador'];
            $e['sale']  = $r['jugador2'] !== null ? (string) $r['jugador2'] : null;
        }
        if ($r['marcador_local'] !== null && $r['marcador_visita'] !== null) {
            $e['marcador'] = ['local' => (int) $r['marcador_local'], 'visita' => (int) $r['marcador_visita']];
        }
        if (!empty($r['detalle'])) {
            $e['detalle'] = (string) $r['detalle'];
        }
        $out[] = $e;
    }
    return $out;
}

/** Quita lo interno y arma la parte pública de la respuesta. */
function live_publicar(array $estados, ?PDO $pdo = null, bool $conEventos = false): array
{
    $salida = [];
    foreach ($estados as $id => $s) {
        [$idL, $idV] = $s['_eq'];
        unset($s['_eq']);
        if ($conEventos && $pdo !== null) {
            $s['eventos'] = live_eventos($pdo, (int) $id, $idL, $idV);
        }
        $salida[(string) $id] = $s;
    }
    return $salida;
}

/** Cada cuántos segundos preguntar, considerando todos los partidos: el menor; NULL si ninguno lo necesita. */
function live_poll_global(array $estados): ?int
{
    $polls = array_filter(array_column($estados, 'poll'), static fn ($p) => $p !== null);
    return $polls ? min($polls) : null;
}

/** Huella del contenido (para ETag). No incluye la hora del servidor: solo cambia si cambian los datos. */
function live_version(array $payload): string
{
    return substr(sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)), 0, 20);
}

/**
 * Atributos HTML que enganchan una tarjeta/cabecera de partido al JavaScript de tiempo real
 * (public/js/live.js). $p = fila de `partidos` (necesita id, estado y fecha_partido, que se guarda en UTC).
 * $conEventos = true en partido.php: pide también la línea de tiempo (goles, asistencias, tarjetas...).
 */
function live_attrs(array $p, bool $conEventos = false): string
{
    $inicio = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $p['fecha_partido'] . ' UTC'));
    return 'data-live-partido="' . (int) $p['id'] . '"'
        . ' data-live-estado="' . e((string) $p['estado']) . '"'
        . ' data-live-inicio="' . e($inicio) . '"'
        . ($conEventos ? ' data-live-eventos="1"' : '');
}
