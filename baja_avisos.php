<?php

declare(strict_types=1);

/**
 * Baja / alta de avisos por correo desde el enlace firmado de cada aviso.
 * No exige sesión: la firma (HMAC) del enlace es la prueba de identidad.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/avisos.php';

$uid = (int) ($_REQUEST['u'] ?? 0);
$t   = (string) ($_REQUEST['t'] ?? '');
$mensaje = null;
$valido  = avisos_firma_valida($uid, $t);
$activo  = null;

if ($valido) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = (string) ($_POST['accion'] ?? '');
        if (in_array($accion, ['baja', 'alta'], true)) {
            db()->prepare('UPDATE usuarios SET avisos_email = ? WHERE id = ?')->execute([$accion === 'alta' ? 1 : 0, $uid]);
            $mensaje = $accion === 'alta'
                ? 'Listo, volviste a activar los avisos por correo.'
                : 'Listo, no vas a recibir más avisos de partidos, apuestas ni alineaciones. Los correos de tu cuenta (confirmación, contraseña y recargas) se siguen enviando.';
        }
    }
    $stmt = db()->prepare('SELECT avisos_email FROM usuarios WHERE id = ?');
    $stmt->execute([$uid]);
    $fila = $stmt->fetch();
    $valido = (bool) $fila;
    $activo = $fila ? (int) $fila['avisos_email'] === 1 : null;
}

$titulo_pagina = 'Avisos por correo';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4 text-center">
        <h1 class="h4 mb-3"><i class="bi bi-bell text-warning"></i> Avisos por correo</h1>

        <?php if (!$valido): ?>
          <div class="alert alert-danger">El enlace no es válido. Usá el enlace «Dejar de recibir avisos» de uno de los correos que te enviamos.</div>
        <?php else: ?>
          <?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>

          <p class="text-muted small">
            Avisos de partidos de tus equipos favoritos, alineaciones, resultados de tus apuestas y combinadas.
            Ahora están <strong><?= $activo ? 'activados' : 'desactivados' ?></strong>.
          </p>
          <form method="post">
            <input type="hidden" name="u" value="<?= (int) $uid ?>">
            <input type="hidden" name="t" value="<?= e($t) ?>">
            <input type="hidden" name="accion" value="<?= $activo ? 'baja' : 'alta' ?>">
            <button type="submit" class="btn <?= $activo ? 'btn-outline-danger' : 'btn-warning fw-semibold' ?>">
              <?= $activo ? 'Dejar de recibir avisos' : 'Volver a activar avisos' ?>
            </button>
          </form>
        <?php endif; ?>

        <div class="mt-3 pt-2 border-top small">
          <a href="<?= APP_URL ?>/index.php" class="text-decoration-none">← Ir a GoalBet</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
