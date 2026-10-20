<?php

declare(strict_types=1);

/**
 * Motor de Generación de Cuotas Realistas para GoalBet2026.
 *
 * Calcula probabilidades empíricas basadas en:
 * - Tabla de posiciones (puntos por partido, diferencia de goles)
 * - Ventaja de localía (+20% peso ofensivo/defensivo)
 * - Margen de la casa (overround) del 6% al 8% (default: 7%)
 * - Piso mínimo de cuota (floor): 1.05
 * - Regla de deporte: 3 cuotas si tiene_empate = 1 (Fútbol),
 *   2 cuotas si tiene_empate = 0 (NBA, MLB, NFL), dejando cuota_empate en NULL.
 */

/**
 * Calcula cuotas para un partido dado el rendimiento de ambos equipos.
 *
 * @return array{local: float, empate: ?float, visita: float}
 */
function calcular_cuotas_equipos(
    PDO $pdo,
    int $idLiga,
    int $idLocal,
    int $idVisita,
    bool $tieneEmpate,
    float $margenCasa = 0.07
): array {
    $margenCasa = max(0.05, min(0.12, $margenCasa)); // Garantizar margen razonable (5% - 12%)
    $pisoCuota = 1.05;

    // 1. Consultar estadísticas de ambos equipos en la tabla de posiciones
    $stmt = $pdo->prepare(
        'SELECT id_equipo, pj, pg, pe, pp, favor, contra, pts
         FROM tabla_posiciones
         WHERE id_liga = ? AND id_equipo IN (?, ?)'
    );
    $stmt->execute([$idLiga, $idLocal, $idVisita]);
    $stats = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $statLocal = $stats[$idLocal] ?? null;
    $statVisita = $stats[$idVisita] ?? null;

    // Métricas por equipo (Puntos por Partido y Dif Goles por Partido)
    $ppgLocal = ($statLocal && $statLocal['pj'] > 0)
        ? ((float) $statLocal['pts'] / (float) $statLocal['pj'])
        : 1.35; // promedio base neutro
    $difLocal = ($statLocal && $statLocal['pj'] > 0)
        ? (((float) $statLocal['favor'] - (float) $statLocal['contra']) / (float) $statLocal['pj'])
        : 0.0;

    $ppgVisita = ($statVisita && $statVisita['pj'] > 0)
        ? ((float) $statVisita['pts'] / (float) $statVisita['pj'])
        : 1.35;
    $difVisita = ($statVisita && $statVisita['pj'] > 0)
        ? (((float) $statVisita['favor'] - (float) $statVisita['contra']) / (float) $statVisita['pj'])
        : 0.0;

    // Ventaja de localía (+0.30 puntos equivalentes)
    $fuerzaLocal = max(0.2, $ppgLocal + ($difLocal * 0.25) + 0.30);
    $fuerzaVisita = max(0.2, $ppgVisita + ($difVisita * 0.25));

    $difFuerza = $fuerzaLocal - $fuerzaVisita;

    if ($tieneEmpate) {
        // Modelo de 3 resultados (Fútbol)
        // Probabilidad base de empate típica en fútbol: 24% - 28%
        $probEmpateBruta = max(0.18, 0.27 - (abs($difFuerza) * 0.05));

        // El resto se reparte entre local y visita según su fuerza relativa
        $resto = 1.0 - $probEmpateBruta;
        $ratioLocal = 1.0 / (1.0 + exp(-$difFuerza * 1.3)); // Función logística sigmoide

        $probLocalBruta = $resto * $ratioLocal;
        $probVisitaBruta = $resto * (1.0 - $ratioLocal);

        // Normalizar probabilidades brutas sumen 1.0
        $sumaBruta = $probLocalBruta + $probEmpateBruta + $probVisitaBruta;
        $pL = $probLocalBruta / $sumaBruta;
        $pX = $probEmpateBruta / $sumaBruta;
        $pV = $probVisitaBruta / $sumaBruta;

        // Aplicar margen de la casa (overround uniforme)
        // Cuota = 1 / (Probabilidad * (1 + Margen))
        $overround = 1.0 + $margenCasa;
        $cuotaLocal = max($pisoCuota, round(1.0 / ($pL * $overround), 2));
        $cuotaEmpate = max($pisoCuota, round(1.0 / ($pX * $overround), 2));
        $cuotaVisita = max($pisoCuota, round(1.0 / ($pV * $overround), 2));

        return [
            'local'  => $cuotaLocal,
            'empate' => $cuotaEmpate,
            'visita' => $cuotaVisita,
        ];
    } else {
        // Modelo de 2 resultados (NBA, MLB, NFL sin empate)
        $ratioLocal = 1.0 / (1.0 + exp(-$difFuerza * 1.1));
        $pL = max(0.10, min(0.90, $ratioLocal));
        $pV = 1.0 - $pL;

        $overround = 1.0 + $margenCasa;
        $cuotaLocal = max($pisoCuota, round(1.0 / ($pL * $overround), 2));
        $cuotaVisita = max($pisoCuota, round(1.0 / ($pV * $overround), 2));

        return [
            'local'  => $cuotaLocal,
            'empate' => null,
            'visita' => $cuotaVisita,
        ];
    }
}

/**
 * Asigna cuotas realistas automáticas a todos los partidos 'programados'
 * que no tengan apuestas pendientes asociadas (para no alterar cuotas de boletos en juego).
 *
 * @param PDO $pdo
 * @param int|null $idLiga Si se especifica, solo procesa esa liga.
 * @return int Cantidad de partidos actualizados
 */
function asignar_cuotas_partidos_pendientes(PDO $pdo, ?int $idLiga = null): int
{
    $sql = "SELECT p.id, p.id_liga, p.id_equipo_local, p.id_equipo_visita,
                   d.tiene_empate, p.cuota_local, p.cuota_empate, p.cuota_visita
            FROM partidos p
            JOIN deportes d ON d.id = p.id_deporte
            WHERE p.estado = 'programado'
              AND p.id NOT IN (SELECT DISTINCT id_partido FROM apuestas WHERE estado = 'pendiente')";

    $params = [];
    if ($idLiga !== null) {
        $sql .= " AND p.id_liga = ?";
        $params[] = $idLiga;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $partidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $actualizados = 0;
    $stmtUpdate = $pdo->prepare(
        'UPDATE partidos SET cuota_local = ?, cuota_empate = ?, cuota_visita = ? WHERE id = ?'
    );

    foreach ($partidos as $p) {
        $nuevasCuotas = calcular_cuotas_equipos(
            $pdo,
            (int) $p['id_liga'],
            (int) $p['id_equipo_local'],
            (int) $p['id_equipo_visita'],
            (bool) $p['tiene_empate'],
            0.07 // 7% margen casa
        );

        // Solo actualizar si las cuotas son diferentes
        $stmtUpdate->execute([
            $nuevasCuotas['local'],
            $nuevasCuotas['empate'],
            $nuevasCuotas['visita'],
            $p['id'],
        ]);
        $actualizados++;
    }

    return $actualizados;
}
