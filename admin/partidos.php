<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$estado_filtro = $_GET['estado'] ?? 'programado';
$estados_validos = ['programado', 'en_curso', 'finalizado', 'pospuesto', 'cancelado', 'suspendido', ''];
if (!in_array($estado_filtro, $estados_validos, true)) {
    $estado_filtro = 'programado';
}

$sql = "SELECT p.*, d.nombre AS deporte, lt.nombre AS liga,
               el.nombre AS equipo_local, ev.nombre AS equipo_visita
        FROM partidos p
        JOIN deportes d ON d.id = p.id_deporte
        LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
        JOIN equipos el ON el.id = p.id_equipo_local
        JOIN equipos ev ON ev.id = p.id_equipo_visita";
$params = [];
if ($estado_filtro !== '') {
    $sql .= ' WHERE p.estado = ?';
    $params[] = $estado_filtro;
}
$sql .= ' ORDER BY p.fecha_partido DESC LIMIT 100';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$partidos = $stmt->fetchAll();

$vencidosCount = (int) db()->query("SELECT COUNT(*) FROM partidos WHERE estado = 'programado' AND fecha_partido <= UTC_TIMESTAMP()")->fetchColumn();

$titulo_pagina = 'Admin · Partidos';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="bi bi-flag"></i> Partidos</h1>
  <div class="d-flex gap-2 align-items-center">
    <?php if ($vencidosCount > 0): ?>
      <a href="<?= APP_URL ?>/admin/partidos_vencidos.php" class="btn btn-outline-danger btn-sm fw-bold">
        <i class="bi bi-exclamation-triangle-fill"></i> Vencidos (<?= $vencidosCount ?>)
      </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/admin/refrescar.php" class="btn btn-warning btn-sm fw-bold">
      <i class="bi bi-arrow-repeat"></i> Refrescar partidos (ESPN)
    </a>
    <form method="get" class="d-flex gap-2">
      <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach (['' => 'Todos', 'programado' => 'Programados', 'en_curso' => 'En curso', 'finalizado' => 'Finalizados', 'pospuesto' => 'Pospuestos', 'cancelado' => 'Cancelados', 'suspendido' => 'Suspendidos'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= $estado_filtro === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<div class="alert alert-info small">
  <i class="bi bi-info-circle"></i> Al guardar un resultado y marcar el partido como <strong>finalizado</strong>,
  la base de datos liquida las apuestas automáticamente (trigger + <code>sp_resolver_partido</code>): no hace falta hacer nada más.
</div>

<?php if (!$partidos): ?>
  <div class="alert alert-secondary">No hay partidos con ese filtro.</div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-hover bg-white align-middle">
    <thead class="table-dark">
      <tr>
        <th>Partido</th>
        <th>Liga</th>
        <th>Fecha</th>
        <th>Estado</th>
        <th>Marcador</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($partidos as $p): ?>
        <tr>
          <td>
            <span class="badge bg-dark badge-deporte me-1"><?= e($p['deporte']) ?></span>
            <?= e($p['equipo_local']) ?> vs <?= e($p['equipo_visita']) ?>
          </td>
          <td class="small text-muted"><?= e($p['liga'] ?? 'Amistoso') ?></td>
          <td class="small"><?= fecha_partido_legible($p['fecha_partido']) ?></td>
          <td><?= badge_estado($p['estado']) ?></td>
          <td><?= $p['marcador_local'] !== null ? (int) $p['marcador_local'] . ' - ' . (int) $p['marcador_visita'] : '—' ?></td>
          <td><a href="<?= APP_URL ?>/admin/partido_editar.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-dark">Editar</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
