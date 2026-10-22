<?php

declare(strict_types=1);

/**
 * Página 404. Apache la sirve (vía .htaccess) para cualquier URL que no exista,
 * manteniendo el código HTTP 404.
 */

require_once __DIR__ . '/includes/bootstrap.php';

http_response_code(404);

$titulo_pagina = 'Página no encontrada';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-8 col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body p-4 p-md-5 text-center" data-cy="pagina-404">
        <div class="display-1 fw-bold text-warning mb-1">404</div>
        <h1 class="h3 mb-3">⚽ Esta página se fue al banquillo</h1>
        <p class="text-muted">No encontramos lo que buscabas. Puede que el enlace esté mal escrito o que la página ya no exista.</p>
        <div class="d-flex flex-wrap gap-2 justify-content-center mt-4">
          <a href="<?= APP_URL ?>/<?= esta_logueado() ? 'dashboard.php' : 'index.php' ?>" class="btn btn-warning fw-semibold">
            <i class="bi bi-house"></i> <?= esta_logueado() ? 'Ir a mi dashboard' : 'Ir al inicio' ?>
          </a>
          <?php if (esta_logueado()): ?>
            <a href="<?= APP_URL ?>/partidos.php" class="btn btn-outline-secondary"><i class="bi bi-calendar-event"></i> Ver partidos</a>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/ayuda.php" class="btn btn-outline-secondary"><i class="bi bi-question-circle"></i> Ayuda</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
