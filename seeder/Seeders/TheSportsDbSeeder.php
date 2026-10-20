<?php

/**
 * TheSportsDB es multideporte de fabrica y no exige key para los
 * endpoints basicos (usa la key publica de pruebas '3'). Sirve
 * como fuente de respaldo/complemento cuando api-sports o
 * football-data no cubren algo (p.ej. ligas menores o historicas).
 */
class TheSportsDbSeeder
{
    private const FUENTE = 'thesportsdb';
    private const BASE_URL = 'https://www.thesportsdb.com/api/v1/json/3';

    public function __construct(
        private Repository $repo,
        private HttpClient $http,
        private Logger $logger
    ) {
    }

    public function run(string $nombreLiga, string $sportSlug): void
    {
        $idDeporte = $this->repo->idDeportePorSlug($sportSlug);

        $ligaResp = $this->http->get(self::BASE_URL . '/search_all_leagues.php?s=' . urlencode($this->deporteApiParaBusqueda($sportSlug)));
        $ligaApi = null;
        foreach ($ligaResp['countries'] ?? $ligaResp['leagues'] ?? [] as $l) {
            if (($l['strLeague'] ?? '') === $nombreLiga) {
                $ligaApi = $l;
                break;
            }
        }
        if ($ligaApi === null) {
            $this->logger->error("Liga '$nombreLiga' no encontrada en TheSportsDB");
            return;
        }

        $idLiga = $this->repo->upsertLiga(
            self::FUENTE,
            $idDeporte,
            $ligaApi['strLeague'],
            null,
            null,
            'liga',
            $ligaApi['strBadge'] ?? $ligaApi['strLogo'] ?? null,
            (string)$ligaApi['idLeague']
        );
        if ($idLiga === null) {
            return;
        }

        // Equipos de la liga
        $equiposResp = $this->http->get(self::BASE_URL . '/search_all_teams.php?l=' . urlencode($nombreLiga));
        $mapaEquipos = [];
        foreach ($equiposResp['teams'] ?? [] as $eq) {
            $idEquipo = $this->repo->upsertEquipo(
                self::FUENTE,
                $idDeporte,
                $idLiga,
                null,
                null,
                $eq['strTeam'],
                $eq['strTeamShort'] ?? null,
                null,
                $eq['strBadge'] ?? null,
                null,
                null,
                (string)$eq['idTeam']
            );
            if ($idEquipo !== null) {
                $mapaEquipos[$eq['idTeam']] = $idEquipo;

                $plantilla = $this->http->get(self::BASE_URL . "/lookup_all_players.php?id={$eq['idTeam']}");
                foreach ($plantilla['player'] ?? [] as $j) {
                    $this->repo->upsertJugador(
                        self::FUENTE,
                        $idEquipo,
                        $j['strPlayer'],
                        $j['strPosition'] ?? 'N/D',
                        is_numeric($j['strNumber'] ?? null) ? (int)$j['strNumber'] : null,
                        $this->calcularEdad($j['dateBorn'] ?? null),
                        $j['strNationality'] ?? null,
                        $j['strThumb'] ?? null,
                        0,
                        'Goles',
                        (string)$j['idPlayer']
                    );
                }
            }
        }

        // Ultimos y proximos eventos por equipo (TheSportsDB no da
        // "todos los partidos de la liga" gratis por temporada completa,
        // se recorre por equipo)
        foreach ($mapaEquipos as $idExterno => $idEquipoInterno) {
            foreach (['eventslast.php?id=', 'eventsnext.php?id='] as $endpoint) {
                $eventos = $this->http->get(self::BASE_URL . "/$endpoint$idExterno");
                foreach (($eventos['results'] ?? $eventos['events'] ?? []) as $ev) {
                    $idLocal = $mapaEquipos[$ev['idHomeTeam']] ?? null;
                    $idVisita = $mapaEquipos[$ev['idAwayTeam']] ?? null;
                    if ($idLocal === null || $idVisita === null) {
                        continue;
                    }
                    $this->repo->upsertPartido(
                        self::FUENTE,
                        $idDeporte,
                        $idLiga,
                        $idLocal,
                        $idVisita,
                        null,
                        $ev['strSeason'] ?? 'Temporada Regular',
                        (int)($ev['intRound'] ?? 0) ?: null,
                        null,
                        ($ev['dateEvent'] ?? date('Y-m-d')) . ' ' . ($ev['strTime'] ?? '00:00:00'),
                        is_numeric($ev['intHomeScore'] ?? null) ? (int)$ev['intHomeScore'] : null,
                        is_numeric($ev['intAwayScore'] ?? null) ? (int)$ev['intAwayScore'] : null,
                        !empty($ev['intHomeScore']) ? 'finalizado' : 'programado',
                        (string)$ev['idEvent']
                    );
                }
            }
        }

        $this->repo->recalcularTablaPosiciones($idLiga);
    }

    private function deporteApiParaBusqueda(string $slug): string
    {
        return match ($slug) {
            'futbol'            => 'Soccer',
            'baloncesto'        => 'Basketball',
            'beisbol'           => 'Baseball',
            'futbol-americano'  => 'American Football',
            default             => 'Soccer',
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
