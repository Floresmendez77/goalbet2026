<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$pdo = db();

$totales = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM usuarios WHERE rol = 'cliente') AS usuarios,
        (SELECT COUNT(*) FROM partidos WHERE estado = 'programado') AS partidos_programados,
        (SELECT COUNT(*) FROM partidos WHERE estado = 'programado' AND fecha_partido <= UTC_TIMESTAMP()) AS partidos_vencidos,
        (SELECT COUNT(*) FROM apuestas WHERE estado = 'pendiente') AS apuestas_pendientes,
        (SELECT COUNT(*) FROM recargas WHERE estado = 'pendiente') AS recargas_pendientes,
        (SELECT COALESCE(SUM(monto), 0) FROM apuestas) AS total_apostado,
        (SELECT COALESCE(SUM(ganancia_potencial), 0) FROM apuestas WHERE estado IN ('ganada','pagada')) AS total_ganancias_pagadas,
        (SELECT COALESCE(SUM(monto * cuota), 0) FROM apuestas WHERE estado = 'pendiente' AND id_boleto IS NULL) +
        (SELECT COALESCE(SUM(monto * cuota_total), 0) FROM boletos WHERE estado = 'pendiente') AS pasivo_pendiente"
)->fetch();

$balanceCasa = (float) $totales['total_apostado'] - (float) $totales['total_ganancias_pagadas'];

$titulo_pagina = 'Panel admin';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="bi bi-shield-lock"></i> Panel de administración</h1>
  <?php if ((int) $totales['partidos_vencidos'] > 0): ?>
    <a href="<?= APP_URL ?>/admin/partidos_vencidos.php" class="btn btn-danger btn-sm fw-bold">
      <i class="bi bi-exclamation-triangle-fill"></i> <?= (int) $totales['partidos_vencidos'] ?> partidos vencidos sin resultado
    </a>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body">
        <div class="text-muted small">Clientes registrados</div>
        <div class="fs-4 fw-bold"><?= (int) $totales['usuarios'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body">
        <div class="text-muted small">Partidos programados</div>
        <div class="fs-4 fw-bold"><?= (int) $totales['partidos_programados'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100 border-warning">
      <div class="card-body">
        <div class="text-muted small">Apuestas pendientes</div>
        <div class="fs-4 fw-bold"><?= (int) $totales['apuestas_pendientes'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100 border-danger">
      <div class="card-body">
        <div class="text-muted small">Recargas pendientes</div>
        <div class="fs-4 fw-bold"><?= (int) $totales['recargas_pendientes'] ?></div>
        <?php if ((int) $totales['recargas_pendientes'] > 0): ?>
          <a href="<?= APP_URL ?>/admin/recargas.php" class="small">Revisar →</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Total apostado histórico</div>
      <div class="fs-5 fw-bold"><?= money($totales['total_apostado']) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Ganancias pagadas</div>
      <div class="fs-5 fw-bold text-danger"><?= money($totales['total_ganancias_pagadas']) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100 border-warning"><div class="card-body">
      <div class="text-muted small">Pasivo pendiente (riesgo)</div>
      <div class="fs-5 fw-bold text-warning"><?= money($totales['pasivo_pendiente']) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Balance casa neto (aprox.)</div>
      <div class="fs-5 fw-bold text-success"><?= money($balanceCasa) ?></div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6 col-lg-3">
    <a href="<?= APP_URL ?>/admin/refrescar.php" class="btn btn-warning w-100 py-3 text-dark fw-bold"><i class="bi bi-arrow-repeat fs-4"></i><br>Refrescar partidos</a>
  </div>
  <div class="col-md-6 col-lg-3">
    <a href="<?= APP_URL ?>/admin/partidos.php" class="btn btn-dark w-100 py-3"><i class="bi bi-flag fs-4"></i><br>Partidos</a>
  </div>
  <div class="col-md-4 col-lg-2">
    <a href="<?= APP_URL ?>/admin/usuarios.php" class="btn btn-dark w-100 py-3"><i class="bi bi-people fs-4"></i><br>Usuarios</a>
  </div>
  <div class="col-md-4 col-lg-2">
    <a href="<?= APP_URL ?>/admin/recargas.php" class="btn btn-dark w-100 py-3"><i class="bi bi-wallet2 fs-4"></i><br>Recargas</a>
  </div>
  <div class="col-md-4 col-lg-2">
    <a href="<?= APP_URL ?>/admin/reportes.php" class="btn btn-dark w-100 py-3"><i class="bi bi-bar-chart fs-4"></i><br>Reportes</a>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
