<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$pdo = db();

// Manejo de acciones POST (cancelar, posponer, finalizar con resultado)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id_partido'] ?? 0);

    if ($id <= 0) {
        flash('danger', 'ID de partido inválido.');
        redirect('/admin/partidos_vencidos.php');
    }

    if ($accion === 'cancelar') {
        try {
            $pdo->prepare("UPDATE partidos SET estado = 'cancelado' WHERE id = ? AND estado = 'programado'")->execute([$id]);
            flash('success', "Partido #$id marcado como cancelado. Las apuestas pendientes se reembolsaron automáticamente vía trigger.");
        } catch (Throwable $e) {
            flash('danger', 'Error al cancelar partido: ' . $e->getMessage());
        }
        redirect('/admin/partidos_vencidos.php');
    }

    if ($accion === 'posponer') {
        try {
            $pdo->prepare("UPDATE partidos SET estado = 'pospuesto' WHERE id = ? AND estado = 'programado'")->execute([$id]);
            flash('success', "Partido #$id marcado como pospuesto. Las apuestas se reembolsaron vía trigger.");
        } catch (Throwable $e) {
            flash('danger', 'Error al posponer partido: ' . $e->getMessage());
        }
        redirect('/admin/partidos_vencidos.php');
    }

    if ($accion === 'finalizar_resultado') {
        $ml_raw = trim((string) ($_POST['marcador_local'] ?? ''));
        $mv_raw = trim((string) ($_POST['marcador_visita'] ?? ''));

        if ($ml_raw === '' || $mv_raw === '') {
            flash('danger', 'Debés ingresar ambos marcadores para finalizar el partido.');
            redirect('/admin/partidos_vencidos.php');
        }

        $ml = (int) $ml_raw;
        $mv = (int) $mv_raw;

        if ($ml < 0 || $mv < 0) {
            flash('danger', 'Los marcadores no pueden ser negativos.');
            redirect('/admin/partidos_vencidos.php');
        }

        try {
            $pdo->prepare(
                "UPDATE partidos SET marcador_local = ?, marcador_visita = ?, estado = 'finalizado' WHERE id = ? AND estado = 'programado'"
            )->execute([$ml, $mv, $id]);
            flash('success', "Partido #$id finalizado ($ml - $mv). Apuestas liquidadas automáticamente vía trigger.");
        } catch (Throwable $e) {
            flash('danger', 'Error al finalizar partido: ' . $e->getMessage());
        }
        redirect('/admin/partidos_vencidos.php');
    }
}

// Consultar partidos vencidos aún 'programados'
$stmt = $pdo->query(
    "SELECT p.*, d.nombre AS deporte, lt.nombre AS liga,
            el.nombre AS equipo_local, ev.nombre AS equipo_visita,
            (SELECT COUNT(*) FROM apuestas a WHERE a.id_partido = p.id AND a.estado = 'pendiente') AS apuestas_pendientes,
            (SELECT COALESCE(SUM(a.monto), 0) FROM apuestas a WHERE a.id_partido = p.id AND a.estado = 'pendiente') AS monto_pendiente
     FROM partidos p
     JOIN deportes d ON d.id = p.id_deporte
     LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
     JOIN equipos el ON el.id = p.id_equipo_local
     JOIN equipos ev ON ev.id = p.id_equipo_visita
     WHERE p.estado = 'programado' AND p.fecha_partido <= UTC_TIMESTAMP()
     ORDER BY p.fecha_partido ASC"
);
$partidosVencidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$titulo_pagina = 'Admin · Partidos vencidos sin resultado';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-exclamation-triangle-fill text-warning"></i> Partidos Vencidos sin Resultado</h1>
    <p class="text-muted small mb-0">Partidos cuya fecha ya pasó pero siguen en estado 'programado' (requieren resultado o cancelación).</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/admin/refrescar.php" class="btn btn-warning btn-sm fw-bold">
      <i class="bi bi-arrow-repeat"></i> Intentar auto-sync ESPN
    </a>
    <a href="<?= APP_URL ?>/admin/partidos.php" class="btn btn-outline-light btn-sm">
      <i class="bi bi-arrow-left"></i> Volver a partidos
    </a>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold">Total pendientes de resolución: <?= count($partidosVencidos) ?></span>
    <span class="small text-muted">Las acciones disparan automáticamente la liquidación o reembolso en la BD</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($partidosVencidos)): ?>
      <div class="alert alert-success m-3 mb-3">
        <i class="bi bi-check-circle-fill"></i> ¡Excelente! No hay partidos vencidos pendientes de resolución.
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-dark small">
            <tr>
              <th>ID</th>
              <th>Partido</th>
              <th>Liga</th>
              <th>Fecha programada</th>
              <th>Apuestas</th>
              <th style="min-width: 280px;">Acción de reparación</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($partidosVencidos as $p): ?>
              <tr>
                <td><strong>#<?= (int) $p['id'] ?></strong></td>
                <td>
                  <span class="badge bg-dark badge-deporte me-1"><?= e($p['deporte']) ?></span>
                  <strong><?= e($p['equipo_local']) ?> vs <?= e($p['equipo_visita']) ?></strong>
                </td>
                <td class="small text-muted"><?= e($p['liga'] ?? 'Amistoso') ?></td>
                <td class="text-danger small fw-semibold">
                  <?= fecha_partido_legible($p['fecha_partido']) ?>
                </td>
                <td>
                  <?php if ((int) $p['apuestas_pendientes'] > 0): ?>
                    <span class="badge bg-warning text-dark"><?= (int) $p['apuestas_pendientes'] ?> pend.</span><br>
                    <small class="text-muted"><?= money($p['monto_pendiente']) ?></small>
                  <?php else: ?>
                    <span class="text-muted small">0</span>
                  <?php endif; ?>
                </td>
                <td>
                  <!-- Formulario rápido de resultado -->
                  <form method="post" class="d-flex align-items-center gap-1 mb-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_partido" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="accion" value="finalizar_resultado">
                    <input type="number" name="marcador_local" class="form-control form-control-sm text-center" style="width: 50px;" min="0" placeholder="L" required>
                    <span>-</span>
                    <input type="number" name="marcador_visita" class="form-control form-control-sm text-center" style="width: 50px;" min="0" placeholder="V" required>
                    <button type="submit" class="btn btn-sm btn-success text-nowrap" title="Finalizar con resultado">
                      <i class="bi bi-check-lg"></i> Finalizar
                    </button>
                  </form>

                  <!-- Botones de cancelar o posponer -->
                  <div class="d-flex gap-1">
                    <form method="post" onsubmit="return confirm('¿Confirmás cancelar este partido? Se reembolsarán las apuestas.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id_partido" value="<?= (int) $p['id'] ?>">
                      <input type="hidden" name="accion" value="cancelar">
                      <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2 small">
                        <i class="bi bi-x-circle"></i> Cancelar
                      </button>
                    </form>
                    <form method="post" onsubmit="return confirm('¿Confirmás posponer este partido? Se reembolsarán las apuestas.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id_partido" value="<?= (int) $p['id'] ?>">
                      <input type="hidden" name="accion" value="posponer">
                      <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2 small">
                        <i class="bi bi-clock"></i> Posponer
                      </button>
                    </form>
                    <a href="<?= APP_URL ?>/admin/partido_editar.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-dark py-0 px-2 small">
                      Detalle
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
