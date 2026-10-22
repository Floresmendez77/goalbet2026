<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (esta_logueado()) {
    redirect('/dashboard.php');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = null;
$registroValido = null;

if ($token === '') {
    $error = 'Enlace de restablecimiento inválido o no proporcionado.';
} else {
    $stmt = db()->prepare('SELECT pr.*, u.username, u.email, u.nombre FROM password_resets pr JOIN usuarios u ON u.id = pr.id_usuario WHERE pr.token = ? AND pr.utilizado = 0 AND pr.expira_at >= NOW() LIMIT 1');
    $stmt->execute([$token]);
    $registroValido = $stmt->fetch();

    if (!$registroValido) {
        $error = 'El enlace de recuperación es inválido, ya fue utilizado o ha expirado. Por favor solicita uno nuevo.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registroValido) {
    verificar_csrf();

    $password  = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');

    if (strlen($password) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($password !== $password2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $idUsuario = (int) $registroValido['id_usuario'];

        db()->beginTransaction();
        try {
            // Actualizar contraseña del usuario
            $stmtUpd = db()->prepare('UPDATE usuarios SET password = ? WHERE id = ?');
            $stmtUpd->execute([$hash, $idUsuario]);

            // Marcar token como utilizado (un solo uso)
            $stmtToken = db()->prepare('UPDATE password_resets SET utilizado = 1 WHERE id = ?');
            $stmtToken->execute([(int) $registroValido['id']]);

            db()->commit();

            // Desbloquear intentos fallidos previos si estaba bloqueado
            $ip = obtener_ip_cliente();
            reiniciar_intentos_fallidos($registroValido['username'], $ip);
            reiniciar_intentos_fallidos($registroValido['email'], $ip);

            correo_password_cambiada([
                'nombre'   => $registroValido['nombre'],
                'username' => $registroValido['username'],
                'email'    => $registroValido['email'],
            ]);

            flash('success', 'Tu contraseña ha sido restablecida con éxito. Ya podés iniciar sesión.');
            redirect('/login.php');
        } catch (Throwable $e) {
            db()->rollBack();
            $error = 'Ocurrió un error al actualizar la contraseña: ' . $e->getMessage();
        }
    }
}

$titulo_pagina = 'Restablecer Contraseña';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 mb-3 text-center"><i class="bi bi-shield-lock-fill text-warning"></i> Restablecer Contraseña</h1>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($registroValido): ?>
          <p class="text-muted small mb-3">
            Creá una nueva contraseña para la cuenta <strong>@<?= e($registroValido['username']) ?></strong>.
          </p>

          <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            
            <div class="mb-3">
              <label class="form-label">Nueva Contraseña (mínimo 8 caracteres)</label>
              <input type="password" name="password" class="form-control" required minlength="8" autofocus>
            </div>
            <div class="mb-3">
              <label class="form-label">Repetir Nueva Contraseña</label>
              <input type="password" name="password2" class="form-control" required minlength="8">
            </div>
            <button type="submit" class="btn btn-warning w-100 fw-semibold">Guardar nueva contraseña</button>
          </form>
        <?php else: ?>
          <div class="text-center mt-3">
            <a href="<?= APP_URL ?>/recuperar.php" class="btn btn-outline-warning btn-sm">Solicitar un nuevo enlace</a>
          </div>
        <?php endif; ?>

        <div class="text-center mt-3 pt-2 border-top small">
          <a href="<?= APP_URL ?>/login.php" class="text-decoration-none">← Ir al inicio de sesión</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
