<?php

class FootballDataSeeder
{
    private const BASE_URL = 'https://api.football-data.org/v4';
    private const FUENTE = 'football-data';

    public function __construct(
        private Repository $repo,
        private HttpClient $http,
        private Logger $logger,
        private string $apiKey
    ) {
    }

    public function run(string $codigoLiga, ?string $temporada = null): void
    {
        $idDeporte = $this->repo->idDeportePorSlug('futbol');
        $headers = ["X-Auth-Token: {$this->apiKey}"];

        // 1) Liga/competicion
        $comp = $this->http->get(self::BASE_URL . "/competitions/$codigoLiga", $headers);
        if ($comp === null) {
            $this->logger->error("No se pudo obtener la competicion $codigoLiga");
            return;
        }

        $idPais = null;
        if (!empty($comp['area']['name'])) {
            $idPais = $this->repo->upsertPais(
                self::FUENTE,
                $comp['area']['name'],
                $comp['area']['code'] ?? null,
                $comp['area']['flag'] ?? null,
                (string)($comp['area']['id'] ?? $comp['area']['name'])
            );
        }

        $idLiga = $this->repo->upsertLiga(
            self::FUENTE,
            $idDeporte,
            $comp['name'],
            $idPais,
            $temporada ?? ($comp['currentSeason']['startDate'] ?? null),
            'liga',
            $comp['emblem'] ?? null,
            (string)$comp['id']
        );

        if ($idLiga === null) {
            return;
        }

        // 2) Equipos de la liga
        $equipos = $this->http->get(self::BASE_URL . "/competitions/$codigoLiga/teams", $headers);
        $mapaEquipos = []; // id externo -> id interno
        foreach ($equipos['teams'] ?? [] as $eq) {
            $idEquipo = $this->repo->upsertEquipo(
                self::FUENTE,
                $idDeporte,
                $idLiga,
                $idPais,
                null,
                $eq['name'],
                $eq['tla'] ?? null,
                null,
                $eq['crest'] ?? null,
                $eq['coach']['name'] ?? null,
                null,
                (string)$eq['id']
            );
            if ($idEquipo !== null) {
                $mapaEquipos[$eq['id']] = $idEquipo;

                // 3) Plantilla del equipo
                foreach ($eq['squad'] ?? [] as $jugador) {
                    $this->repo->upsertJugador(
                        self::FUENTE,
                        $idEquipo,
                        $jugador['name'],
                        $jugador['position'] ?? 'N/D',
                        $jugador['shirtNumber'] ?? null,
                        $this->calcularEdad($jugador['dateOfBirth'] ?? null),
                        $jugador['nationality'] ?? null,
                        null,
                        0,
                        'Goles',
                        (string)$jugador['id']
                    );
                }
            }
        }

        // 4) Partidos de la liga
        $partidos = $this->http->get(self::BASE_URL . "/competitions/$codigoLiga/matches", $headers);
        foreach ($partidos['matches'] ?? [] as $p) {
            $idLocal = $mapaEquipos[$p['homeTeam']['id']] ?? null;
            $idVisita = $mapaEquipos[$p['awayTeam']['id']] ?? null;
            if ($idLocal === null || $idVisita === null) {
                $this->logger->skipped("partido {$p['id']} con equipo no resuelto, se omite");
                continue;
            }

            $this->repo->upsertPartido(
                self::FUENTE,
                $idDeporte,
                $idLiga,
                $idLocal,
                $idVisita,
                null,
                $p['stage'] ?? 'Fase de Grupos',
                $p['matchday'] ?? null,
                $p['group'] ?? null,
                date('Y-m-d H:i:s', strtotime($p['utcDate'])),
                $p['score']['fullTime']['home'] ?? null,
                $p['score']['fullTime']['away'] ?? null,
                $this->mapearEstado($p['status']),
                (string)$p['id']
            );
        }

        $this->repo->recalcularTablaPosiciones($idLiga, $temporada);
    }

    /**
     * Trae SOLO partidos (sin recrear equipos ni plantillas), pensado
     * para usarse despues de correr el puente de equipos
     * (puente_equipos_aplicar.php), que ya dejo vinculados en
     * external_ids los equipos de football-data que coinciden con
     * equipos ya sembrados de api-sports.
     *
     * Por cada partido: primero busca si el equipo YA esta vinculado
     * (resolverExternalId). Si esta vinculado, reutiliza ese id interno
     * -- nunca lo vuelve a crear. Si NO esta vinculado (equipo genuinamente
     * nuevo, ej. un recien ascendido que no tiene version historica), recien
     * ahi lo crea con upsertEquipo() como haria el metodo run() normal.
     *
     * @param string $codigoLiga   codigo de la competicion en football-data (ej. 'BSA')
     * @param int    $idLigaDestino id interno (ligas_torneos.id) donde guardar los partidos
     */
    public function runSoloPartidos(string $codigoLiga, int $idLigaDestino): void
    {
        $idDeporte = $this->repo->idDeportePorSlug('futbol');
        $headers = ["X-Auth-Token: {$this->apiKey}"];

        $partidosResp = $this->http->get(
            self::BASE_URL . "/competitions/$codigoLiga/matches",
            $headers
        );

        if ($partidosResp === null) {
            $this->logger->error("No se pudo obtener partidos de football-data para $codigoLiga");
            return;
        }

        if (!empty($partidosResp['errors'])) {
            $this->logger->error(
                "football-data devolvio errores para $codigoLiga: " . json_encode($partidosResp['errors'])
            );
            return;
        }

        $creados = 0;
        $reutilizados = 0;
        $nuevos = 0;

        foreach ($partidosResp['matches'] ?? [] as $p) {
            $idLocal = $this->resolverOCrearEquipo($p['homeTeam'], $idDeporte, $idLigaDestino, $nuevos, $reutilizados);
            $idVisita = $this->resolverOCrearEquipo($p['awayTeam'], $idDeporte, $idLigaDestino, $nuevos, $reutilizados);

            if ($idLocal === null || $idVisita === null) {
                $this->logger->skipped("partido {$p['id']} con equipo no resuelto, se omite");
                continue;
            }

            $this->repo->upsertPartido(
                self::FUENTE,
                $idDeporte,
                $idLigaDestino,
                $idLocal,
                $idVisita,
                null,
                $p['stage'] ?? 'Fase de Grupos',
                $p['matchday'] ?? null,
                $p['group'] ?? null,
                date('Y-m-d H:i:s', strtotime($p['utcDate'])),
                $p['score']['fullTime']['home'] ?? null,
                $p['score']['fullTime']['away'] ?? null,
                $this->mapearEstado($p['status']),
                (string)$p['id']
            );
            $creados++;
        }

        $this->logger->info(
            "Liga $idLigaDestino ($codigoLiga): $creados partidos procesados. " .
            "Equipos reutilizados (via puente): $reutilizados. Equipos nuevos creados: $nuevos."
        );

        $this->repo->recalcularTablaPosiciones($idLigaDestino);
    }

    /**
     * Busca si el equipo de football-data ya esta vinculado a un equipo
     * existente (via el puente). Si no, lo crea como equipo nuevo bajo
     * la liga de destino. Los contadores $nuevos/$reutilizados se pasan
     * por referencia solo para el resumen final del log.
     */
    private function resolverOCrearEquipo(array $equipoApi, int $idDeporte, int $idLigaDestino, int &$nuevos, int &$reutilizados): ?int
    {
        $idExterno = (string)($equipoApi['id'] ?? '');
        if ($idExterno === '') {
            return null;
        }

        $idExistente = $this->repo->resolverExternalId(self::FUENTE, 'equipo', $idExterno);
        if ($idExistente !== null) {
            $reutilizados++;
            return $idExistente;
        }

        // No vinculado -> equipo genuinamente nuevo, se crea
        $idNuevo = $this->repo->upsertEquipo(
            self::FUENTE,
            $idDeporte,
            $idLigaDestino,
            null,
            null,
            $equipoApi['name'] ?? "Equipo $idExterno",
            $equipoApi['tla'] ?? null,
            null,
            $equipoApi['crest'] ?? null,
            null,
            null,
            $idExterno
        );
        if ($idNuevo !== null) {
            $nuevos++;
        }
        return $idNuevo;
    }

    private function mapearEstado(string $statusApi): string
    {
        return match ($statusApi) {
            'FINISHED'                => 'finalizado',
            'IN_PLAY', 'PAUSED'       => 'en_curso',
            'POSTPONED'               => 'pospuesto',
            'CANCELLED'               => 'cancelado',
            'SUSPENDED'               => 'suspendido',
            default                   => 'programado',
        };
    }

    private function calcularEdad(?string $fechaNacimiento): ?int
    {
        if (!$fechaNacimiento) {
            return null;
        }
        try {
            return (new DateTime($fechaNacimiento))->diff(new DateTime())->y;
        } catch (Exception) {
            return null;
        }
    }
}