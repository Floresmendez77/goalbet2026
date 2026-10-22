<?php

/**
 * SEEDER DE RESULTADOS VIA ESPN — cubre el hueco que no cubre ningun
 * otro seeder del proyecto: traer marcadores reales (scoreboard) desde
 * la API publica/no oficial de ESPN y dejarlos escritos en `partidos`.
 *
 * Contexto (para quien retome esto despues):
 *   - `actualizar_plantilla_espn.php` YA usa ESPN, pero solo para
 *     plantillas (jugadores), solo futbol, y matchea por nombre en
 *     cada corrida sin guardar el vinculo en `external_ids`.
 *   - Ningun seeder existente (FootballDataSeeder / ApiSportsSeeder /
 *     TheSportsDbSeeder) usa ESPN. Los marcadores hoy vienen de
 *     football-data.org / api-sports.io.
 *   - Esta clase llena ese hueco: por cada partido finalizado que
 *     ESPN reporta en el rango de fechas pedido, busca el partido
 *     correspondiente YA sembrado en la base (por otra fuente) y le
 *     escribe marcador + estado='finalizado'. Eso solo alcanza para
 *     que el trigger `trg_partidos_resolver_apuestas` liquide las
 *     apuestas pendientes -- no hay que tocar nada mas.
 *
 * DECISION DE DISEÑO: esta clase NO pasa por Repository::upsertPartido()
 * ni Repository::upsertEquipo(). Motivo: esos metodos matchean
 * ENTIDADES EXCLUSIVAMENTE por (fuente, entidad, external_id) en
 * `external_ids` -- si no hay un vinculo 'espn' todavia (que es el caso
 * la primera vez que se corre esto), un upsert normal INSERTARIA una
 * fila NUEVA en vez de actualizar la que ya sembro football-data/
 * api-sports, duplicando el partido (el mismo tipo de bug que ya
 * documentan migrar_partidos_premier.php / migrar_partidos_champions.php
 * en este proyecto). Por eso el flujo real es:
 *
 *   1. Buscar el vinculo 'espn' en external_ids (rapido, camino feliz
 *      en corridas repetidas).
 *   2. Si no existe: resolver el equipo/partido por coincidencia
 *      (nombre normalizado para equipos, par de equipos + fecha
 *      cercana para partidos) contra lo que YA hay en la base.
 *   3. Recien ahi UPDATE directo a la fila existente + se guarda el
 *      vinculo en external_ids para que la proxima corrida entre por
 *      el camino rapido (paso 1).
 *
 * Nunca inserta un equipo nuevo a ciegas (mismo criterio que
 * actualizar_plantilla_espn.php). Si pedis --crear-nuevos SI puede
 * insertar un PARTIDO nuevo (no equipo) cuando el par de equipos ya
 * existe en la base pero ese partido puntual todavia no fue sembrado
 * por ningun seeder -- pensado para completar calendario, no solo
 * resultados.
 *
 * Requiere PHP 8.1+ (usa match()).
 */
class EspnSeeder
{
    private const BASE_URL = 'https://site.api.espn.com/apis/site/v2/sports';

    /** deporte (slug de `deportes`) -> segmento de ruta que usa ESPN */
    private const SPORT_PATHS = [
        'futbol'           => 'soccer',
        'baloncesto'       => 'basketball',
        'beisbol'          => 'baseball',
        'futbol-americano' => 'football',
    ];

    /** ventana de tolerancia (horas) al matchear un partido por fecha,
     *  para absorber diferencias de huso horario entre ESPN y como
     *  quedo guardado `fecha_partido` originalmente */
    private const VENTANA_HORAS = 36;

    private int $creados = 0;
    private int $actualizados = 0;
    private int $omitidos = 0;
    private int $sinMatchEquipo = 0;

    public function __construct(
        private Repository $repo,
        private PDO $pdo,
        private HttpClient $http,
        private Logger $logger
    ) {
    }

    private function fuente(string $deporteSlug): string
    {
        // Namespaced por deporte -- mismo motivo que ApiSportsSeeder::fuente():
        // el id numerico de equipo/partido de ESPN NO es unico entre
        // deportes (equipo 3445 de soccer no tiene nada que ver con el
        // 3445 de baseball), asi que compartir un solo 'espn' generico
        // pisaria external_ids entre deportes.
        return 'espn-' . $deporteSlug;
    }

    /**
     * @param string $deporteSlug  'futbol'|'baloncesto'|'beisbol'|'futbol-americano'
     * @param string $ligaEspn     slug de liga de ESPN (ej. 'bra.1', 'eng.1', 'nba', 'mlb', 'nfl')
     * @param int    $idLigaDestino  id de ligas_torneos donde viven los partidos a actualizar
     * @param string $desde       'YYYY-MM-DD'
     * @param string $hasta       'YYYY-MM-DD' (inclusive)
     * @param bool   $aplicar     false = solo diagnostico, no escribe nada
     * @param bool   $crearNuevos true = si un partido de ESPN no tiene par en la base, lo crea
     */
    public function sincronizarResultados(
        string $deporteSlug,
        string $ligaEspn,
        int $idLigaDestino,
        string $desde,
        string $hasta,
        bool $aplicar = false,
        bool $crearNuevos = false
    ): array {
        if (!isset(self::SPORT_PATHS[$deporteSlug])) {
            throw new InvalidArgumentException(
                'deporte debe ser: ' . implode('|', array_keys(self::SPORT_PATHS))
            );
        }

        $sportPath = self::SPORT_PATHS[$deporteSlug];
        $idDeporte = $this->repo->idDeportePorSlug($deporteSlug);
        $tieneEmpate = $this->deporteTieneEmpate($idDeporte);
        $fuente = $this->fuente($deporteSlug);

        // Indice de equipos LOCALES del deporte (no solo los de esta
        // liga -- mismo motivo que actualizar_plantilla_espn.php: un
        // equipo grande puede vivir con id_liga de un torneo continental).
        $stmt = $this->pdo->prepare('SELECT id, nombre, id_liga FROM equipos WHERE id_deporte = ?');
        $stmt->execute([$idDeporte]);
        $indiceEquipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            $fechaCursor = new DateTime($desde);
            $fechaFin = new DateTime($hasta);
        } catch (Exception $e) {
            throw new InvalidArgumentException('Fechas invalidas, usar formato YYYY-MM-DD: ' . $e->getMessage());
        }

        if ($fechaCursor > $fechaFin) {
            throw new InvalidArgumentException('--desde no puede ser posterior a --hasta');
        }

        $this->logger->info(
            ($aplicar ? '=== MODO APLICAR ===' : '=== MODO DIAGNOSTICO (dry-run) ===') .
            " deporte=$deporteSlug liga_espn=$ligaEspn id_liga=$idLigaDestino rango=$desde..$hasta"
        );

        while ($fechaCursor <= $fechaFin) {
            $ymd = $fechaCursor->format('Ymd');
            $url = self::BASE_URL . "/$sportPath/$ligaEspn/scoreboard?dates=$ymd";
            $resp = $this->http->get($url, []);

            if ($resp === null) {
                $this->logger->error("Sin respuesta de ESPN para $ymd, se omite ese dia");
                $fechaCursor->modify('+1 day');
                continue;
            }

            foreach ($resp['events'] ?? [] as $evento) {
                $this->procesarEvento(
                    $evento,
                    $idDeporte,
                    $idLigaDestino,
                    $fuente,
                    $tieneEmpate,
                    $indiceEquipos,
                    $aplicar,
                    $crearNuevos
                );
            }

            $fechaCursor->modify('+1 day');
        }

        if ($aplicar && ($this->creados > 0 || $this->actualizados > 0)) {
            $this->repo->recalcularTablaPosiciones($idLigaDestino);
        }

        $this->logger->info(sprintf(
            '[RESUMEN ESPN] actualizados: %d | creados: %d | omitidos: %d | equipos sin match: %d',
            $this->actualizados,
            $this->creados,
            $this->omitidos,
            $this->sinMatchEquipo
        ));

        return [
            'actualizados' => $this->actualizados,
            'creados'      => $this->creados,
            'omitidos'     => $this->omitidos,
            'sin_match'    => $this->sinMatchEquipo,
        ];
    }

    public function getStats(): array
    {
        return [
            'actualizados' => $this->actualizados,
            'creados'      => $this->creados,
            'omitidos'     => $this->omitidos,
            'sin_match'    => $this->sinMatchEquipo,
        ];
    }

    private function procesarEvento(
        array $evento,
        int $idDeporte,
        int $idLigaDestino,
        string $fuente,
        bool $tieneEmpate,
        array $indiceEquipos,
        bool $aplicar,
        bool $crearNuevos
    ): void {
        $espnEventId = (string)($evento['id'] ?? '');
        $estadoTipo = $evento['status']['type'] ?? [];
        $estadoLocal = $this->mapearEstado($estadoTipo);

        // En juego (solo fútbol): ESPN state 'in'. Se maneja más abajo (minuto en vivo).
        $enVivo = ($estadoTipo['state'] ?? '') === 'in' && $fuente === 'espn-futbol';

        // Si no es un desenlace real, solo procesamos si crearNuevos es true y el evento está programado
        if (!in_array($estadoLocal, ['finalizado', 'pospuesto', 'cancelado'], true)) {
            if (!$enVivo && (!$crearNuevos || $estadoLocal !== 'programado')) {
                return;
            }
        }

        $competidores = $evento['competitions'][0]['competitors'] ?? [];
        $local = null;
        $visita = null;
        foreach ($competidores as $c) {
            if (($c['homeAway'] ?? '') === 'home') {
                $local = $c;
            } elseif (($c['homeAway'] ?? '') === 'away') {
                $visita = $c;
            }
        }

        if ($local === null || $visita === null) {
            $this->logger->skipped("evento ESPN $espnEventId sin par local/visita claro, se omite");
            $this->omitidos++;
            return;
        }

        $idEquipoLocal = $this->resolverEquipo($local, $idDeporte, $fuente, $indiceEquipos, $idLigaDestino);
        $idEquipoVisita = $this->resolverEquipo($visita, $idDeporte, $fuente, $indiceEquipos, $idLigaDestino);

        if ($idEquipoLocal === null || $idEquipoVisita === null) {
            $this->sinMatchEquipo++;
            $this->omitidos++;
            return;
        }

        $fechaEvento = null;
        try {
            $fechaEvento = new DateTime($evento['date'] ?? 'now');
        } catch (Exception) {
            $this->logger->skipped("evento ESPN $espnEventId con fecha invalida, se omite");
            $this->omitidos++;
            return;
        }
        $fechaSql = $fechaEvento->format('Y-m-d H:i:s');

        $marcadorLocal = $estadoLocal === 'finalizado' ? (int)($local['score'] ?? 0) : null;
        $marcadorVisita = $estadoLocal === 'finalizado' ? (int)($visita['score'] ?? 0) : null;

        // Camino rapido: ¿ya vinculamos este evento de ESPN antes?
        $idPartido = $this->repo->resolverExternalId($fuente, 'partido', $espnEventId);

        if ($idPartido === null) {
            $idPartido = $this->buscarPartidoExistente($idLigaDestino, $idEquipoLocal, $idEquipoVisita, $fechaEvento);
        }

        if ($idPartido === 'ambiguo') {
            $this->logger->skipped(
                "evento ESPN $espnEventId ({$local['team']['displayName']} vs {$visita['team']['displayName']}, " .
                "$fechaSql): mas de un partido candidato en la base, revision manual"
            );
            $this->omitidos++;
            return;
        }

        // Partido en juego que ya existe en la base: minuto, marcador y estado en vivo.
        // Solo desde programado/en_curso (nunca pisa un partido ya cerrado o pospuesto por un admin).
        // No suma a $this->actualizados: no cambia la tabla de posiciones.
        if ($enVivo && $idPartido !== null) {
            $minuto = $this->minutoEnVivo($evento['status'] ?? []);
            if ($aplicar) {
                $stmt = $this->pdo->prepare(
                    "UPDATE partidos SET estado = 'en_curso', marcador_local = :ml, marcador_visita = :mv, minuto_actual = :min
                     WHERE id = :id AND estado IN ('programado', 'en_curso')"
                );
                $stmt->execute([
                    ':ml'  => (int)($local['score'] ?? 0),
                    ':mv'  => (int)($visita['score'] ?? 0),
                    ':min' => $minuto,
                    ':id'  => $idPartido,
                ]);
                $this->vincularSiHaceFalta($fuente, 'partido', $idPartido, $espnEventId);
            }
            $this->logger->info(
                "[VIVO] partido #$idPartido " . ($minuto ?? 's/min') . ' ' .
                (int)($local['score'] ?? 0) . '-' . (int)($visita['score'] ?? 0) . " [espn:$espnEventId]"
            );
            return;
        }

        if ($idPartido === null) {
            if (!$crearNuevos) {
                $this->logger->skipped(
                    "evento ESPN $espnEventId ({$local['team']['displayName']} vs {$visita['team']['displayName']}, " .
                    "$fechaSql): sin partido existente que le corresponda (correr con --crear-nuevos si es alta genuina)"
                );
                $this->omitidos++;
                return;
            }

            if ($aplicar) {
                $idPartido = $this->crearPartido(
                    $idDeporte,
                    $idLigaDestino,
                    $idEquipoLocal,
                    $idEquipoVisita,
                    $fechaSql,
                    $marcadorLocal,
                    $marcadorVisita,
                    $estadoLocal,
                    $tieneEmpate,
                    $fuente,
                    $espnEventId
                );
            }
            $this->creados++;
            $this->logger->created(
                "partido nuevo {$local['team']['displayName']} vs {$visita['team']['displayName']} ($fechaSql) [espn:$espnEventId]"
            );
            return;
        }

        // Partido existente: si sigue programado, solo vinculamos si hace falta y no sobreescribimos
        if ($estadoLocal === 'programado') {
            if ($aplicar) {
                $this->vincularSiHaceFalta($fuente, 'partido', $idPartido, $espnEventId);
            }
            return;
        }

        // Partido existente: actualizar marcador/estado directo (bypass
        // deliberado de Repository::upsertPartido, ver docblock de la clase).
        if ($aplicar) {
            $stmt = $this->pdo->prepare(
                'UPDATE partidos SET marcador_local = :ml, marcador_visita = :mv, estado = :estado, minuto_actual = NULL WHERE id = :id'
            );
            $stmt->execute([
                ':ml' => $marcadorLocal,
                ':mv' => $marcadorVisita,
                ':estado' => $estadoLocal,
                ':id' => $idPartido,
            ]);
            $this->vincularSiHaceFalta($fuente, 'partido', $idPartido, $espnEventId);
        }
        $this->actualizados++;
        $this->logger->updated(
            "partido #$idPartido -> $estadoLocal " .
            ($estadoLocal === 'finalizado' ? "($marcadorLocal-$marcadorVisita) " : '') .
            "[espn:$espnEventId]"
        );
    }

    /**
     * Busca un partido ya sembrado (por CUALQUIER otra fuente) que
     * corresponda al mismo par de equipos dentro de la ventana de
     * fecha tolerada. Devuelve: int (id, match unico), null (sin
     * match) o el string 'ambiguo' (mas de un candidato -- nunca se
     * elige a ciegas).
     */
    private function buscarPartidoExistente(int $idLiga, int $idLocal, int $idVisita, DateTime $fecha): int|string|null
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, fecha_partido FROM partidos
             WHERE id_liga = :liga AND id_equipo_local = :local AND id_equipo_visita = :visita
               AND ABS(TIMESTAMPDIFF(HOUR, fecha_partido, :fecha)) <= :ventana'
        );
        $stmt->execute([
            ':liga' => $idLiga,
            ':local' => $idLocal,
            ':visita' => $idVisita,
            ':fecha' => $fecha->format('Y-m-d H:i:s'),
            ':ventana' => self::VENTANA_HORAS,
        ]);
        $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($candidatos) === 0) {
            return null;
        }
        if (count($candidatos) > 1) {
            return 'ambiguo';
        }
        return (int)$candidatos[0]['id'];
    }

    private function crearPartido(
        int $idDeporte,
        int $idLiga,
        int $idLocal,
        int $idVisita,
        string $fechaSql,
        ?int $marcadorLocal,
        ?int $marcadorVisita,
        string $estado,
        bool $tieneEmpate,
        string $fuente,
        string $espnEventId
    ): int {
        $codigo = 'ESP-' . $espnEventId;
        $stmt = $this->pdo->prepare(
            'INSERT INTO partidos
                (id_deporte, id_liga, id_equipo_local, id_equipo_visita, fase, fecha_partido,
                 marcador_local, marcador_visita, estado, cuota_local, cuota_empate, cuota_visita, codigo)
             VALUES
                (:deporte, :liga, :local, :visita, :fase, :fecha, :ml, :mv, :estado, 2.00, :cuota_empate, 2.50, :codigo)'
        );
        $stmt->execute([
            ':deporte' => $idDeporte,
            ':liga' => $idLiga,
            ':local' => $idLocal,
            ':visita' => $idVisita,
            ':fase' => 'REGULAR_SEASON',
            ':fecha' => $fechaSql,
            ':ml' => $marcadorLocal,
            ':mv' => $marcadorVisita,
            ':estado' => $estado,
            ':cuota_empate' => $tieneEmpate ? 3.00 : null,
            ':codigo' => $codigo,
        ]);
        $idNuevo = (int)$this->pdo->lastInsertId();
        $this->vincularSiHaceFalta($fuente, 'partido', $idNuevo, $espnEventId);
        return $idNuevo;
    }

    /**
     * Resuelve un competitor de ESPN (bloque 'home' o 'away' del
     * scoreboard) al id interno de `equipos`. Primero intenta el
     * vinculo guardado; si no existe, matchea por nombre normalizado
     * contra $indiceEquipos y, si encuentra, guarda el vinculo para
     * la proxima corrida. Nunca crea un equipo nuevo solo.
     */
    private function resolverEquipo(array $competitor, int $idDeporte, string $fuente, array $indiceEquipos, ?int $idLigaDestino = null): ?int
    {
        $espnId = (string)($competitor['team']['id'] ?? '');
        $nombre = $competitor['team']['displayName'] ?? $competitor['team']['name'] ?? '';

        if ($espnId !== '') {
            $idVinculado = $this->repo->resolverExternalId($fuente, 'equipo', $espnId);
            if ($idVinculado !== null) {
                return $idVinculado;
            }
        }

        $palabrasEspn = $this->normalizarPalabras($nombre);
        $mejor = null;
        $mejorClave = [0.0, 0, 0.0];
        $mejorScore = 0.0;
        foreach ($indiceEquipos as $eq) {
            $palabrasLocal = $this->normalizarPalabras($eq['nombre']);
            if (empty($palabrasLocal) || empty($palabrasEspn)) {
                continue;
            }
            $comunes = array_intersect($palabrasEspn, $palabrasLocal);
            $base = min(count($palabrasEspn), count($palabrasLocal));
            if ($base === 0) {
                continue;
            }
            $score = count($comunes) / $base;
            // FIX (11/10): con solo $score, "Paris FC" empataba en 1.0 con
            // "Paris Saint Germain" (comparten "paris") y ganaba el primero de
            // la lista, vinculando el equipo de ESPN con el equivocado. Ahora el
            // desempate es: (1) que el equipo pertenezca a la liga que se esta
            // sincronizando (evita que "Santos" de Liga MX caiga en el Santos de
            // Brasil), (2) que coincidan mas palabras sobre el total del nombre
            // mas largo (Paris FC vs Paris Saint Germain).
            $scoreTotal = count($comunes) / max(count($palabrasEspn), count($palabrasLocal));
            $enLiga = ($idLigaDestino !== null && (int)($eq['id_liga'] ?? 0) === $idLigaDestino) ? 1 : 0;
            $clave = [$score, $enLiga, $scoreTotal];
            if (($clave <=> $mejorClave) > 0) {
                $mejorClave = $clave;
                $mejorScore = $score;
                $mejor = $eq;
            }
        }

        if ($mejor === null || $mejorScore < 0.6) {
            $this->logger->skipped("equipo ESPN '$nombre' (id $espnId) sin match >=60% en equipos.id_deporte=$idDeporte");
            return null;
        }

        if ($espnId !== '') {
            $this->vincularSiHaceFalta($fuente, 'equipo', (int)$mejor['id'], $espnId);
        }
        return (int)$mejor['id'];
    }

    /**
     * INSERT directo a external_ids, protegido por la UNIQUE KEY
     * (fuente, entidad, external_id) de la tabla -- si dos corridas
     * pisan la carrera, la segunda simplemente no hace nada.
     */
    private function vincularSiHaceFalta(string $fuente, string $entidad, int $entidadId, string $externalId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO external_ids (entidad, entidad_id, fuente, external_id) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$entidad, $entidadId, $fuente, $externalId]);
        } catch (PDOException $e) {
            // Codigo 23000 = violacion de UNIQUE KEY -> alguien ya lo
            // vinculo (corrida concurrente o repetida), no es un error real.
            if ($e->getCode() !== '23000') {
                $this->logger->error("No se pudo vincular external_ids ($fuente/$entidad/$externalId): " . $e->getMessage());
            }
        }
    }

    private function deporteTieneEmpate(int $idDeporte): bool
    {
        $stmt = $this->pdo->prepare('SELECT tiene_empate FROM deportes WHERE id = ?');
        $stmt->execute([$idDeporte]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Minuto en vivo para mostrar ("67'", "90+4'", "Descanso", "Penales") desde el
     * `status` del scoreboard de ESPN (displayClock + periodo). NULL si no hay dato fiable.
     */
    private function minutoEnVivo(array $status): ?string
    {
        $nombre = strtoupper((string)($status['type']['name'] ?? ''));
        $periodo = (int)($status['period'] ?? 0);

        if (str_contains($nombre, 'HALFTIME')) {
            return 'Descanso';
        }
        if (str_contains($nombre, 'SHOOTOUT') || $periodo >= 5) {
            return 'Penales';
        }

        // displayClock llega como "67'" o "90'+4'"; se normaliza a "67'" / "90+4'"
        $reloj = str_replace(["'", ' '], '', (string)($status['displayClock'] ?? ''));
        if (preg_match('/^\d{1,3}(\+\d{1,2})?$/', $reloj) !== 1 || $reloj === '0') {
            return null;
        }
        return $reloj . "'";
    }

    private function mapearEstado(array $statusType): string
    {
        if (($statusType['completed'] ?? false) === true) {
            return 'finalizado';
        }
        $state = $statusType['state'] ?? '';
        $nombre = strtoupper($statusType['name'] ?? '');
        return match (true) {
            str_contains($nombre, 'POSTPON') => 'pospuesto',
            str_contains($nombre, 'CANCEL')  => 'cancelado',
            $state === 'post'                => 'finalizado', // completed=false pero state=post: raro, tratar como cerrado
            default                          => 'programado',
        };
    }

    private function sacarAcentos(string $texto): string
    {
        $mapa = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
            'ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n','ø'=>'o','Ø'=>'o',
        ];
        return strtr($texto, $mapa);
    }

    private function normalizarPalabras(string $nombre): array
    {
        $n = strtolower($this->sacarAcentos($nombre));
        $n = str_replace(['.', ','], '', $n);
        $ruido = ['fc', 'cf', 'afc', 'sad', 'club', 'clube', 'de', 'da', 'do', 'dos', 'the', 'red', 'bull'];
        $palabras = preg_split('/[\s\-]+/', $n);
        $palabras = array_filter($palabras, fn($p) => $p !== '' && !in_array($p, $ruido, true));
        return array_values($palabras);
    }
}