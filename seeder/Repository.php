<?php
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit("Acceso denegado.\n");
}


/**
 * Capa de acceso a datos del seeder.
 *
 * Principio central: la idempotencia NO vive en columnas
 * external_id_<fuente> dentro de cada tabla, vive en UNA tabla
 * de mapeo (external_ids). El flujo para cualquier entidad es:
 *
 *   1. Buscar en external_ids (fuente, entidad, external_id).
 *   2. Si existe -> UPDATE de la fila de dominio y se retorna su id.
 *   3. Si no existe -> INSERT de la fila de dominio + INSERT del
 *      mapeo en external_ids, dentro de una transaccion.
 *
 * Ventaja sobre el diseño anterior: agregar una fuente nueva
 * (una 5ta o 6ta API) no requiere alterar ninguna tabla de dominio,
 * solo insertar filas en external_ids.
 */
class Repository
{
    public function __construct(
        private PDO $pdo,
        private Logger $logger
    ) {
    }

    // ------------------------------------------------------------------
    // Nucleo generico: resolver o crear cualquier entidad
    // ------------------------------------------------------------------

    /**
     * @param string $entidad  'pais'|'estadio'|'liga'|'equipo'|'jugador'|'partido'
     * @param string $tabla    nombre real de la tabla en la BD
     * @param array  $insert   columnas => valores para el INSERT inicial
     * @param array  $update   columnas => valores para el UPDATE si ya existe
     */
    private function upsertGenerico(
        string $entidad,
        string $tabla,
        array $insert,
        array $update,
        string $fuente,
        string $externalId,
        string $etiqueta
    ): ?int {
        try {
            $this->pdo->beginTransaction();

            $idExistente = $this->resolverExternalId($fuente, $entidad, $externalId);

            if ($idExistente !== null) {
                if (!empty($update)) {
                    $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($update)));
                    $stmt = $this->pdo->prepare("UPDATE $tabla SET $sets WHERE id = :id");
                    $stmt->execute([...$update, ':id' => $idExistente]);
                }
                $this->pdo->commit();
                $this->registrarSeedLog($fuente, $entidad, $externalId, $idExistente, 'actualizado');
                $this->logger->updated("$etiqueta ($fuente:$externalId) -> id $idExistente");
                return $idExistente;
            }

            $cols = implode(', ', array_keys($insert));
            $params = implode(', ', array_map(fn($c) => ":$c", array_keys($insert)));
            $stmt = $this->pdo->prepare("INSERT INTO $tabla ($cols) VALUES ($params)");
            $stmt->execute($insert);
            $nuevoId = (int)$this->pdo->lastInsertId();

            $this->vincularExternalId($fuente, $entidad, $nuevoId, $externalId);

            $this->pdo->commit();
            $this->registrarSeedLog($fuente, $entidad, $externalId, $nuevoId, 'creado');
            $this->logger->created("$etiqueta ($fuente:$externalId) -> id $nuevoId");
            return $nuevoId;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error("$etiqueta ($fuente:$externalId): " . $e->getMessage());
            $this->registrarSeedLog($fuente, $entidad, $externalId, 0, 'error', $e->getMessage());
            return null;
        }
    }

    public function resolverExternalId(string $fuente, string $entidad, string $externalId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT entidad_id FROM external_ids WHERE fuente = ? AND entidad = ? AND external_id = ? LIMIT 1'
        );
        $stmt->execute([$fuente, $entidad, $externalId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Inversa de resolverExternalId(): dado el id LOCAL de un equipo
     * (entidad_id de tu base), devuelve el external_id externo vinculado
     * para esa fuente, o null si no hay vinculo. Usado por
     * poblar_partidos_liga_espn.php para reconocer vinculos manuales
     * (vincular_equipo_espn.php) sin depender de que el equipo aparezca
     * en ninguna lista trafda de la API en ese momento puntual -- la
     * lista de equipos de una API externa puede variar entre llamadas,
     * pero el vinculo guardado en tu base es siempre el mismo.
     */
    public function resolverEspnIdDeEquipo(string $fuente, int $idEquipoLocal): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT external_id FROM external_ids WHERE fuente = ? AND entidad = 'equipo' AND entidad_id = ? LIMIT 1"
        );
        $stmt->execute([$fuente, $idEquipoLocal]);
        $externalId = $stmt->fetchColumn();
        return $externalId === false ? null : (string)$externalId;
    }

    private function vincularExternalId(string $fuente, string $entidad, int $entidadId, string $externalId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO external_ids (entidad, entidad_id, fuente, external_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$entidad, $entidadId, $fuente, $externalId]);
    }

    // ------------------------------------------------------------------
    // DEPORTES (catalogo fijo, solo lectura)
    // ------------------------------------------------------------------

    public function idDeportePorSlug(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM deportes WHERE slug = ?');
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Deporte '$slug' no existe. Revisa database/schema.sql");
        }
        return (int)$id;
    }

    // ------------------------------------------------------------------
    // PAISES
    // ------------------------------------------------------------------

    /**
     * FIX (codigo_iso2 no entraba en la columna): `paises.codigo_iso2` es
     * char(2), pero algunas fuentes devuelven codigos mas largos que un
     * ISO2 real -- el caso confirmado es api-sports.io con Inglaterra,
     * que usa "GB-ENG" (existe tambien "GB-SCT", "GB-WLS", "GB-NIR" para
     * el resto del Reino Unido) en vez de un ISO2 de 2 letras. Sin este
     * fix, el INSERT tira SQLSTATE[22001] "Data too long for column
     * codigo_iso2" y el pais (y por lo tanto la liga que dependia de el)
     * queda sin crear. Ya habia pasado una vez antes con la fuente
     * football-data (ver seed_log id=1, 11/jul/2026, mismo mensaje) --
     * en vez de parchear cada script llamador, se corta aca, en el unico
     * punto de escritura real a la columna, para que sea imposible que
     * vuelva a pasar sin importar la fuente.
     *
     * El external_id (usado para idempotencia en external_ids, columna
     * varchar(60)) SIGUE guardando el codigo completo sin recortar --
     * solo se recorta lo que se escribe en codigo_iso2. Para Inglaterra
     * esto guarda "GB" en la columna (el ISO2 real de United Kingdom,
     * que es correcto), mientras que external_id sigue siendo "GB-ENG"
     * para no romper el matching de futuras corridas del seeder.
     */
    public function upsertPais(string $fuente, string $nombre, ?string $iso2, ?string $bandera, string $externalId): ?int
    {
        $iso2Truncado = $iso2 !== null ? substr($iso2, 0, 2) : null;

        return $this->upsertGenerico(
            'pais',
            'paises',
            ['nombre' => $nombre, 'codigo_iso2' => $iso2Truncado, 'bandera_url' => $bandera],
            ['bandera_url' => $bandera],
            $fuente,
            $externalId,
            "pais '$nombre'"
        );
    }

    // ------------------------------------------------------------------
    // LIGAS / TORNEOS
    // ------------------------------------------------------------------

    /**
     * FIX PREVENTIVO (mismo patron que upsertEquipo()/upsertEstadio()):
     * el $update de abajo no incluia 'id_pais'. Hoy esto no esta
     * bloqueando nada porque poblar_paises.php resuelve el id_pais de
     * las ligas con un UPDATE propio por fuera de este metodo -- pero si
     * ApiSportsSeeder::run() se vuelve a correr sobre una liga que ya
     * existe (ej. para refrescar el logo), este bug le devolveria el
     * id_pais a NULL en vez de conservar/actualizar el valor real.
     */
    public function upsertLiga(
        string $fuente,
        int $idDeporte,
        string $nombre,
        ?int $idPais,
        ?string $temporada,
        string $tipo,
        ?string $logo,
        string $externalId
    ): ?int {
        return $this->upsertGenerico(
            'liga',
            'ligas_torneos',
            [
                'id_deporte' => $idDeporte, 'id_pais' => $idPais, 'nombre' => $nombre,
                'temporada' => $temporada, 'tipo' => $tipo, 'logo' => $logo,
            ],
            ['id_pais' => $idPais, 'temporada' => $temporada, 'logo' => $logo],
            $fuente,
            $externalId,
            "liga '$nombre'"
        );
    }

    // ------------------------------------------------------------------
    // ESTADIOS
    // ------------------------------------------------------------------

    /**
     * FIX (id_pais nunca se actualizaba en estadios ya existentes): mismo
     * bug, mismo patron que el ya documentado en upsertEquipo() -- el
     * $update de abajo no incluia 'id_pais', asi que solo se grababa en
     * el INSERT inicial (estadio nuevo). Para un estadio que ya existia
     * (creado cuando su liga todavia tenia id_pais=NULL, ej. los 89
     * estadios de Premier League/La Liga/Serie A/Bundesliga/Champions
     * League sembrados antes de poblar_paises.php), volver a correr
     * poblar_estadios_liga_apisports.php JAMAS iba a completar el pais:
     * upsertGenerico toma el camino de UPDATE (ya existe el external_id)
     * y ese UPDATE ignoraba la columna. Se arregla aca para que sea
     * imposible que un estadio quede con id_pais pegado en NULL para
     * siempre solo porque se creo antes de que su liga tuviera pais.
     */
    public function upsertEstadio(
        string $fuente,
        string $nombre,
        ?string $ciudad,
        ?int $idPais,
        ?int $capacidad,
        ?string $imagen,
        string $externalId
    ): ?int {
        return $this->upsertGenerico(
            'estadio',
            'estadios',
            [
                'nombre' => $nombre, 'ciudad' => $ciudad, 'id_pais' => $idPais,
                'capacidad' => $capacidad ?? 0, 'imagen' => $imagen,
            ],
            ['ciudad' => $ciudad, 'id_pais' => $idPais, 'capacidad' => $capacidad ?? 0, 'imagen' => $imagen],
            $fuente,
            $externalId,
            "estadio '$nombre'"
        );
    }

    // ------------------------------------------------------------------
    // EQUIPOS
    // ------------------------------------------------------------------

    /**
     * FIX (id_estadio/id_pais nunca se actualizaban en equipos ya
     * existentes): el $update de abajo no incluia 'id_estadio' ni
     * 'id_pais', asi que solo se grababan en el INSERT inicial (equipo
     * nuevo). Para un equipo que ApiSportsSeeder ya habia creado antes
     * (ej. los 29 de MLS, creados el 21/7 con id_estadio=null porque en
     * ese momento el venue de la respuesta de /teams ni se leia), volver
     * a correr el seeder JAMAS iba a completar el estadio: upsertGenerico
     * toma el camino de UPDATE (ya existe el external_id) y ese UPDATE
     * ignoraba las dos columnas. Mismo bug, dos columnas -- se arreglan
     * juntas aca. Efecto secundario esperado (y deseado): si mas adelante
     * un equipo cambia de estadio (mudanza, remodelacion con reapertura
     * con otro nombre) o se corrige el pais, correr el seeder de nuevo
     * ahora SI lo refleja en vez de quedar pegado al primer valor.
     */
    public function upsertEquipo(
        string $fuente,
        int $idDeporte,
        ?int $idLiga,
        ?int $idPais,
        ?int $idEstadio,
        string $nombre,
        ?string $siglas,
        ?string $grupo,
        ?string $escudo,
        ?string $entrenador,
        ?int $ranking,
        string $externalId
    ): ?int {
        return $this->upsertGenerico(
            'equipo',
            'equipos',
            [
                'id_deporte' => $idDeporte, 'id_liga' => $idLiga, 'id_pais' => $idPais,
                'id_estadio' => $idEstadio, 'nombre' => $nombre, 'siglas' => $siglas,
                'grupo' => $grupo, 'escudo' => $escudo, 'entrenador' => $entrenador,
                'ranking' => $ranking,
            ],
            [
                'id_liga' => $idLiga, 'id_pais' => $idPais, 'id_estadio' => $idEstadio,
                'escudo' => $escudo, 'entrenador' => $entrenador,
                'ranking' => $ranking, 'grupo' => $grupo,
            ],
            $fuente,
            $externalId,
            "equipo '$nombre'"
        );
    }

    // ------------------------------------------------------------------
    // JUGADORES
    // ------------------------------------------------------------------

    public function upsertJugador(
        string $fuente,
        int $idEquipo,
        string $nombre,
        string $posicion,
        ?int $dorsal,
        ?int $edad,
        ?string $nacionalidad,
        ?string $foto,
        int $estadisticaPrincipal,
        string $estadisticaLabel,
        string $externalId
    ): ?int {
        if (trim($nombre) === '') {
            $this->logger->skipped("jugador sin nombre ($fuente:$externalId), se omite");
            return null;
        }

        return $this->upsertGenerico(
            'jugador',
            'jugadores',
            [
                'id_equipo' => $idEquipo, 'nombre' => $nombre, 'posicion' => $posicion ?: 'N/D',
                'dorsal' => $dorsal, 'edad' => $edad, 'nacionalidad' => $nacionalidad, 'foto' => $foto,
                'estadistica_principal' => $estadisticaPrincipal, 'estadistica_label' => $estadisticaLabel,
            ],
            [
                'posicion' => $posicion ?: 'N/D', 'dorsal' => $dorsal, 'edad' => $edad,
                'foto' => $foto, 'estadistica_principal' => $estadisticaPrincipal,
                'estadistica_label' => $estadisticaLabel,
            ],
            $fuente,
            $externalId,
            "jugador '$nombre'"
        );
    }

    // ------------------------------------------------------------------
    // PARTIDOS
    // ------------------------------------------------------------------

    public function upsertPartido(
        string $fuente,
        int $idDeporte,
        ?int $idLiga,
        int $idEquipoLocal,
        int $idEquipoVisita,
        ?int $idEstadio,
        string $fase,
        ?int $jornada,
        ?string $grupo,
        string $fechaPartido,
        ?int $marcadorLocal,
        ?int $marcadorVisita,
        string $estado,
        string $externalId
    ): ?int {
        $codigo = strtoupper(substr($fuente, 0, 3)) . '-' . $externalId;
        return $this->upsertGenerico(
            'partido',
            'partidos',
            [
                'id_deporte' => $idDeporte, 'id_liga' => $idLiga, 'id_equipo_local' => $idEquipoLocal,
                'id_equipo_visita' => $idEquipoVisita, 'id_estadio' => $idEstadio, 'fase' => $fase,
                'jornada' => $jornada, 'grupo' => $grupo, 'fecha_partido' => $fechaPartido,
                'marcador_local' => $marcadorLocal, 'marcador_visita' => $marcadorVisita,
                'estado' => $estado, 'codigo' => $codigo,
            ],
            [
                'fecha_partido' => $fechaPartido, 'marcador_local' => $marcadorLocal,
                'marcador_visita' => $marcadorVisita, 'estado' => $estado,
            ],
            $fuente,
            $externalId,
            "partido $codigo"
        );
    }

    // ------------------------------------------------------------------
    // TABLA DE POSICIONES — se recalcula desde partidos finalizados,
    // nunca se inserta a mano.
    // ------------------------------------------------------------------

    public function recalcularTablaPosiciones(int $idLiga, ?string $temporada = null, ?string $filtroFaseContiene = null): void
    {
        // Detectamos si esta liga es un torneo de fase de grupos (ej.
        // Champions League, y en el futuro Libertadores/Sudamericana):
        // api-sports arma la fase de grupos como "Group A - 1", "Group B - 3",
        // etc. En estos torneos, ademas de los grupos hay rondas
        // clasificatorias (Qualifying Round, Preliminary Round) y toda la
        // eliminatoria directa (Round of 16, Quarter-finals, Semi-finals,
        // Final) que comparten el mismo id_liga pero NO tienen una tabla
        // de posiciones real (son eliminacion directa). Para estos casos,
        // la unica tabla de posiciones valida es la de los grupos: filtramos
        // por fase LIKE "Group%" en vez de la lista negra de abajo.
        //
        // Para ligas domesticas normales (Premier League, La Liga, etc.)
        // no existe ninguna fase "Group%", asi que caen en la rama de
        // siempre: excluimos partidos de repechaje/promocion-descenso
        // (ej. Ligue 1 vs Ligue 2, "Relegation Round" en api-sports), que
        // comparten id_liga con la fase regular pero no son parte de la
        // tabla de posiciones real. Sin este filtro, esos partidos inflan
        // la tabla con un equipo de mas (ej. Saint Etienne en Ligue 1
        // 2023-24, que en realidad jugo esa temporada en Ligue 2).
        //
        // OJO: no se puede filtrar por `jornada IS NOT NULL` -- ese campo
        // siempre viaja NULL para partidos de api-sports (ApiSportsSeeder
        // nunca lo completa, pasa null a upsertPartido() a proposito/por
        // bug aparte). La señal real esta en `fase`: api-sports arma
        // "Regular Season - N" para partidos normales y textos sin numero
        // como "Relegation Round" para repechajes. Usamos lista negra de
        // palabras clave en vez de lista blanca de textos exactos, porque
        // cada fuente (api-sports, football-data, TheSportsDB) redacta
        // "temporada regular" distinto (ej. "Temporada Regular",
        // "REGULAR_SEASON") y no queremos excluir esas por accidente.
        //
        // FIX (15/8, encontrado recalculando liga 28 -- Liga Profesional
        // Argentina): la lista negra original solo cubria el vocabulario
        // de api-sports (Relegation/Promotion/Play-off/Barrage). Pero
        // poblar_partidos_liga_espn.php y poblar_calendario_espn.php
        // (fuente 'espn-futbol') traen la eliminatoria directa del
        // Apertura con fases como "Apertura - Round of 16",
        // "Apertura - Quarterfinals", "Apertura - Semifinals" y
        // "Apertura - Final" -- ninguna contenia ninguna de esas
        // palabras, asi que esos partidos YA JUGADOS de eliminacion
        // directa se colaban en la tabla de posiciones como si fueran
        // fase de grupos, inflando PJ/puntos de los equipos que
        // llegaron a playoffs. Se agregan los patrones de ESPN a la
        // misma lista negra (mismo criterio: lista negra de palabras
        // clave, no lista blanca de textos exactos, para no tener que
        // tocar esto de nuevo si aparece "Clausura - Final" mas
        // adelante -- "- Final" ya lo cubre de forma generica).
        $stmtCheck = $this->pdo->prepare(
            'SELECT 1 FROM partidos WHERE id_liga = ? AND fase LIKE "Group%" LIMIT 1'
        );
        $stmtCheck->execute([$idLiga]);
        $esFaseDeGrupos = (bool)$stmtCheck->fetchColumn();

        // FIX (15/8): filtro opcional por torneo puntual. Cuando se pasa
        // $filtroFaseContiene (ej. "Apertura" o "Clausura"), se agrega un
        // AND fase LIKE "%...%" a CUALQUIERA de las dos ramas de abajo --
        // es un filtro adicional, no reemplaza la lista negra/blanca ya
        // existente. Necesario en ligas como la Argentina, donde Apertura
        // y Clausura comparten id_liga pero son 2 tablas de posiciones
        // reales y separadas.
        $params = [$idLiga];
        $condicionFiltroFase = '';
        if ($filtroFaseContiene !== null && $filtroFaseContiene !== '') {
            $condicionFiltroFase = ' AND fase LIKE ?';
            $params[] = '%' . $filtroFaseContiene . '%';
        }

        if ($esFaseDeGrupos) {
            $stmt = $this->pdo->prepare(
                'SELECT id, id_equipo_local, id_equipo_visita, marcador_local, marcador_visita, grupo
                 FROM partidos
                 WHERE id_liga = ? AND estado = "finalizado"
                   AND marcador_local IS NOT NULL AND marcador_visita IS NOT NULL
                   AND fase LIKE "Group%"' . $condicionFiltroFase
            );
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, id_equipo_local, id_equipo_visita, marcador_local, marcador_visita, grupo
                 FROM partidos
                 WHERE id_liga = ? AND estado = "finalizado"
                   AND marcador_local IS NOT NULL AND marcador_visita IS NOT NULL
                   AND fase NOT LIKE "%Relegation%"
                   AND fase NOT LIKE "%Promotion%"
                   AND fase NOT LIKE "%Play-off%"
                   AND fase NOT LIKE "%Playoff%"
                   AND fase NOT LIKE "%Play off%"
                   AND fase NOT LIKE "%Barrage%"
                   AND fase NOT LIKE "%Round of%"
                   AND fase NOT LIKE "%Quarterfinal%"
                   AND fase NOT LIKE "%Quarter-final%"
                   AND fase NOT LIKE "%Semifinal%"
                   AND fase NOT LIKE "%Semi-final%"
                   AND fase NOT LIKE "%- Final%"' . $condicionFiltroFase
            );
        }
        $stmt->execute($params);
        $partidos = $stmt->fetchAll();

        $tabla = []; // id_equipo => stats

        foreach ($partidos as $p) {
            foreach (['local', 'visita'] as $lado) {
                $idEquipo = $p["id_equipo_$lado"];
                $tabla[$idEquipo] ??= [
                    'grupo' => $p['grupo'], 'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0,
                    'favor' => 0, 'contra' => 0, 'pts' => 0,
                ];
            }

            $gl = (int)$p['marcador_local'];
            $gv = (int)$p['marcador_visita'];
            $local = &$tabla[$p['id_equipo_local']];
            $visita = &$tabla[$p['id_equipo_visita']];

            $local['pj']++; $visita['pj']++;
            $local['favor'] += $gl; $local['contra'] += $gv;
            $visita['favor'] += $gv; $visita['contra'] += $gl;

            if ($gl > $gv) {
                $local['pg']++; $local['pts'] += 3;
                $visita['pp']++;
            } elseif ($gl < $gv) {
                $visita['pg']++; $visita['pts'] += 3;
                $local['pp']++;
            } else {
                $local['pe']++; $local['pts'] += 1;
                $visita['pe']++; $visita['pts'] += 1;
            }
            unset($local, $visita);
        }

        $upsert = $this->pdo->prepare(
            'INSERT INTO tabla_posiciones (id_liga, id_equipo, temporada, grupo, pj, pg, pe, pp, favor, contra, pts)
             VALUES (:liga, :equipo, :temporada, :grupo, :pj, :pg, :pe, :pp, :favor, :contra, :pts)
             ON DUPLICATE KEY UPDATE
                grupo = VALUES(grupo), pj = VALUES(pj), pg = VALUES(pg), pe = VALUES(pe), pp = VALUES(pp),
                favor = VALUES(favor), contra = VALUES(contra), pts = VALUES(pts)'
        );

        foreach ($tabla as $idEquipo => $s) {
            $upsert->execute([
                ':liga' => $idLiga, ':equipo' => $idEquipo, ':temporada' => $temporada, ':grupo' => $s['grupo'],
                ':pj' => $s['pj'], ':pg' => $s['pg'], ':pe' => $s['pe'], ':pp' => $s['pp'],
                ':favor' => $s['favor'], ':contra' => $s['contra'], ':pts' => $s['pts'],
            ]);
        }

        $this->logger->info("tabla_posiciones recalculada para liga $idLiga (" . count($tabla) . " equipos)");
    }

    // ------------------------------------------------------------------
    // Bitacora
    // ------------------------------------------------------------------

    private function registrarSeedLog(
        string $fuente,
        string $entidad,
        string $externalId,
        int $idInterno,
        string $accion,
        ?string $detalle = null
    ): void {
        $sql = 'INSERT INTO seed_log (fuente, entidad, external_id, id_interno, accion, detalle)
                VALUES (:fuente, :entidad, :ext, :idi, :accion, :detalle)
                ON DUPLICATE KEY UPDATE
                    id_interno = VALUES(id_interno), accion = VALUES(accion),
                    detalle = VALUES(detalle), created_at = CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([
            ':fuente' => $fuente, ':entidad' => $entidad, ':ext' => $externalId,
            ':idi' => $idInterno, ':accion' => $accion,
            ':detalle' => $detalle !== null ? substr($detalle, 0, 500) : null,
        ]);
    }
}