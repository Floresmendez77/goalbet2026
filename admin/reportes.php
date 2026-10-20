<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$pdo = db();

$por_deporte = $pdo->query(
    "SELECT d.nombre AS deporte, COUNT(a.id) AS total_apuestas, COALESCE(SUM(a.monto),0) AS total_monto
     FROM apuestas a
     JOIN partidos p ON p.id = a.id_partido
     JOIN deportes d ON d.id = p.id_deporte
     GROUP BY d.nombre
     ORDER BY total_monto DESC"
)->fetchAll();

$top_usuarios = $pdo->query(
    "SELECT u.username, u.nombre, u.apellido, COUNT(a.id) AS total_apuestas, COALESCE(SUM(a.monto),0) AS total_apostado
     FROM apuestas a JOIN usuarios u ON u.id = a.id_usuario
     GROUP BY u.id
     ORDER BY total_apostado DESC
     LIMIT 10"
)->fetchAll();

$transacciones = $pdo->query(
    "SELECT t.*, u.username FROM transacciones t JOIN usuarios u ON u.id = t.id_usuario
     ORDER BY t.created_at DESC LIMIT 30"
)->fetchAll();

$titulo_pagina = 'Admin · Reportes';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="h3 mb-4"><i class="bi bi-bar-chart"></i> Reportes</h1>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <h2 class="h5 mb-3">Apuestas por deporte</h2>
    <?php if (!$por_deporte): ?>
      <div class="alert alert-secondary">Todavía no hay apuestas registradas.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm bg-white table-hover">
          <thead class="table-dark"><tr><th>Deporte</th><th class="text-center">Apuestas</th><th class="text-end">Monto total</th></tr></thead>
          <tbody>
            <?php foreach ($por_deporte as $d): ?>
              <tr>
                <td><?= e($d['deporte']) ?></td>
                <td class="text-center"><?= (int) $d['total_apuestas'] ?></td>
                <td class="text-end"><?= money($d['total_monto']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <h2 class="h5 mb-3">Top 10 clientes por monto apostado</h2>
    <?php if (!$top_usuarios): ?>
      <div class="alert alert-secondary">Todavía no hay apuestas registradas.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm bg-white table-hover">
          <thead class="table-dark"><tr><th>Usuario</th><th class="text-center">Apuestas</th><th class="text-end">Total apostado</th></tr></thead>
          <tbody>
            <?php foreach ($top_usuarios as $u): ?>
              <tr>
                <td><?= e($u['nombre'] . ' ' . $u['apellido']) ?> <span class="text-muted small">@<?= e($u['username']) ?></span></td>
                <td class="text-center"><?= (int) $u['total_apuestas'] ?></td>
                <td class="text-end"><?= money($u['total_apostado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<h2 class="h5 mb-3">Últimas transacciones (todas las cuentas)</h2>
<?php if (!$transacciones): ?>
  <div class="alert alert-secondary">Todavía no hay transacciones.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover bg-white">
      <thead class="table-dark">
        <tr><th>Fecha</th><th>Usuario</th><th>Tipo</th><th class="text-end">Monto</th><th class="text-end">Saldo resultante</th><th>Referencia</th></tr>
      </thead>
      <tbody>
        <?php foreach ($transacciones as $t): ?>
          <tr>
            <td class="small"><?= fecha_legible($t['created_at']) ?></td>
            <td>@<?= e($t['username']) ?></td>
            <td><?= e(ucfirst(str_replace('_', ' ', $t['tipo']))) ?></td>
            <td class="text-end <?= $t['monto'] < 0 || $t['tipo'] === 'apuesta' || $t['tipo'] === 'retiro' ? 'text-danger' : 'text-success' ?>">
              <?= money($t['monto']) ?>
            </td>
            <td class="text-end"><?= money($t['saldo_nuevo']) ?></td>
            <td class="small font-monospace"><?= e($t['referencia']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
