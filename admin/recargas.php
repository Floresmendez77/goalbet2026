<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$pdo = db();
$admin = usuario_actual();
$estado_filtro = $_GET['estado'] ?? 'pendiente';
if (!in_array($estado_filtro, ['pendiente', 'aprobada', 'rechazada', ''], true)) {
    $estado_filtro = 'pendiente';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $accion = $_POST['accion'] ?? '';
    $id_recarga = (int) ($_POST['id_recarga'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM recargas WHERE id = ?');
    $stmt->execute([$id_recarga]);
    $recarga = $stmt->fetch();

    if (!$recarga) {
        flash('danger', 'Solicitud no encontrada.');
        redirect('/admin/recargas.php');
    }
    if ($recarga['estado'] !== 'pendiente') {
        flash('warning', 'Esa solicitud ya fue procesada.');
        redirect('/admin/recargas.php');
    }

    $correoPendiente = null; // se envía DESPUÉS de confirmar la operación en BD

    if ($accion === 'aprobar') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT saldo FROM usuarios WHERE id = ? FOR UPDATE');
            $stmt->execute([$recarga['id_usuario']]);
            $saldo_anterior = (float) $stmt->fetchColumn();
            $saldo_nuevo = $saldo_anterior + (float) $recarga['monto'];

            $pdo->prepare('UPDATE usuarios SET saldo = ? WHERE id = ?')
                ->execute([$saldo_nuevo, $recarga['id_usuario']]);

            $ref_tx = generar_referencia('DEP');
            $pdo->prepare(
                'INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
                 VALUES (?, "deposito", ?, ?, ?, ?, ?)'
            )->execute([
                $recarga['id_usuario'], $recarga['monto'], $saldo_anterior, $saldo_nuevo, $ref_tx,
                'Recarga aprobada ' . $recarga['referencia'] . ' (' . $recarga['metodo'] . ')',
            ]);

            $pdo->prepare(
                'UPDATE recargas SET estado = "aprobada", id_admin = ?, procesada_at = NOW() WHERE id = ?'
            )->execute([$admin['id'], $id_recarga]);

            $pdo->commit();
            flash('success', 'Recarga aprobada y saldo acreditado.');
            $correoPendiente = ['tipo' => 'aprobada', 'saldo_nuevo' => $saldo_nuevo, 'motivo' => ''];
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('danger', 'No se pudo aprobar la recarga.');
        }
    }

    if ($accion === 'rechazar') {
        $motivo = trim($_POST['motivo_rechazo'] ?? '');
        $pdo->prepare(
            'UPDATE recargas SET estado = "rechazada", id_admin = ?, motivo_rechazo = ?, procesada_at = NOW() WHERE id = ?'
        )->execute([$admin['id'], $motivo ?: 'Sin motivo especificado', $id_recarga]);
        flash('success', 'Recarga rechazada.');
        $correoPendiente = ['tipo' => 'rechazada', 'saldo_nuevo' => 0.0, 'motivo' => $motivo ?: 'Sin motivo especificado'];
    }

    // Aviso por correo al usuario (si falla el SMTP la operación ya quedó registrada igual).
    if ($correoPendiente !== null) {
        $stmtU = $pdo->prepare('SELECT nombre, username, email FROM usuarios WHERE id = ?');
        $stmtU->execute([$recarga['id_usuario']]);
        $dest = $stmtU->fetch();
        if ($dest) {
            $ok = $correoPendiente['tipo'] === 'aprobada'
                ? correo_recarga_aprobada($dest, $recarga, (float) $correoPendiente['saldo_nuevo'])
                : correo_recarga_rechazada($dest, $recarga, (string) $correoPendiente['motivo']);
            if (!$ok) {
                flash('warning', 'La operación se registró, pero no se pudo enviar el correo de aviso al usuario.');
            }
        }
    }

    redirect('/admin/recargas.php' . ($estado_filtro ? '?estado=' . $estado_filtro : ''));
}

$sql = "SELECT r.*, u.username, u.nombre, u.apellido
        FROM recargas r JOIN usuarios u ON u.id = r.id_usuario";
$params = [];
if ($estado_filtro !== '') {
    $sql .= ' WHERE r.estado = ?';
    $params[] = $estado_filtro;
}
$sql .= ' ORDER BY r.created_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recargas = $stmt->fetchAll();

$titulo_pagina = 'Admin · Recargas';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="bi bi-wallet2"></i> Solicitudes de recarga</h1>
  <form method="get" class="d-flex gap-2">
    <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach (['pendiente' => 'Pendientes', 'aprobada' => 'Aprobadas', 'rechazada' => 'Rechazadas', '' => 'Todas'] as $val => $label): ?>
        <option value="<?= $val ?>" <?= $estado_filtro === $val ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$recargas): ?>
  <div class="empty-state">
  <div class="empty-icon"><i class="bi bi-inbox"></i></div>
  <h3 class="empty-title">No hay solicitudes con ese filtro.</h3>
  <p>Cuando haya datos para mostrar, van a aparecer acá.</p>
</div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($recargas as $r): ?>
    <div class="col-md-6">
      <div class="card h-100" data-cy="card-recarga">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fw-semibold"><?= e($r['nombre'] . ' ' . $r['apellido']) ?> <span class="text-muted small">@<?= e($r['username']) ?></span></div>
              <div class="fs-5 fw-bold"><?= money($r['monto']) ?></div>
            </div>
            <?= badge_estado($r['estado']) ?>
          </div>
          <div class="small text-muted mt-2">
            Método: <?= e($r['metodo']) ?><br>
            <?php if ($r['comprobante']): ?>Comprobante: <?= e($r['comprobante']) ?><br><?php endif; ?>
            Referencia: <span class="font-monospace"><?= e($r['referencia']) ?></span><br>
            Solicitada: <?= fecha_legible($r['created_at']) ?>
            <?php if ($r['procesada_at']): ?><br>Procesada: <?= fecha_legible($r['procesada_at']) ?><?php endif; ?>
          </div>

          <?php if ($r['estado'] === 'pendiente'): ?>
            <div class="d-flex gap-2 mt-3">
              <form method="post" data-confirm="¿Aprobar esta recarga y acreditar <?= money($r['monto']) ?>?">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="aprobar">
                <input type="hidden" name="id_recarga" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm" data-cy="btn-aprobar-recarga"><i class="bi bi-check-lg"></i> Aprobar</button>
              </form>
              <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rechazoModal<?= (int) $r['id'] ?>">
                <i class="bi bi-x-lg"></i> Rechazar
              </button>
            </div>
          <?php elseif ($r['estado'] === 'rechazada' && $r['motivo_rechazo']): ?>
            <div class="alert alert-danger small mt-3 mb-0">Motivo: <?= e($r['motivo_rechazo']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="modal fade" id="rechazoModal<?= (int) $r['id'] ?>" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="rechazar">
            <input type="hidden" name="id_recarga" value="<?= (int) $r['id'] ?>">
            <div class="modal-header">
              <h5 class="modal-title">Rechazar recarga de @<?= e($r['username']) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <label class="form-label">Motivo del rechazo</label>
              <textarea name="motivo_rechazo" class="form-control" rows="3" placeholder="Ej: comprobante no válido"></textarea>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-danger">Rechazar solicitud</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
