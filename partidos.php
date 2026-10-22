<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/calendario.php';
require_once __DIR__ . '/includes/live_data.php';
requerir_login();

$pdo = db();
$uid = (int) usuario_actual()['id'];

// ---- Vista: calendario (con equipo favorito) o lista clásica ----
$listo = cal_tablas_listas($pdo);
$vista = 'lista';
if ($listo) {
    $prefs = cal_preferencias($pdo, $uid);
    $vista = (string) ($_GET['vista'] ?? $prefs['vista_partidos']);
    if (!in_array($vista, ['calendario', 'lista'], true)) {
        $vista = 'calendario';
    }
    if (isset($_GET['vista']) && $vista !== $prefs['vista_partidos']) {
        cal_guardar_preferencia($pdo, $uid, 'vista_partidos', $vista);
    }
}

if ($vista === 'calendario') {
    $titulo_pagina = 'Partidos';
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/vista_calendario.php';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ---- Lista clásica (con apuestas) ----
$deportes = db()->query('SELECT * FROM deportes ORDER BY nombre')->fetchAll();

$id_deporte = isset($_GET['deporte']) && $_GET['deporte'] !== '' ? (int) $_GET['deporte'] : null;
$id_liga    = isset($_GET['liga']) && $_GET['liga'] !== '' ? (int) $_GET['liga'] : null;
$estado     = $_GET['estado'] ?? '';

$ligas = [];
if ($id_deporte) {
    $stmt = db()->prepare('SELECT id, nombre, temporada FROM ligas_torneos WHERE id_deporte = ? ORDER BY nombre');
    $stmt->execute([$id_deporte]);
    $ligas = $stmt->fetchAll();
}

$sql = 'SELECT p.*, d.nombre AS deporte, d.slug AS deporte_slug, lt.nombre AS liga,
               el.nombre AS equipo_local, el.escudo AS escudo_local,
               ev.nombre AS equipo_visita, ev.escudo AS escudo_visita
        FROM partidos p
        JOIN deportes d ON d.id = p.id_deporte
        LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
        JOIN equipos el ON el.id = p.id_equipo_local
        JOIN equipos ev ON ev.id = p.id_equipo_visita
        WHERE 1=1';
$params = [];

if ($id_deporte) {
    $sql .= ' AND p.id_deporte = ?';
    $params[] = $id_deporte;
}
if ($id_liga) {
    $sql .= ' AND p.id_liga = ?';
    $params[] = $id_liga;
}
if ($estado !== '' && in_array($estado, ['programado', 'en_curso', 'finalizado', 'pospuesto', 'cancelado', 'suspendido'], true)) {
    $sql .= ' AND p.estado = ?';
    $params[] = $estado;
}

// Primero los próximos partidos (del más cercano al más lejano), después el resto.
$sql .= " ORDER BY (p.estado = 'programado' AND p.fecha_partido > UTC_TIMESTAMP()) DESC,
          CASE WHEN p.estado = 'programado' AND p.fecha_partido > UTC_TIMESTAMP() THEN p.fecha_partido END ASC,
          p.fecha_partido DESC LIMIT 100";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$partidos = $stmt->fetchAll();

$titulo_pagina = 'Partidos';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <h1 class="h3 mb-0"><i class="bi bi-calendar-event"></i> Partidos</h1>
  <?php if ($listo): ?>
    <div class="cal-toggle" role="group" aria-label="Vista">
      <a href="?vista=calendario" class="cal-toggle-btn"><i class="bi bi-calendar3"></i> Calendario</a>
      <a href="?vista=lista" class="cal-toggle-btn active"><i class="bi bi-list-ul"></i> Lista</a>
    </div>
  <?php endif; ?>
</div>

<?php if (!$listo && es_admin()): ?>
  <div class="alert alert-info small">
    <i class="bi bi-info-circle"></i> Para activar el <strong>calendario con equipo favorito</strong>, ejecutá una vez
    <code>database/favoritos.sql</code> en phpMyAdmin.
  </div>
<?php endif; ?>

<form method="get" class="row g-2 mb-4 align-items-end">
  <input type="hidden" name="vista" value="lista">
  <div class="col-sm-3">
    <label class="form-label small">Deporte</label>
    <select name="deporte" class="form-select" onchange="this.form.submit()">
      <option value="">Todos</option>
      <?php foreach ($deportes as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= $id_deporte === (int) $d['id'] ? 'selected' : '' ?>>
          <?= e($d['nombre']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-3">
    <label class="form-label small">Liga / torneo</label>
    <select name="liga" class="form-select" <?= $ligas ? '' : 'disabled' ?>>
      <option value="">Todas</option>
      <?php foreach ($ligas as $l): ?>
        <option value="<?= (int) $l['id'] ?>" <?= $id_liga === (int) $l['id'] ? 'selected' : '' ?>>
          <?= e($l['nombre']) ?> <?= $l['temporada'] ? '(' . e($l['temporada']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-3">
    <label class="form-label small">Estado</label>
    <select name="estado" class="form-select">
      <option value="">Todos</option>
      <?php foreach (['programado', 'en_curso', 'finalizado', 'pospuesto', 'cancelado', 'suspendido'] as $es): ?>
        <option value="<?= $es ?>" <?= $estado === $es ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $es)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-3">
    <button class="btn btn-dark w-100" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
  </div>
</form>

<p class="small text-muted mb-2"><i class="bi bi-clock"></i> Horarios en hora de Honduras (<?= e(app_tz_etiqueta()) ?>).</p>

<?php if (!$partidos): ?>
  <div class="alert alert-secondary">No hay partidos con esos filtros.</div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($partidos as $p): ?>
    <?php $abierto = $p['estado'] === 'programado' && partido_futuro($p['fecha_partido']); ?>
    <div class="col-md-6">
      <div class="card card-partido match-card h-100" <?= live_attrs($p) ?>>
        <div class="card-body">
          <div class="match-top">
            <span class="match-liga"><span class="badge bg-dark badge-deporte"><?= e($p['deporte']) ?></span> <?= e($p['liga'] ?? 'Amistoso') ?></span>
            <span data-live="badge"><?= $p['estado'] === 'en_curso' ? badge_en_vivo($p['minuto_actual'] ?? null) : badge_estado($p['estado']) ?></span>
          </div>

          <div class="match-teams">
            <div class="team">
              <?= escudo_equipo($p['escudo_local'], $p['equipo_local'], 'lg') ?>
              <span class="team-name"><?= e($p['equipo_local']) ?></span>
            </div>
            <div class="match-center">
              <?php if ($p['estado'] === 'finalizado' || ($p['estado'] === 'en_curso' && $p['marcador_local'] !== null)): ?>
                <span class="match-score" data-live="score"><?= (int) $p['marcador_local'] ?> - <?= (int) $p['marcador_visita'] ?></span>
              <?php else: ?>
                <span class="match-vs" data-live="score">VS</span>
              <?php endif; ?>
              <span class="match-date"><?= fecha_partido_legible($p['fecha_partido'], 'd/m/Y') ?></span>
              <span class="match-time"><?= fecha_partido_legible($p['fecha_partido'], 'h:i A') ?></span>
            </div>
            <div class="team">
              <?= escudo_equipo($p['escudo_visita'], $p['equipo_visita'], 'lg') ?>
              <span class="team-name"><?= e($p['equipo_visita']) ?></span>
            </div>
          </div>

          <?php if ($p['estado'] === 'programado'): ?>
            <div class="odds-row <?= $p['cuota_empate'] !== null ? 'odds-3' : 'odds-2' ?>">
              <div class="odd"><small>Local</small><strong><?= e($p['cuota_local']) ?></strong></div>
              <?php if ($p['cuota_empate'] !== null): ?>
                <div class="odd"><small>Empate</small><strong><?= e($p['cuota_empate']) ?></strong></div>
              <?php endif; ?>
              <div class="odd"><small>Visita</small><strong><?= e($p['cuota_visita']) ?></strong></div>
            </div>
            <?php if ($abierto): ?>
              <a href="<?= APP_URL ?>/apostar.php?partido=<?= (int) $p['id'] ?>" class="btn btn-warning btn-sm w-100 fw-semibold">
                <i class="bi bi-cash-coin"></i> Apostar
              </a>
            <?php else: ?>
              <button class="btn btn-secondary btn-sm w-100" disabled>
                <i class="bi bi-lock-fill"></i> Cerrado para apuestas (en curso)
              </button>
            <?php endif; ?>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/partido.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">
            <i class="bi bi-info-circle"></i> Detalle del partido
          </a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>