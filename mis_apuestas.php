<?php
require_once __DIR__ . '/includes/bootstrap.php';
requerir_login();
refrescar_usuario_sesion();

$usuario = usuario_actual();
$estado_filtro = $_GET['estado'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $accion = $_POST['accion'] ?? 'cancelar_apuesta';

    if ($accion === 'cancelar_boleto') {
        // GAP-002: cancelación de un boleto combinado completo (todas sus patas a
        // la vez). Solo se permite si NINGUNA pata empezó a jugarse todavía.
        $id_boleto = (int) ($_POST['id_boleto'] ?? 0);
        $pdo = db();
        $maxIntentos = 3;
        $exito = false;
        $monto_reembolsado = 0.0;

        for ($intento = 1; $intento <= $maxIntentos; $intento++) {
            try {
                $pdo->beginTransaction();

                $stmt_u = $pdo->prepare('SELECT saldo FROM usuarios WHERE id = ? FOR UPDATE');
                $stmt_u->execute([$usuario['id']]);
                $saldo_anterior = $stmt_u->fetchColumn();
                if ($saldo_anterior === false) {
                    throw new RuntimeException('Usuario no encontrado.');
                }
                $saldo_anterior = (float) $saldo_anterior;

                $stmt_b = $pdo->prepare('SELECT * FROM boletos WHERE id = ? AND id_usuario = ? FOR UPDATE');
                $stmt_b->execute([$id_boleto, $usuario['id']]);
                $boleto = $stmt_b->fetch();
                if (!$boleto) {
                    throw new RuntimeException('Boleto no encontrado.');
                }
                if ($boleto['estado'] !== 'pendiente') {
                    throw new RuntimeException('Este boleto ya no se puede cancelar (ya fue procesado).');
                }

                // Todas las patas deben seguir pendientes y sus partidos programados.
                $stmt_chk = $pdo->prepare(
                    "SELECT COUNT(*) AS total,
                            SUM(CASE WHEN a.estado <> 'pendiente' OR p.estado <> 'programado' THEN 1 ELSE 0 END) AS bloqueadas
                     FROM apuestas a JOIN partidos p ON p.id = a.id_partido
                     WHERE a.id_boleto = ?"
                );
                $stmt_chk->execute([$id_boleto]);
                $chk = $stmt_chk->fetch();
                if (!$chk || (int) $chk['total'] === 0 || (int) $chk['bloqueadas'] > 0) {
                    throw new RuntimeException('Este boleto ya no se puede cancelar (al menos uno de sus partidos ya empezó o fue procesado).');
                }

                $monto = (float) $boleto['monto'];
                $monto_reembolsado = $monto;
                $saldo_nuevo = $saldo_anterior + $monto;

                $pdo->prepare('UPDATE usuarios SET saldo = ? WHERE id = ?')->execute([$saldo_nuevo, $usuario['id']]);
                $pdo->prepare("UPDATE apuestas SET estado = 'cancelada', resuelta_at = NOW() WHERE id_boleto = ? AND estado = 'pendiente'")
                    ->execute([$id_boleto]);
                $pdo->prepare("UPDATE boletos SET estado = 'cancelado', resuelto_at = NOW() WHERE id = ? AND estado = 'pendiente'")
                    ->execute([$id_boleto]);

                $ref_tx = generar_referencia('RC-BOL');
                $pdo->prepare(
                    'INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
                     VALUES (?, "reembolso_cancelacion_cliente", ?, ?, ?, ?, ?)'
                )->execute([
                    $usuario['id'], $monto, $saldo_anterior, $saldo_nuevo, $ref_tx,
                    'Cancelación manual de boleto combinado #' . $id_boleto,
                ]);

                $pdo->commit();
                $exito = true;
                break;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $esDeadlock = ($e instanceof PDOException && (string) $e->getCode() === '40001');
                if ($esDeadlock && $intento < $maxIntentos) {
                    usleep(random_int(50000, 150000));
                    continue;
                }

                flash('danger', $esDeadlock
                    ? 'El sistema está ocupado procesando uno de estos partidos, intentá de nuevo en unos segundos.'
                    : $e->getMessage());
                break;
            }
        }

        if ($exito) {
            refrescar_usuario_sesion();
            flash('success', 'Boleto #' . $id_boleto . ' cancelado exitosamente. Se reembolsaron ' . money($monto_reembolsado) . ' a tu saldo.');
        }

        redirect('/mis_apuestas.php');
    }

    $id_apuesta = (int) ($_POST['id_apuesta'] ?? 0);
    $pdo = db();
    $maxIntentos = 3;
    $exito = false;
    $monto_reembolsado = 0.0;

    for ($intento = 1; $intento <= $maxIntentos; $intento++) {
        try {
            $pdo->beginTransaction();

            // Lock 1: Lock de USUARIOS primero
            $stmt_u = $pdo->prepare('SELECT saldo FROM usuarios WHERE id = ? FOR UPDATE');
            $stmt_u->execute([$usuario['id']]);
            $saldo_anterior = $stmt_u->fetchColumn();

            if ($saldo_anterior === false) {
                throw new RuntimeException('Usuario no encontrado.');
            }
            $saldo_anterior = (float) $saldo_anterior;

            // Lock 2: Lock de APUESTAS segundo (SIN JOIN a partidos)
            $stmt_a = $pdo->prepare('SELECT * FROM apuestas WHERE id = ? AND id_usuario = ? FOR UPDATE');
            $stmt_a->execute([$id_apuesta, $usuario['id']]);
            $apuesta = $stmt_a->fetch();

            if (!$apuesta) {
                throw new RuntimeException('Apuesta no encontrada.');
            }

            if ($apuesta['id_boleto'] !== null) {
                throw new RuntimeException('Esta apuesta es parte de un boleto combinado; cancelá el boleto completo en la sección "Boletos combinados".');
            }

            // Lectura informativa SIN LOCK sobre partidos
            $stmt_p = $pdo->prepare('SELECT estado FROM partidos WHERE id = ?');
            $stmt_p->execute([$apuesta['id_partido']]);
            $estado_partido = $stmt_p->fetchColumn();

            if ($apuesta['estado'] !== 'pendiente' || $estado_partido !== 'programado') {
                throw new RuntimeException('Esta apuesta ya no se puede cancelar (el evento cambió de estado o la apuesta fue procesada).');
            }

            $monto = (float) $apuesta['monto'];
            $monto_reembolsado = $monto;
            $saldo_nuevo = $saldo_anterior + $monto;

            // 1. Acreditar saldo
            $pdo->prepare('UPDATE usuarios SET saldo = ? WHERE id = ?')->execute([$saldo_nuevo, $usuario['id']]);

            // 2. Marcar apuesta como cancelada
            $pdo->prepare('UPDATE apuestas SET estado = "cancelada", resuelta_at = NOW() WHERE id = ? AND estado = "pendiente"')
                ->execute([$id_apuesta]);

            // 3. Registrar transacción de reembolso por cliente
            $ref_tx = generar_referencia('REEMB-CLI');
            $pdo->prepare(
                'INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
                 VALUES (?, "reembolso_cancelacion_cliente", ?, ?, ?, ?, ?)'
            )->execute([
                $usuario['id'], $monto, $saldo_anterior, $saldo_nuevo, $ref_tx,
                'Cancelación manual de apuesta #' . $id_apuesta,
            ]);

            $pdo->commit();
            $exito = true;
            break;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $esDeadlock = ($e instanceof PDOException && (string)$e->getCode() === '40001');
            if ($esDeadlock && $intento < $maxIntentos) {
                usleep(random_int(50000, 150000)); // backoff aleatorio 50-150ms
                continue;
            }

            flash('danger', $esDeadlock
                ? 'El sistema está ocupado procesando este partido, intentá de nuevo en unos segundos.'
                : $e->getMessage());
            break;
        }
    }

    if ($exito) {
        refrescar_usuario_sesion();
        flash('success', 'Apuesta #' . $id_apuesta . ' cancelada exitosamente. Se reembolsaron ' . money($monto_reembolsado) . ' a tu saldo.');
    }

    redirect('/mis_apuestas.php');
}

$sql = "SELECT a.*, d.nombre AS deporte, el.nombre AS equipo_local, ev.nombre AS equipo_visita,
               p.fecha_partido, p.estado AS estado_partido, p.marcador_local, p.marcador_visita
        FROM apuestas a
        JOIN partidos p ON p.id = a.id_partido
        JOIN deportes d ON d.id = p.id_deporte
        JOIN equipos el ON el.id = p.id_equipo_local
        JOIN equipos ev ON ev.id = p.id_equipo_visita
        WHERE a.id_usuario = ?";
$params = [$usuario['id']];

if (in_array($estado_filtro, ['pendiente', 'ganada', 'perdida', 'cancelada', 'pagada'], true)) {
    $sql .= ' AND a.estado = ?';
    $params[] = $estado_filtro;
}
$sql .= ' ORDER BY a.created_at DESC LIMIT 100';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$apuestas = $stmt->fetchAll();

// GAP-002: resumen de boletos combinados del usuario (independiente del filtro
// de estado de arriba, que aplica solo a apuestas simples/patas individuales).
$stmt_bol = db()->prepare(
    "SELECT b.*,
            GROUP_CONCAT(
              CONCAT(el.nombre, ' vs ', ev.nombre, ' (', UPPER(LEFT(a.tipo_apuesta,1)), LOWER(SUBSTRING(a.tipo_apuesta,2)), ' @', a.cuota, ')')
              ORDER BY a.id SEPARATOR ' · '
            ) AS detalle,
            COUNT(a.id) AS num_patas,
            SUM(CASE WHEN a.estado <> 'pendiente' OR p.estado <> 'programado' THEN 1 ELSE 0 END) AS patas_bloqueadas
     FROM boletos b
     JOIN apuestas a ON a.id_boleto = b.id
     JOIN partidos p ON p.id = a.id_partido
     JOIN equipos el ON el.id = p.id_equipo_local
     JOIN equipos ev ON ev.id = p.id_equipo_visita
     WHERE b.id_usuario = ?
     GROUP BY b.id
     ORDER BY b.created_at DESC
     LIMIT 50"
);
$stmt_bol->execute([$usuario['id']]);
$boletos = $stmt_bol->fetchAll();

$titulo_pagina = 'Mis apuestas';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="bi bi-receipt"></i> Mis apuestas</h1>
  <form method="get" class="d-flex gap-2">
    <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Todos los estados</option>
      <?php foreach (['pendiente', 'ganada', 'perdida', 'cancelada', 'pagada'] as $es): ?>
        <option value="<?= $es ?>" <?= $estado_filtro === $es ? 'selected' : '' ?>><?= ucfirst($es) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($boletos): ?>
  <h2 class="h5 mb-3"><i class="bi bi-layers-fill"></i> Boletos combinados</h2>
  <div class="table-responsive mb-4">
    <table class="table table-hover bg-white align-middle">
      <thead class="table-dark">
        <tr>
          <th>Patas</th>
          <th>Monto</th>
          <th>Cuota total</th>
          <th>Ganancia pot.</th>
          <th>Estado</th>
          <th>Fecha</th>
          <th class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($boletos as $b): ?>
          <tr data-cy="mis-boletos-row">
            <td class="small"><?= (int) $b['num_patas'] ?> partidos<br><span class="text-muted"><?= e($b['detalle']) ?></span></td>
            <td><?= money($b['monto']) ?></td>
            <td>x<?= e(number_format((float) $b['cuota_total'], 2)) ?></td>
            <td><?= money($b['ganancia_potencial']) ?></td>
            <td><?= badge_estado($b['estado']) ?></td>
            <td class="small text-muted"><?= fecha_legible($b['created_at']) ?></td>
            <td class="text-end">
              <?php if ($b['estado'] === 'pendiente' && (int) $b['patas_bloqueadas'] === 0): ?>
                <form method="post" class="d-inline" data-confirm="¿Estás seguro de cancelar este boleto de <?= money($b['monto']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="cancelar_boleto">
                  <input type="hidden" name="id_boleto" value="<?= (int) $b['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" data-cy="btn-cancelar-boleto">
                    <i class="bi bi-x-circle"></i> Cancelar boleto
                  </button>
                </form>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<h2 class="h5 mb-3">Detalle de apuestas (incluye patas de boletos combinados)</h2>
<?php if (!$apuestas): ?>
  <div class="alert alert-secondary">No hay apuestas con ese filtro.</div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-hover bg-white align-middle">
    <thead class="table-dark">
      <tr>
        <th>Partido</th>
        <th>Apuesta</th>
        <th>Monto</th>
        <th>Cuota</th>
        <th>Ganancia pot.</th>
        <th>Estado</th>
        <th>Fecha</th>
        <th class="text-end">Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($apuestas as $a): ?>
        <tr data-cy="mis-apuestas-row">
          <td>
            <span class="badge bg-dark badge-deporte me-1"><?= e($a['deporte']) ?></span>
            <?= e($a['equipo_local']) ?> vs <?= e($a['equipo_visita']) ?>
            <?php if ($a['estado_partido'] === 'finalizado'): ?>
              <div class="small text-muted"><?= (int) $a['marcador_local'] ?> - <?= (int) $a['marcador_visita'] ?></div>
            <?php endif; ?>
          </td>
          <td><?= e(ucfirst($a['tipo_apuesta'])) ?><?= $a['id_boleto'] ? ' <span class="badge bg-info text-dark">Combinada</span>' : '' ?></td>
          <td><?= money($a['monto']) ?></td>
          <td><?= e($a['cuota']) ?></td>
          <td>
            <?php if ($a['id_boleto']): ?>
              <span class="text-muted small" title="El pago real se calcula y se paga a nivel de boleto, ver tabla de arriba.">— (ver boleto)</span>
            <?php else: ?>
              <?= money($a['ganancia_potencial']) ?>
            <?php endif; ?>
          </td>
          <td><?= badge_estado($a['estado']) ?></td>
          <td class="small text-muted"><?= fecha_legible($a['created_at']) ?></td>
          <td class="text-end">
            <?php if ($a['id_boleto']): ?>
              <span class="text-muted small">Parte de boleto</span>
            <?php elseif ($a['estado'] === 'pendiente' && $a['estado_partido'] === 'programado'): ?>
              <form method="post" class="d-inline" data-confirm="¿Estás seguro de cancelar esta apuesta de <?= money($a['monto']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="cancelar_apuesta">
                <input type="hidden" name="id_apuesta" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" data-cy="btn-cancelar-apuesta">
                  <i class="bi bi-x-circle"></i> Cancelar
                </button>
              </form>
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
