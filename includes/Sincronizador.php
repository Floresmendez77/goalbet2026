<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once SEEDER_PATH . '/HttpClient.php';
require_once SEEDER_PATH . '/Logger.php';
require_once SEEDER_PATH . '/Repository.php';
require_once SEEDER_PATH . '/Seeders/EspnSeeder.php';

/**
 * Servicio Central de Sincronización para GoalBet2026.
 *
 * Automatiza la obtención de resultados y calendario futuro desde ESPN,
 * delega la liquidación de apuestas a los triggers de base de datos
 * (trg_partidos_resolver_apuestas / sp_resolver_partido / sp_reembolsar_partido),
 * recalcula tablas de posiciones y registra auditoría en sync_log.
 */
class Sincronizador
{
    private PDO $pdo;
    private Logger $logger;
    private HttpClient $http;
    private Repository $repo;
    private EspnSeeder $espnSeeder;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
        $this->logger = new Logger();
        $this->http = new HttpClient($this->logger);
        $this->repo = new Repository($this->pdo, $this->logger);
        $this->espnSeeder = new EspnSeeder($this->repo, $this->pdo, $this->http, $this->logger);
    }

    /**
     * Devuelve las ligas configuradas y marcadas como activas para sincronización.
     */
    public function obtenerLigasActivas(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ls.id AS sync_id, ls.id_liga, ls.deporte, ls.slug_espn, ls.activa,
                    lt.nombre, lt.id_deporte, lt.temporada
             FROM ligas_sync ls
             JOIN ligas_torneos lt ON lt.id = ls.id_liga
             WHERE ls.activa = 1
             ORDER BY lt.nombre ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Devuelve todas las ligas configuradas (activas e inactivas).
     */
    public function obtenerTodasLigas(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ls.id AS sync_id, ls.id_liga, ls.deporte, ls.slug_espn, ls.activa,
                    lt.nombre, lt.id_deporte, lt.temporada
             FROM ligas_sync ls
             JOIN ligas_torneos lt ON lt.id = ls.id_liga
             ORDER BY lt.nombre ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sincroniza una liga puntual (resultados y nuevos partidos).
     *
     * @param int|array $liga           ID de ligas_torneos o array con datos de la liga
     * @param string|null $desde        Fecha inicial (YYYY-MM-DD). Default: hace 7 días
     * @param string|null $hasta        Fecha final (YYYY-MM-DD). Default: dentro de 14 días
     * @param string $usuarioAdmin      Nombre de usuario o proceso invocador
     * @param bool $crearNuevos         Si es true, crea nuevos partidos futuros programados
     */
    public function sincronizarLiga(
        int|array $liga,
        ?string $desde = null,
        ?string $hasta = null,
        string $usuarioAdmin = 'Sistema',
        bool $crearNuevos = true
    ): array {
        if (is_int($liga)) {
            $stmt = $this->pdo->prepare(
                'SELECT ls.id AS sync_id, ls.id_liga, ls.deporte, ls.slug_espn, ls.activa,
                        lt.nombre, lt.id_deporte, lt.temporada
                 FROM ligas_sync ls
                 JOIN ligas_torneos lt ON lt.id = ls.id_liga
                 WHERE ls.id_liga = ?
                 LIMIT 1'
            );
            $stmt->execute([$liga]);
            $datosLiga = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$datosLiga) {
                return [
                    'exito' => false,
                    'error' => "Liga #$liga no está configurada en ligas_sync.",
                ];
            }
        } else {
            $datosLiga = $liga;
        }

        $desde = $desde ?? date('Y-m-d', strtotime('-7 days'));
        $hasta = $hasta ?? date('Y-m-d', strtotime('+14 days'));

        $nombreLiga = $datosLiga['nombre'] ?? ('Liga #' . $datosLiga['id_liga']);
        $deporteSlug = $datosLiga['deporte'];
        $slugEspn = $datosLiga['slug_espn'];
        $idLiga = (int) $datosLiga['id_liga'];

        $errores = 0;
        $detalle = '';
        $stats = [
            'actualizados' => 0,
            'creados'      => 0,
            'omitidos'     => 0,
            'sin_match'    => 0,
        ];

        try {
            // Invocar EspnSeeder con modo aplicar activo
            $stats = $this->espnSeeder->sincronizarResultados(
                $deporteSlug,
                $slugEspn,
                $idLiga,
                $desde,
                $hasta,
                true,
                $crearNuevos
            );

            // Generar cuotas realistas para partidos nuevos si cuotas.php está disponible
            if (file_exists(__DIR__ . '/cuotas.php')) {
                require_once __DIR__ . '/cuotas.php';
                if (function_exists('asignar_cuotas_partidos_pendientes')) {
                    asignar_cuotas_partidos_pendientes($this->pdo, $idLiga);
                }
            }

            $detalle = sprintf(
                'Sincronización exitosa: %d actualizados, %d creados, %d omitidos, %d sin match.',
                $stats['actualizados'],
                $stats['creados'],
                $stats['omitidos'],
                $stats['sin_match']
            );
        } catch (Throwable $e) {
            $errores = 1;
            $detalle = 'Error en sincronización: ' . $e->getMessage();
            $this->logger->error("Sincronizador fallo en $nombreLiga: " . $e->getMessage());
        }

        // Registrar auditoría en sync_log
        try {
            $stmtLog = $this->pdo->prepare(
                'INSERT INTO sync_log (fecha, usuario_admin, liga, resultados_actualizados, partidos_nuevos, errores, detalle)
                 VALUES (NOW(), :usuario, :liga, :actualizados, :nuevos, :errores, :detalle)'
            );
            $stmtLog->execute([
                ':usuario'      => substr($usuarioAdmin, 0, 100),
                ':liga'         => substr($nombreLiga, 0, 100),
                ':actualizados' => $stats['actualizados'],
                ':nuevos'       => $stats['creados'],
                ':errores'      => $errores,
                ':detalle'      => $detalle,
            ]);
        } catch (Throwable $eLog) {
            $this->logger->error('Error registrando sync_log: ' . $eLog->getMessage());
        }

        return [
            'exito'         => ($errores === 0),
            'id_liga'       => $idLiga,
            'liga'          => $nombreLiga,
            'actualizados'  => $stats['actualizados'],
            'creados'       => $stats['creados'],
            'omitidos'      => $stats['omitidos'],
            'sin_match'     => $stats['sin_match'],
            'errores'       => $errores,
            'detalle'       => $detalle,
        ];
    }

    /**
     * Sincroniza todas las ligas marcadas como activas.
     */
    public function sincronizarTodas(
        int $diasAtras = 7,
        int $diasAdelante = 14,
        string $usuarioAdmin = 'Cron'
    ): array {
        $ligas = $this->obtenerLigasActivas();
        $desde = date('Y-m-d', strtotime("-$diasAtras days"));
        $hasta = date('Y-m-d', strtotime("+$diasAdelante days"));

        $resultados = [];
        $totalActualizados = 0;
        $totalCreados = 0;
        $totalErrores = 0;

        foreach ($ligas as $liga) {
            $res = $this->sincronizarLiga($liga, $desde, $hasta, $usuarioAdmin, true);
            $resultados[] = $res;
            $totalActualizados += $res['actualizados'] ?? 0;
            $totalCreados += $res['creados'] ?? 0;
            $totalErrores += $res['errores'] ?? 0;
        }

        return [
            'total_ligas'        => count($ligas),
            'total_actualizados' => $totalActualizados,
            'total_creados'      => $totalCreados,
            'total_errores'      => $totalErrores,
            'desde'              => $desde,
            'hasta'              => $hasta,
            'detalle_por_liga'   => $resultados,
        ];
    }

    /**
     * Devuelve las últimas entradas de auditoría de sync_log.
     */
    public function obtenerUltimosLogs(int $limite = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_log ORDER BY id DESC LIMIT :limite'
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
