<?php
require_once __DIR__ . '/includes/bootstrap.php';
requerir_login();
refrescar_usuario_sesion();

$usuario = usuario_actual();

$proximos = db()->query(
    "SELECT p.id, p.fecha_partido, d.nombre AS deporte, lt.nombre AS liga,
            el.nombre AS equipo_local, el.escudo AS escudo_local,
            ev.nombre AS equipo_visita, ev.escudo AS escudo_visita
     FROM partidos p
     JOIN deportes d ON d.id = p.id_deporte
     LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
     JOIN equipos el ON el.id = p.id_equipo_local
     JOIN equipos ev ON ev.id = p.id_equipo_visita
     WHERE p.estado = 'programado' AND p.fecha_partido > UTC_TIMESTAMP()
     ORDER BY p.fecha_partido ASC
     LIMIT 6"
)->fetchAll();

$stmt = db()->prepare(
    'SELECT a.*, el.nombre AS equipo_local, ev.nombre AS equipo_visita, p.fecha_partido, p.estado AS estado_partido
     FROM apuestas a
     JOIN partidos p ON p.id = a.id_partido
     JOIN equipos el ON el.id = p.id_equipo_local
     JOIN equipos ev ON ev.id = p.id_equipo_visita
     WHERE a.id_usuario = ?
     ORDER BY a.created_at DESC
     LIMIT 5'
);
$stmt->execute([$usuario['id']]);
$ultimas_apuestas = $stmt->fetchAll();

$resumen = db()->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN estado = 'ganada' THEN 1 ELSE 0 END) AS ganadas,
        SUM(monto) AS total_apostado
     FROM apuestas WHERE id_usuario = ?"
);
$resumen->execute([$usuario['id']]);
$resumen = $resumen->fetch();

$titulo_pagina = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0">Hola, <?= e($usuario['nombre']) ?> 👋</h1>
  <a href="<?= APP_URL ?>/recargar.php" class="btn btn-warning"><i class="bi bi-wallet2"></i> Recargar saldo</a>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body">
        <div class="text-muted small">Saldo disponible</div>
        <div class="fs-4 fw-bold"><?= money($usuario['saldo']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body">
        <div class="text-muted small">Apuestas totales</div>
        <div class="fs-4 fw-bold"><?= (int) $resumen['total'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body">
        <div class="text-muted small">Pendientes</div>
        <div class="fs-4 fw-bold"><?= (int) $resumen['pendientes'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat-card h-100">
      <div class="card-body">
        <div class="text-muted small">Ganadas</div>
        <div class="fs-4 fw-bold text-success"><?= (int) $resumen['ganadas'] ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h5 mb-0"><i class="bi bi-calendar-event"></i> Próximos partidos</h2>
      <a href="<?= APP_URL ?>/partidos.php" class="small">Ver todos →</a>
    </div>
    <?php if (!$proximos): ?>
      <div class="alert alert-secondary">No hay partidos programados por ahora.</div>
    <?php endif; ?>
    <?php foreach ($proximos as $p): ?>
      <div class="card card-partido mb-2">
        <div class="card-body py-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <span class="badge bg-dark badge-deporte"><?= e($p['deporte']) ?></span>
              <span class="text-muted small ms-1"><?= e($p['liga'] ?? 'Amistoso') ?></span>
              <div class="fw-semibold d-flex align-items-center gap-2 mt-1">
                <?= escudo_equipo($p['escudo_local'], $p['equipo_local'], 'sm') ?> <?= e($p['equipo_local']) ?>
                <span class="text-muted small">vs</span>
                <?= escudo_equipo($p['escudo_visita'], $p['equipo_visita'], 'sm') ?> <?= e($p['equipo_visita']) ?>
              </div>
              <div class="text-muted small"><?= fecha_partido_legible($p['fecha_partido']) ?></div>
            </div>
            <a href="<?= APP_URL ?>/apostar.php?partido=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-warning">Apostar</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="col-lg-5">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h2 class="h5 mb-0"><i class="bi bi-receipt"></i> Últimas apuestas</h2>
      <a href="<?= APP_URL ?>/mis_apuestas.php" class="small">Ver todas →</a>
    </div>
    <?php if (!$ultimas_apuestas): ?>
      <div class="alert alert-secondary">Todavía no hiciste ninguna apuesta.</div>
    <?php endif; ?>
    <div class="list-group">
      <?php foreach ($ultimas_apuestas as $a): ?>
        <div class="list-group-item">
          <div class="d-flex justify-content-between">
            <span class="fw-semibold"><?= e($a['equipo_local']) ?> vs <?= e($a['equipo_visita']) ?></span>
            <?= badge_estado($a['estado']) ?>
          </div>
          <div class="small text-muted">
            Apostaste a <strong><?= e(ucfirst($a['tipo_apuesta'])) ?></strong> ·
            <?= money($a['monto']) ?> a cuota <?= e($a['cuota']) ?>
            · posible ganancia <?= money($a['ganancia_potencial']) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
