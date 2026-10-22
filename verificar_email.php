<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$token     = trim((string) ($_GET['token'] ?? ''));
$resultado = confirmar_verificacion_email($token);

if ($resultado['ok']) {
    if (esta_logueado()) {
        refrescar_usuario_sesion();
    }
    if ($resultado['cambio']) {
        flash('success', $resultado['ya_verificado']
            ? 'Tu nuevo correo ya estaba confirmado.'
            : '¡Listo! Tu correo se cambió y quedó confirmado. Te avisamos también al correo anterior.');
        redirect(esta_logueado() ? '/perfil.php' : '/login.php');
    }
    flash('success', $resultado['ya_verificado']
        ? 'Tu correo ya estaba confirmado. Ya podés iniciar sesión.'
        : '¡Correo confirmado! Tu cuenta está activa y te enviamos un correo de bienvenida.');
    redirect(esta_logueado() ? '/dashboard.php' : '/login.php');
}

$titulo_pagina = 'Confirmar correo';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4 text-center">
        <h1 class="h4 mb-3"><i class="bi bi-envelope-exclamation text-warning"></i> No pudimos confirmar tu correo</h1>
        <div class="alert alert-danger"><?= e((string) $resultado['error']) ?></div>
        <a href="<?= APP_URL ?>/reenviar_verificacion.php" class="btn btn-warning fw-semibold">Pedir un enlace nuevo</a>
        <div class="mt-3 pt-2 border-top small">
          <a href="<?= APP_URL ?>/login.php" class="text-decoration-none">← Ir al inicio de sesión</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
