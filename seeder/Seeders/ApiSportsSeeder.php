<?php

/**
 * La familia api-sports.io (basketball, baseball, american-football,
 * y tambien football) comparte casi toda la misma forma de
 * respuesta y el mismo header de autenticacion. Este UN seeder
 * parametrizado por dominio cubre los deportes que faltan
 * en vez de triplicar/cuadruplicar codigo.
 *
 * Dominios validos (parametro $dominioApi):
 *   'v1.basketball.api-sports.io'
 *   'v1.baseball.api-sports.io'
 *   'v1.american-football.api-sports.io'
 *   'v3.football.api-sports.io'
 *
 * NOTA IMPORTANTE sobre futbol: a diferencia de basket/beisbol/NFL,
 * el endpoint para traer la PLANTILLA COMPLETA de un equipo de futbol
 * es /players/squads?team=X (sin filtro de temporada, sin paginacion).
 * El endpoint /players?team=X&season=Y es para estadisticas de
 * jugador por temporada, viene paginado y NO es la plantilla completa.
 * Por eso este seeder distingue el caso 'futbol' al pedir jugadores.
 *
 * NOTA sobre temporadas: en el plan gratis de api-sports.io, el
 * endpoint /teams?league=X&season=Y solo acepta ciertas temporadas
 * (confirmado: 2022-2024 para futbol). Pedir una temporada fuera de
 * ese rango devuelve error "Free plans do not have access to this
 * season" con results:0, no una excepcion.
 *
 * FIX (beisbol sin jugadores): tanto traerPlantillaFutbol() como
 * traerPlantillaGenerica() nunca revisaban $resp['errors'] ni si
 * $resp era null. Si el plan gratis restringe /players para un
 * deporte (confirmado que pasa con beisbol), la respuesta se convertia
 * silenciosamente en "cero jugadores" via el operador ?? [], exactamente
 * el mismo bug que ya se habia arreglado antes para /fixtures. Ahora
 * ambos metodos loguean el motivo real cuando no traen jugadores.
 *
 * FIX (colision de nombres de liga entre paises, ej. "Serie A" Italia
 * vs "Serie A" Brasil): antes upsertLiga() recibia SIEMPRE null como
 * id_pais, sin importar que /leagues devuelva el pais en un objeto
 * 'country' separado (name, code, flag). Como la restriccion unica de
 * ligas_torneos es (id_deporte, id_pais, nombre, temporada), dos ligas
 * de paises distintos con el mismo nombre (api-sports llama "Serie A"
 * tanto a la de Italia como al Campeonato Brasileiro Serie A) chocaban
 * entre si al compartir id_pais=NULL. Ahora se extrae el pais de la
 * respuesta y se resuelve/crea con Repository::upsertPais() antes de
 * upsertLiga(), usando el 'code' de api-sports (ej. "BR") como
 * external_id -- si algun pais no trae code (pasa con torneos
 * multinacionales como Champions League, donde country.name es
 * "World"), se usa el nombre como fallback de external_id.
 */
class ApiSportsSeeder
{
    /**
     * FIX (colision de external_id entre deportes): antes esta clase usaba
     * un unico 'api-sports' generico como fuente para futbol, basquet,
     * beisbol y NFL a la vez. Cada dominio de api-sports.io tiene su PROPIO
     * catalogo de ids (el equipo id=34 de futbol no tiene nada que ver con
     * el id=34 de beisbol), asi que compartir el mismo 'fuente' en
     * external_ids hacia que un external_id se pisara con el de otro
     * deporte (paso con Newcastle/Man Utd/Bournemouth/Fulham vs Rays/
     * Cardinals/Rangers/Blue Jays). Ahora cada deporte tiene su propio
     * fuente, namespaced, para que eso sea imposible.
     *
     * Los datos ya sembrados bajo el 'api-sports' generico se migran una
     * sola vez con migrar_fuentes_api_sports.php -- correrlo ANTES de
     * volver a usar este seeder, si no la proxima corrida va a duplicar
     * en vez de actualizar.
     */
    private function fuente(): string
    {
        return match ($this->sportSlug) {
            'futbol'           => 'api-sports-futbol',
            'baloncesto'       => 'api-sports-basketball',
            'beisbol'          => 'api-sports-baseball',
            'futbol-americano' => 'api-sports-nfl',
            default            => 'api-sports-' . $this->sportSlug,
        };
    }

    public function __construct(
        private Repository $repo,
        private HttpClient $http,
        private Logger $logger,
        private string $apiKey,
        private string $dominioApi,
        private string $sportSlug // 'baloncesto' | 'beisbol' | 'futbol-americano' | 'futbol'
    ) {
    }

    private function baseUrl(): string
    {
        return "https://{$this->dominioApi}";
    }

    private function headers(): array
    {
        return [
            "x-apisports-key: {$this->apiKey}",
        ];
    }

    public function run(int $idLigaApi, string $temporada): void
    {
        $idDeporte = $this->repo->idDeportePorSlug($this->sportSlug);

        // 1) Liga
        $ligaResp = $this->http->get($this->baseUrl() . "/leagues?id=$idLigaApi", $this->headers());
        $ligaInfo = $ligaResp['response'][0] ?? null;
        if ($ligaInfo === null) {
            $this->logger->error("Liga $idLigaApi no encontrada en {$this->dominioApi}");
            return;
        }

        $nombreLiga = $ligaInfo['name'] ?? $ligaInfo['league']['name'] ?? "Liga $idLigaApi";
        $logoLiga = $ligaInfo['logo'] ?? $ligaInfo['league']['logo'] ?? null;

        // FIX: extraer el pais de la respuesta (objeto 'country' separado
        // de 'league') y resolverlo/crearlo antes de la liga, para poder
        // distinguir ligas homonimas de paises distintos (ver nota arriba
        // de la clase).
        $idPais = null;
        $paisInfo = $ligaInfo['country'] ?? null;
        if ($paisInfo !== null && !empty($paisInfo['name'])) {
            $externalIdPais = $paisInfo['code'] ?? $paisInfo['name'];
            $idPais = $this->repo->upsertPais(
                $this->fuente(),
                $paisInfo['name'],
                $paisInfo['code'] ?? null,
                $paisInfo['flag'] ?? null,
                $externalIdPais
            );
        }

        $idLiga = $this->repo->upsertLiga(
            $this->fuente(),
            $idDeporte,
            $nombreLiga,
            $idPais,
            $temporada,
            'liga',
            $logoLiga,
            (string)$idLigaApi
        );
        if ($idLiga === null) {
            return;
        }

        // 2) Equipos
        $equiposResp = $this->http->get(
            $this->baseUrl() . "/teams?league=$idLigaApi&season=$temporada",
            $this->headers()
        );

        if (isset($equiposResp['errors']['plan'])) {
            $this->logger->error(
                "Liga $idLigaApi / temporada $temporada: " . $equiposResp['errors']['plan']
            );
            return;
        }

        $totalJugadoresLiga = 0;

        $mapaEquipos = [];
        foreach ($equiposResp['response'] ?? [] as $eq) {
            $idExterno = (string)($eq['id'] ?? $eq['team']['id']);
            $nombre = $eq['name'] ?? $eq['team']['name'];
            $idEquipo = $this->repo->upsertEquipo(
                $this->fuente(),
                $idDeporte,
                $idLiga,
                // FIX: antes se pasaba `null` a mano aca, ignorando el
                // $idPais que ya se resuelve arriba (linea ~121) para la
                // liga. Consecuencia real: cualquier equipo NUEVO sembrado
                // desde cero con este seeder (ej. al poblar Saudi Pro
                // League o J1 League) nacia con id_pais = NULL aunque el
                // pais de su liga ya estuviera resuelto -- quedaba
                // dependiendo de que otro script (poblar_pais_estadios_domestico.php
                // o similar) lo corrigiera despues a mano. Con este fix,
                // el equipo ya nace con el pais correcto en el INSERT
                // inicial, sin depender de un paso de reparacion posterior.
                $idPais,
                null,
                $nombre,
                null,
                null,
                $eq['logo'] ?? $eq['team']['logo'] ?? null,
                null,
                null,
                $idExterno
            );
            if ($idEquipo === null) {
                continue;
            }

            $mapaEquipos[$idExterno] = $idEquipo;

            // 3) Plantilla — el endpoint cambia segun el deporte
            $jugadores = $this->sportSlug === 'futbol'
                ? $this->traerPlantillaFutbol($idExterno)
                : $this->traerPlantillaGenerica($idExterno, $temporada);

            $totalJugadoresLiga += count($jugadores);

            foreach ($jugadores as $datos) {
                $this->repo->upsertJugador(
                    $this->fuente(),
                    $idEquipo,
                    $datos['nombre'],
                    $datos['posicion'],
                    $datos['dorsal'],
                    $datos['edad'],
                    $datos['nacionalidad'],
                    $datos['foto'],
                    0,
                    $this->etiquetaEstadistica(),
                    $datos['external_id']
                );
            }
        }

        if ($totalJugadoresLiga === 0 && count($mapaEquipos) > 0) {
            $this->logger->error(
                "Liga $idLigaApi / temporada $temporada: se crearon " . count($mapaEquipos) .
                " equipos pero 0 jugadores en total. Revisa las lineas de arriba " .
                "(por equipo) para ver el motivo exacto — probablemente el plan " .
                "gratis no tiene acceso al endpoint de jugadores para este deporte."
            );
        }

        // 4) Partidos/juegos. En v3.football el endpoint se llama 'fixtures';
        // en basket/beisbol/NFL se llama 'games'. Antes esto hacia SIEMPRE
        // una llamada de mas a /games para futbol (endpoint inexistente ahi,
        // gasta cuota de API para nada) y encima nunca revisaba si la
        // respuesta traia un error, asi que un rate-limit o restriccion de
        // plan se traducia en "cero partidos" sin dejar ningun rastro.
        $endpointJuegos = $this->sportSlug === 'futbol' ? 'fixtures' : 'games';
        $juegosResp = $this->http->get(
            $this->baseUrl() . "/$endpointJuegos?league=$idLigaApi&season=$temporada",
            $this->headers()
        );

        if ($juegosResp === null) {
            $this->logger->error(
                "Liga $idLigaApi / temporada $temporada: no se pudo obtener $endpointJuegos " .
                "(la peticion HTTP fallo tras los reintentos, revisa el log de arriba para el motivo)"
            );
            $this->repo->recalcularTablaPosiciones($idLiga, $temporada);
            return;
        }

        if (!empty($juegosResp['errors'])) {
            $this->logger->error(
                "Liga $idLigaApi / temporada $temporada: la API devolvio errores en $endpointJuegos: " .
                json_encode($juegosResp['errors'])
            );
            $this->repo->recalcularTablaPosiciones($idLiga, $temporada);
            return;
        }

        $totalJuegos = count($juegosResp['response'] ?? []);
        $this->logger->info("Liga $idLigaApi / temporada $temporada: $totalJuegos partidos recibidos de $endpointJuegos");
        if ($totalJuegos === 0) {
            $this->logger->info(
                "Respuesta cruda de $endpointJuegos (primeros 300 chars): " .
                substr(json_encode($juegosResp), 0, 300)
            );
        }

        foreach ($juegosResp['response'] ?? [] as $g) {
            $idLocal = $mapaEquipos[(string)($g['teams']['home']['id'] ?? '')] ?? null;
            $idVisita = $mapaEquipos[(string)($g['teams']['away']['id'] ?? '')] ?? null;
            if ($idLocal === null || $idVisita === null) {
                continue;
            }

            $fecha = $g['date']
                ?? $g['game']['date']['start']
                ?? $g['fixture']['date']
                ?? date('c');
            // FIX (partidos de NFL pisandose entre si): el endpoint de
            // american-football anida el id del juego bajo 'game', igual
            // que ya se contempla mas abajo para la fecha
            // ($g['game']['date']['start']). Sin este fallback, todos los
            // partidos de NFL calculaban external_id='' y el Repository
            // los resolvia como "el mismo partido" (misma fuente+entidad+
            // external_id), asi que se fueron pisando uno a otro en una
            // sola fila en vez de crear ~272 filas distintas.
            $externoId = (string)($g['id'] ?? $g['game']['id'] ?? $g['fixture']['id'] ?? '');
            // FIX (partidos de NFL nunca quedaban 'finalizado'): mismo
            // patron que $externoId y $fecha -- el endpoint de
            // american-football anida 'status' bajo 'game', no suelto.
            // Sin este fallback, $estadoApi quedaba siempre '' y
            // mapearEstado('') caia al default 'programado' aunque el
            // partido ya tuviera marcador final, dejando afuera a todos
            // los partidos de recalcularTablaPosiciones() (que exige
            // estado = "finalizado").
            $estadoApi = $g['status']['short']
                ?? $g['game']['status']['short']
                ?? $g['game']['status']['long']
                ?? $g['status']['long']
                ?? $g['fixture']['status']['short']
                ?? '';
            $marcadorLocal = $g['scores']['home']['total']
                ?? $g['teams']['home']['score']
                ?? $g['goals']['home']
                ?? null;
            $marcadorVisita = $g['scores']['away']['total']
                ?? $g['teams']['away']['score']
                ?? $g['goals']['away']
                ?? null;

            $this->repo->upsertPartido(
                $this->fuente(),
                $idDeporte,
                $idLiga,
                $idLocal,
                $idVisita,
                null,
                $g['stage'] ?? $g['league']['round'] ?? 'Temporada Regular',
                null,
                null,
                date('Y-m-d H:i:s', strtotime($fecha)),
                $marcadorLocal,
                $marcadorVisita,
                $this->mapearEstado($estadoApi),
                $externoId
            );
        }

        $this->repo->recalcularTablaPosiciones($idLiga, $temporada);
    }

    /**
     * Plantilla completa de futbol: /players/squads?team=X
     * Sin filtro de temporada, sin paginacion, trae el roster actual
     * completo (nombre, dorsal, edad, posicion, foto).
     */
    private function traerPlantillaFutbol(string $idExterno): array
    {
        $resp = $this->http->get(
            $this->baseUrl() . "/players/squads?team=$idExterno",
            $this->headers()
        );

        if ($resp === null) {
            $this->logger->error(
                "Equipo $idExterno: no se pudo obtener plantilla (players/squads) " .
                "(la peticion HTTP fallo tras los reintentos, revisa el log de arriba para el motivo)"
            );
            return [];
        }

        if (!empty($resp['errors'])) {
            $this->logger->error(
                "Equipo $idExterno: la API devolvio errores en players/squads: " .
                json_encode($resp['errors'])
            );
            return [];
        }

        $plantilla = $resp['response'][0]['players'] ?? [];

        if (empty($plantilla)) {
            $this->logger->info(
                "Equipo $idExterno: 0 jugadores en players/squads. Respuesta cruda (primeros 300 chars): " .
                substr(json_encode($resp), 0, 300)
            );
        }

        $jugadores = [];
        foreach ($plantilla as $j) {
            $jugadores[] = [
                'nombre'       => $j['name'] ?? '',
                'posicion'     => $j['position'] ?? 'N/D',
                'dorsal'       => is_numeric($j['number'] ?? null) ? (int)$j['number'] : null,
                'edad'         => $j['age'] ?? null,
                'nacionalidad' => null,
                'foto'         => $j['photo'] ?? null,
                'external_id'  => (string)($j['id'] ?? ''),
            ];
        }
        return $jugadores;
    }

    /**
     * Plantilla para basket/beisbol/NFL: /players?team=X&season=Y
     * (estos dominios no tienen un endpoint /squads equivalente,
     * asi que se usa el listado de jugadores por temporada).
     */
    private function traerPlantillaGenerica(string $idExterno, string $temporada): array
    {
        $resp = $this->http->get(
            $this->baseUrl() . "/players?team=$idExterno&season=$temporada",
            $this->headers()
        );

        if ($resp === null) {
            $this->logger->error(
                "Equipo $idExterno / temporada $temporada: no se pudo obtener /players " .
                "(la peticion HTTP fallo tras los reintentos, revisa el log de arriba para el motivo)"
            );
            return [];
        }

        if (!empty($resp['errors'])) {
            $this->logger->error(
                "Equipo $idExterno / temporada $temporada: la API devolvio errores en /players: " .
                json_encode($resp['errors'])
            );
            return [];
        }

        if (empty($resp['response'])) {
            $this->logger->info(
                "Equipo $idExterno / temporada $temporada: 0 jugadores en /players. " .
                "Respuesta cruda (primeros 300 chars): " . substr(json_encode($resp), 0, 300)
            );
        }

        $jugadores = [];
        foreach ($resp['response'] ?? [] as $j) {
            $datos = $j['player'] ?? $j;
            $jugadores[] = [
                'nombre'       => trim(($datos['firstname'] ?? '') . ' ' . ($datos['lastname'] ?? $datos['name'] ?? '')),
                'posicion'     => $datos['position'] ?? 'N/D',
                'dorsal'       => $datos['number'] ?? null,
                'edad'         => $datos['age'] ?? null,
                'nacionalidad' => $datos['nationality'] ?? null,
                'foto'         => $datos['photo'] ?? null,
                'external_id'  => (string)($datos['id'] ?? ''),
            ];
        }
        return $jugadores;
    }

    private function etiquetaEstadistica(): string
    {
        return match ($this->sportSlug) {
            'baloncesto'       => 'Puntos',
            'beisbol'          => 'Hits',
            'futbol-americano' => 'Touchdowns',
            'futbol'           => 'Goles',
            default            => 'Puntos',
        };
    }

    private function mapearEstado(string $statusApi): string
    {
        $s = strtoupper($statusApi);
        return match (true) {
            in_array($s, ['FT', 'AOT', 'FINISHED', 'FINAL']) => 'finalizado',
            in_array($s, ['LIVE', 'Q1', 'Q2', 'Q3', 'Q4', 'IN PLAY', 'HT', '1H', '2H']) => 'en_curso',
            in_array($s, ['PST', 'POSTPONED']) => 'pospuesto',
            in_array($s, ['CANC', 'CANCELLED']) => 'cancelado',
            default => 'programado',
        };
    }
}