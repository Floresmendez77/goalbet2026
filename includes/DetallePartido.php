<?php

declare(strict_types=1);

require_once __DIR__ . '/../seeder/HttpClient.php';
require_once __DIR__ . '/../seeder/Logger.php';

/**
 * Detalle de un partido de fútbol desde el endpoint "summary" de ESPN:
 * estadio, alineaciones (con posición), cronología (goles, tarjetas, cambios)
 * y estadísticas de equipo / jugador.
 *
 * parsear() es una función pura (recibe el JSON de ESPN y devuelve arreglos),
 * así se puede probar sin red ni base de datos.
 */
final class DetallePartido
{
    private const BASE_URL = 'https://site.api.espn.com/apis/site/v2/sports/soccer';

    /** Estadísticas de jugador que se guardan (clave ESPN => clave corta). */
    private const STATS_JUGADOR = [
        'totalGoals' => 'goles', 'goalAssists' => 'asistencias', 'totalShots' => 'tiros',
        'shotsOnTarget' => 'tiros_arco', 'foulsCommitted' => 'faltas', 'foulsSuffered' => 'faltas_recibidas',
        'yellowCards' => 'amarillas', 'redCards' => 'rojas', 'saves' => 'atajadas',
        'goalsConceded' => 'goles_contra', 'ownGoals' => 'autogoles', 'offsides' => 'fuera_de_juego',
    ];

    /** Estadísticas de equipo que se guardan. */
    private const STATS_EQUIPO = [
        'possessionPct', 'totalShots', 'shotsOnTarget', 'blockedShots', 'wonCorners', 'foulsCommitted',
        'yellowCards', 'redCards', 'offsides', 'saves', 'totalPasses', 'accuratePasses', 'passPct',
        'totalCrosses', 'accurateCrosses', 'totalTackles', 'interceptions', 'totalClearance',
    ];

    /** Corta a $max caracteres sin depender de la extensión mbstring. */
    private static function cortar(string $s, int $max): string
    {
        return preg_match('/^.{0,' . $max . '}/us', $s, $m) ? $m[0] : substr($s, 0, $max);
    }

    // ------------------------------------------------------------------ parseo

    /**
     * @param array $s         JSON de ESPN (summary)
     * @param int   $idLocal   equipos.id del local
     * @param int   $idVisita  equipos.id de la visita
     * @return array{detalle: array, alineacion: array, eventos: array}
     */
    public static function parsear(array $s, int $idLocal, int $idVisita): array
    {
        // id de equipo ESPN -> ['lado' => 'home'|'away', 'id' => equipos.id]
        $equipos = [];
        foreach ($s['header']['competitions'][0]['competitors'] ?? [] as $c) {
            $lado = $c['homeAway'] ?? null;
            $espn = (string) ($c['team']['id'] ?? '');
            if ($espn !== '' && ($lado === 'home' || $lado === 'away')) {
                $equipos[$espn] = ['lado' => $lado, 'id' => $lado === 'home' ? $idLocal : $idVisita];
            }
        }
        foreach ($s['rosters'] ?? [] as $r) {
            $espn = (string) ($r['team']['id'] ?? '');
            $lado = $r['homeAway'] ?? null;
            if ($espn !== '' && !isset($equipos[$espn]) && ($lado === 'home' || $lado === 'away')) {
                $equipos[$espn] = ['lado' => $lado, 'id' => $lado === 'home' ? $idLocal : $idVisita];
            }
        }

        // ---- alineaciones
        $alineacion = [];
        $jugadorEquipo = []; // athlete id -> equipos.id
        $formacion = ['home' => null, 'away' => null];
        foreach ($s['rosters'] ?? [] as $r) {
            $espnEquipo = (string) ($r['team']['id'] ?? '');
            if (!isset($equipos[$espnEquipo])) {
                continue;
            }
            $idEquipo = $equipos[$espnEquipo]['id'];
            $formacion[$equipos[$espnEquipo]['lado']] = $r['formation'] ?? null;
            foreach ($r['roster'] ?? [] as $j) {
                $aid = (string) ($j['athlete']['id'] ?? '');
                if ($aid === '') {
                    continue;
                }
                $stats = [];
                foreach ($j['stats'] ?? [] as $st) {
                    $k = self::STATS_JUGADOR[$st['name'] ?? ''] ?? null;
                    if ($k !== null && isset($st['value']) && is_numeric($st['value'])) {
                        $stats[$k] = $st['value'] + 0;
                    }
                }
                $titular = !empty($j['starter']);
                $alineacion[] = [
                    'id_equipo' => $idEquipo,
                    'espn_athlete_id' => $aid,
                    'nombre' => (string) ($j['athlete']['displayName'] ?? $j['athlete']['fullName'] ?? '?'),
                    'dorsal' => isset($j['jersey']) ? (string) $j['jersey'] : null,
                    'posicion' => $j['position']['abbreviation'] ?? null,
                    'lugar' => $titular ? (int) ($j['formationPlace'] ?? 0) : 0,
                    'titular' => $titular ? 1 : 0,
                    'entro' => !empty($j['subbedIn']) ? 1 : 0,
                    'salio' => !empty($j['subbedOut']) ? 1 : 0,
                    'stats' => $stats,
                ];
                $jugadorEquipo[$aid] = $idEquipo;
            }
        }

        // ---- detalles de goles (marcan penal / autogol / roja con precisión)
        $detalles = $s['header']['competitions'][0]['details'] ?? [];
        $marcaDetalle = static function (array $ev) use ($detalles): array {
            $seg = $ev['clock']['value'] ?? null;
            $aid = (string) ($ev['participants'][0]['athlete']['id'] ?? '');
            foreach ($detalles as $d) {
                if (($d['clock']['value'] ?? null) === $seg
                    && (string) ($d['participants'][0]['athlete']['id'] ?? '') === $aid) {
                    return ['penal' => !empty($d['penaltyKick']), 'autogol' => !empty($d['ownGoal']), 'roja' => !empty($d['redCard'])];
                }
            }
            return ['penal' => false, 'autogol' => false, 'roja' => false];
        };

        // ---- eventos, en orden cronológico
        $crudos = [];
        foreach (array_values($s['keyEvents'] ?? []) as $i => $ev) {
            $crudos[] = [$ev['period']['number'] ?? 0, $ev['clock']['value'] ?? 0, $i, $ev];
        }
        usort($crudos, static fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $eventos = [];
        $golesLocal = 0;
        $golesVisita = 0;
        $orden = 0;
        foreach ($crudos as [, , , $ev]) {
            $tipoEspn = strtolower((string) ($ev['type']['type'] ?? ''));
            $textoTipo = strtolower((string) ($ev['type']['text'] ?? ''));
            $part = $ev['participants'] ?? [];
            $p1 = $part[0]['athlete'] ?? null;
            $p2 = $part[1]['athlete'] ?? null;
            $espnEquipo = (string) ($ev['team']['id'] ?? '');
            $idEquipo = isset($equipos[$espnEquipo]) ? $equipos[$espnEquipo]['id'] : null;
            // El equipo del jugador es más confiable que el del evento (autogoles).
            if ($p1 && isset($jugadorEquipo[(string) ($p1['id'] ?? '')])) {
                $idEquipo = $jugadorEquipo[(string) $p1['id']];
            }

            $tipo = null;
            $esGol = !empty($ev['scoringPlay']);
            if ($esGol) {
                $m = $marcaDetalle($ev);
                $tipo = ($m['autogol'] || str_contains($textoTipo, 'own')) ? 'autogol'
                    : (($m['penal'] || str_contains($textoTipo, 'penalty')) ? 'gol_penal' : 'gol');
            } elseif ($tipoEspn === 'halftime') {
                $tipo = 'medio_tiempo';
            } elseif (str_contains($tipoEspn, 'yellow') && str_contains($tipoEspn, 'red')) {
                $tipo = 'segunda_amarilla';
            } elseif (str_contains($tipoEspn, 'red-card')) {
                $tipo = 'roja';
            } elseif (str_contains($tipoEspn, 'yellow')) {
                $tipo = 'amarilla';
            } elseif ($tipoEspn === 'substitution') {
                $tipo = 'cambio';
            } elseif (str_contains($tipoEspn, 'penalty') && !$esGol) {
                $tipo = 'penal_fallado';
            } elseif (str_contains($tipoEspn, 'var')) {
                $tipo = 'var';
            }
            if ($tipo === null) {
                continue; // inicio, fin, demoras, etc.
            }

            // Marcador acumulado después de cada gol
            $ml = $mv = null;
            if ($esGol) {
                if (preg_match('/(\d+),\s+.*?\s(\d+)\.(?:\s|$)/u', (string) ($ev['text'] ?? ''), $mm)) {
                    $golesLocal = (int) $mm[1];
                    $golesVisita = (int) $mm[2];
                } else {
                    $lado = $idEquipo === $idLocal ? 'home' : 'away';
                    if ($tipo === 'autogol') {
                        $lado = $lado === 'home' ? 'away' : 'home';
                    }
                    $lado === 'home' ? $golesLocal++ : $golesVisita++;
                }
                $ml = $golesLocal;
                $mv = $golesVisita;
            }

            $eventos[] = [
                'espn_event_id' => (string) ($ev['id'] ?? ('x' . $orden)),
                'orden' => $orden++,
                'periodo' => (int) ($ev['period']['number'] ?? 0),
                'minuto' => (string) ($ev['clock']['displayValue'] ?? ''),
                'segundos' => (int) ($ev['clock']['value'] ?? 0),
                'tipo' => $tipo,
                'id_equipo' => $idEquipo,
                'jugador' => $p1 ? (string) ($p1['displayName'] ?? '') : null,
                'jugador_id' => $p1 ? (string) ($p1['id'] ?? '') : null,
                'jugador2' => $p2 ? (string) ($p2['displayName'] ?? '') : null,
                'jugador2_id' => $p2 ? (string) ($p2['id'] ?? '') : null,
                'detalle' => isset($ev['text']) ? self::cortar((string) $ev['text'], 500) : null,
                'marcador_local' => $ml,
                'marcador_visita' => $mv,
            ];
        }

        // ---- estadísticas de equipo
        $statsEq = ['home' => [], 'away' => []];
        foreach ($s['boxscore']['teams'] ?? [] as $t) {
            $lado = $t['homeAway'] ?? ($equipos[(string) ($t['team']['id'] ?? '')]['lado'] ?? null);
            if ($lado !== 'home' && $lado !== 'away') {
                continue;
            }
            foreach ($t['statistics'] ?? [] as $st) {
                if (in_array($st['name'] ?? '', self::STATS_EQUIPO, true) && is_numeric($st['displayValue'] ?? null)) {
                    $statsEq[$lado][$st['name']] = $st['displayValue'] + 0;
                }
            }
        }

        $venue = $s['gameInfo']['venue'] ?? [];
        $asist = (int) ($s['gameInfo']['attendance'] ?? 0);

        return [
            'detalle' => [
                'estadio_nombre' => $venue['fullName'] ?? null,
                'estadio_ciudad' => $venue['address']['city'] ?? null,
                'asistencia' => $asist > 0 ? $asist : null,
                'formacion_local' => $formacion['home'],
                'formacion_visita' => $formacion['away'],
                'stats_local' => $statsEq['home'],
                'stats_visita' => $statsEq['away'],
                'tiene_datos' => ($alineacion || $eventos || $statsEq['home']) ? 1 : 0,
            ],
            'alineacion' => $alineacion,
            'eventos' => $eventos,
        ];
    }

    // ------------------------------------------------------------- base de datos

    /** ¿Existen las tablas nuevas? (si no se corrió database/detalle_partido.sql) */
    public static function tablasListas(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $pdo->query('SELECT 1 FROM partido_detalle LIMIT 1');
                $pdo->query('SELECT 1 FROM partido_alineacion LIMIT 1');
                $pdo->query('SELECT 1 FROM partido_eventos LIMIT 1');
                $ok = true;
            } catch (PDOException) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** id de ESPN + slug de liga de un partido de fútbol, o null si no se puede importar. */
    public static function referencia(PDO $pdo, int $idPartido): ?array
    {
        $st = $pdo->prepare(
            "SELECT p.id, p.estado, p.id_equipo_local, p.id_equipo_visita, p.fecha_partido,
                    ei.external_id AS espn_id, ls.slug_espn
             FROM partidos p
             JOIN deportes d ON d.id = p.id_deporte
             JOIN external_ids ei ON ei.entidad = 'partido' AND ei.entidad_id = p.id AND ei.fuente = 'espn-futbol'
             JOIN ligas_sync ls ON ls.id_liga = p.id_liga AND ls.deporte = 'futbol'
             WHERE p.id = ?
             LIMIT 1"
        );
        $st->execute([$idPartido]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Descarga el summary de ESPN y lo guarda. No lanza excepciones: devuelve
     * ['ok' => bool, 'mensaje' => string]. El Logger del seeder imprime con echo,
     * por eso se captura la salida para no ensuciar la página web.
     */
    public static function importar(PDO $pdo, int $idPartido, int $maxIntentos = 1): array
    {
        $ref = self::referencia($pdo, $idPartido);
        if (!$ref) {
            return ['ok' => false, 'mensaje' => 'Este partido no tiene datos de ESPN vinculados.'];
        }

        ob_start();
        try {
            $http = new HttpClient(new Logger());
            $url = self::BASE_URL . '/' . rawurlencode((string) $ref['slug_espn']) . '/summary?event=' . rawurlencode((string) $ref['espn_id']);
            $json = $http->get($url, [], $maxIntentos);
        } catch (Throwable $e) {
            $json = null;
        }
        ob_end_clean();

        if (!is_array($json)) {
            self::marcarIntento($pdo, $idPartido, (string) $ref['estado'], 'ESPN no respondió');
            return ['ok' => false, 'mensaje' => 'ESPN no respondió. Probá de nuevo en unos minutos.'];
        }

        $datos = self::parsear($json, (int) $ref['id_equipo_local'], (int) $ref['id_equipo_visita']);
        if (!$datos['detalle']['tiene_datos']) {
            self::marcarIntento($pdo, $idPartido, (string) $ref['estado'], 'ESPN no publica detalle para este partido');
            return ['ok' => false, 'mensaje' => 'ESPN todavía no publica el detalle de este partido.'];
        }

        try {
            self::guardar($pdo, $idPartido, (string) $ref['estado'], $datos);
        } catch (Throwable $e) {
            error_log('DetallePartido::guardar #' . $idPartido . ': ' . $e->getMessage());
            return ['ok' => false, 'mensaje' => 'No se pudo guardar el detalle.'];
        }
        return ['ok' => true, 'mensaje' => sprintf(
            '%d jugadores, %d eventos.',
            count($datos['alineacion']),
            count($datos['eventos'])
        )];
    }

    private static function marcarIntento(PDO $pdo, int $idPartido, string $estado, string $error): void
    {
        $pdo->prepare(
            'INSERT INTO partido_detalle (id_partido, tiene_datos, estado_importado, error, intento_at)
             VALUES (?, 0, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE error = VALUES(error), intento_at = UTC_TIMESTAMP(),
                                     estado_importado = IF(tiene_datos = 1, estado_importado, VALUES(estado_importado))'
        )->execute([$idPartido, $estado, self::cortar($error, 250)]);
    }

    private static function guardar(PDO $pdo, int $idPartido, string $estado, array $d): void
    {
        $json = static fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->beginTransaction();
        try {
            $x = $d['detalle'];
            $pdo->prepare(
                'INSERT INTO partido_detalle (id_partido, tiene_datos, estadio_nombre, estadio_ciudad, asistencia,
                        formacion_local, formacion_visita, stats_local, stats_visita, estado_importado, error,
                        intento_at, importado_at)
                 VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE tiene_datos = 1, estadio_nombre = VALUES(estadio_nombre),
                        estadio_ciudad = VALUES(estadio_ciudad), asistencia = VALUES(asistencia),
                        formacion_local = VALUES(formacion_local), formacion_visita = VALUES(formacion_visita),
                        stats_local = VALUES(stats_local), stats_visita = VALUES(stats_visita),
                        estado_importado = VALUES(estado_importado), error = NULL,
                        intento_at = UTC_TIMESTAMP(), importado_at = UTC_TIMESTAMP()'
            )->execute([
                $idPartido, $x['estadio_nombre'], $x['estadio_ciudad'], $x['asistencia'],
                $x['formacion_local'], $x['formacion_visita'], $json($x['stats_local']), $json($x['stats_visita']), $estado,
            ]);

            $pdo->prepare('DELETE FROM partido_alineacion WHERE id_partido = ?')->execute([$idPartido]);
            $ins = $pdo->prepare(
                'INSERT INTO partido_alineacion (id_partido, id_equipo, espn_athlete_id, nombre, dorsal, posicion,
                        lugar, titular, entro, salio, stats)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)'
            );
            foreach ($d['alineacion'] as $j) {
                $ins->execute([
                    $idPartido, $j['id_equipo'], $j['espn_athlete_id'], self::cortar((string) $j['nombre'], 150), $j['dorsal'],
                    $j['posicion'], $j['lugar'], $j['titular'], $j['entro'], $j['salio'], $json($j['stats']),
                ]);
            }

            $pdo->prepare('DELETE FROM partido_eventos WHERE id_partido = ?')->execute([$idPartido]);
            $ins = $pdo->prepare(
                'INSERT INTO partido_eventos (id_partido, espn_event_id, orden, periodo, minuto, segundos, tipo, id_equipo,
                        jugador, jugador_id, jugador2, jugador2_id, detalle, marcador_local, marcador_visita)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE orden = VALUES(orden)'
            );
            foreach ($d['eventos'] as $e) {
                $ins->execute([
                    $idPartido, $e['espn_event_id'], $e['orden'], $e['periodo'], $e['minuto'], $e['segundos'], $e['tipo'],
                    $e['id_equipo'], $e['jugador'], $e['jugador_id'], $e['jugador2'], $e['jugador2_id'],
                    $e['detalle'], $e['marcador_local'], $e['marcador_visita'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * ¿Conviene (re)importar al abrir la página del partido?
     * $det = fila de partido_detalle o null.
     */
    public static function debeImportar(string $estado, ?string $fechaUtc, ?array $det): bool
    {
        $ahora = time();
        $intento = $det ? strtotime(($det['intento_at'] ?? '') . ' UTC') : 0;
        $enVentana = $estado === 'en_curso' || $estado === 'finalizado'
            || ($estado === 'programado' && $fechaUtc !== null && strtotime($fechaUtc . ' UTC') - $ahora < 5400);
        if (!$enVentana) {
            return false;
        }
        if (!$det) {
            return true;
        }
        if ((int) $det['tiene_datos'] === 1) {
            if ($estado === 'en_curso') {
                return $ahora - $intento > 90;                      // refresco en vivo
            }
            return $estado === 'finalizado' && ($det['estado_importado'] ?? '') !== 'finalizado'; // una vez al terminar
        }
        return $ahora - $intento > ($estado === 'en_curso' ? 90 : 6 * 3600); // sin datos: reintento espaciado
    }

    // --------------------------------------------------------------- cancha

    /**
     * Ordena los titulares en filas (arquero, defensa, medio, ataque) y calcula
     * su posición en la cancha en porcentajes. Se basa en la abreviatura de la
     * posición (CD-L, LB, CM-R, CF...) porque el número de "lugar" de ESPN no
     * sigue el orden de las filas.
     *
     * @param array $titulares filas de partido_alineacion (titular = 1)
     * @param ?string $formacion formación de ESPN ("4-3-3"); si es válida define cuántos jugadores va en cada fila
     * @return array<int, array> mismas filas con 'x' e 'y' (0-100, y=0 arriba / ataque)
     */
    public static function ubicarEnCancha(array $titulares, ?string $formacion = null): array
    {
        // Con formación válida ("4-3-3", "3-4-2-1"...) las filas tienen exactamente esa cantidad de jugadores.
        $lineas = [];
        if ($formacion !== null && preg_match('/^\d(?:-\d){1,5}$/', trim($formacion))) {
            $lineas = array_map('intval', explode('-', trim($formacion)));
        }
        $esPortero = static fn(array $j): bool => in_array(strtoupper((string) ($j['posicion'] ?? '')), ['G', 'GK'], true);
        $porteros = array_values(array_filter($titulares, $esPortero));
        $campo = array_values(array_filter($titulares, static fn(array $j): bool => !$esPortero($j)));
        if (!$lineas || count($porteros) !== 1 || count($campo) !== array_sum($lineas)) {
            return self::ubicarPorPosicion($titulares); // sin formación usable: agrupa por abreviatura
        }

        // Profundidad: qué tan adelantado juega según la abreviatura de ESPN.
        $prof = static function (string $p): float {
            if (preg_match('/^(LWB|RWB)/', $p)) { return 1.5; }                 // carrileros: defensa o medio según la formación
            if (preg_match('/^(CD|CB|SW|LB|RB)/', $p) || $p === 'D') { return 1.0; }
            if (preg_match('/^(DM|CDM)/', $p)) { return 2.0; }
            if (preg_match('/^(AM|CAM|SS)/', $p)) { return 4.0; }
            if (preg_match('/^(LW|RW)/', $p)) { return 4.2; }
            if (preg_match('/^(LF|RF|LCF|RCF)/', $p) || preg_match('/^(CF|F|ST|S)(-[LR])?$/', $p)) {
                return preg_match('/^(LF|RF|LCF|RCF)|-[LR]$/', $p) ? 4.9 : 5.0;  // los "abiertos" van un pelo detrás del 9
            }
            return 3.0;                                                          // CM, LM, RM, M y desconocidos
        };
        $lado = static function (string $p): int {
            if (preg_match('/^(LB|LWB|LM|LW)$/', $p)) { return -2; }
            if (preg_match('/^(RB|RWB|RM|RW)$/', $p)) { return 2; }
            if (preg_match('/^(LF|LCF)$/', $p) || preg_match('/-L$/', $p)) { return -1; }
            if (preg_match('/^(RF|RCF)$/', $p) || preg_match('/-R$/', $p)) { return 1; }
            return 0;
        };
        foreach ($campo as $i => $j) {
            $p = strtoupper((string) ($j['posicion'] ?? ''));
            $campo[$i]['_p'] = $prof($p);
            $campo[$i]['_l'] = $lado($p);
        }
        // De atrás hacia adelante; a igual profundidad los laterales (LB/RB) primero para que siempre queden en la zaga.
        usort($campo, static fn($a, $b) => [$a['_p'], -abs($a['_l']), $a['_l'], (int) $a['lugar']]
            <=> [$b['_p'], -abs($b['_l']), $b['_l'], (int) $b['lugar']]);

        $filas = [[$porteros[0]]];
        $desde = 0;
        foreach ($lineas as $cant) {
            $grupo = array_slice($campo, $desde, $cant);
            $desde += $cant;
            usort($grupo, static fn($a, $b) => [$a['_l'], (int) $a['lugar']] <=> [$b['_l'], (int) $b['lugar']]);
            $filas[] = $grupo;
        }
        $n = count($filas);
        $res = [];
        foreach ($filas as $i => $grupo) {
            $y = 92 - $i * (80 / ($n - 1)); // arquero abajo (92), ataque arriba (12)
            $c = count($grupo);
            foreach ($grupo as $k => $j) {
                unset($j['_p'], $j['_l']);
                $j['x'] = round(($k + 1) * 100 / ($c + 1), 1);
                $j['y'] = round($y, 1);
                $res[] = $j;
            }
        }
        return $res;
    }

    /** Reparto de respaldo (sin formación válida): agrupa por la abreviatura de la posición. */
    private static function ubicarPorPosicion(array $titulares): array
    {
        $fila = static function (string $pos): int {
            $p = strtoupper($pos);
            if ($p === 'G' || $p === 'GK') { return 0; }
            if (preg_match('/^(DM|CDM)/', $p)) { return 2; }
            if (preg_match('/^(CD|CB|SW|LB|RB|LWB|RWB)/', $p) || $p === 'D') { return 1; }
            if (preg_match('/^(AM|CAM|SS|LW|RW|W)/', $p) && !str_starts_with($p, 'CM')) { return 4; }
            if (preg_match('/^(F|CF|ST|S)/', $p)) { return 5; }
            return 3; // CM, LM, RM y desconocidos
        };
        $lado = static function (string $pos): int {
            $p = strtoupper($pos);
            if (preg_match('/^(LB|LWB|LM|LW)$/', $p)) { return -2; }
            if (preg_match('/^(RB|RWB|RM|RW)$/', $p)) { return 2; }
            if (preg_match('/-L$/', $p)) { return -1; }
            if (preg_match('/-R$/', $p)) { return 1; }
            return 0;
        };

        $filas = [];
        foreach ($titulares as $j) {
            $pos = (string) ($j['posicion'] ?? '');
            $j['_fila'] = $fila($pos);
            $j['_lado'] = $lado($pos);
            $filas[$j['_fila']][] = $j;
        }
        ksort($filas);
        $n = count($filas);
        $res = [];
        $i = 0;
        foreach ($filas as $grupo) {
            usort($grupo, static fn($a, $b) => [$a['_lado'], (int) $a['lugar']] <=> [$b['_lado'], (int) $b['lugar']]);
            $y = $n > 1 ? 92 - ($i * (80 / ($n - 1))) : 50; // arquero abajo (92), ataque arriba (12)
            $c = count($grupo);
            foreach ($grupo as $k => $j) {
                $j['x'] = round(($k + 1) * 100 / ($c + 1), 1);
                $j['y'] = round($y, 1);
                unset($j['_fila'], $j['_lado']);
                $res[] = $j;
            }
            $i++;
        }
        return $res;
    }
}