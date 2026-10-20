<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (esta_logueado()) {
    redirect('/dashboard.php');
}

$mensaje = null;
$error = null;
$enlaceDev = null;
$identificador = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $identificador = trim($_POST['identificador'] ?? '');
    $ip = obtener_ip_cliente();

    if ($identificador === '') {
        $error = 'Por favor ingresá tu nombre de usuario o correo electrónico.';
    } else {
        $stmt = db()->prepare('SELECT id, username, email FROM usuarios WHERE (username = ? OR email = ?) LIMIT 1');
        $stmt->execute([$identificador, $identificador]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $token = bin2hex(random_bytes(32));
            // Invalidar tokens previos no utilizados del mismo usuario
            db()->prepare('UPDATE password_resets SET utilizado = 1 WHERE id_usuario = ? AND utilizado = 0')
                ->execute([(int) $usuario['id']]);

            // Crear nuevo token con expiración de 1 hora
            db()->prepare('INSERT INTO password_resets (id_usuario, token, expira_at, utilizado, created_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 0, NOW())')
                ->execute([(int) $usuario['id'], $token]);

            $urlReset = APP_URL . '/reset_password.php?token=' . $token;

            // Si estamos en entorno local, facilitar el enlace directo en pantalla
            if (Env::get('APP_ENV') === 'local') {
                $enlaceDev = $urlReset;
            }
        }

        $mensaje = 'Si los datos ingresados corresponden a una cuenta registrada, se ha generado el enlace de restablecimiento. Dado que este servidor no cuenta con envío de correo SMTP automático, también puedes solicitarle a un administrador que te genere tu enlace seguro desde su panel.';
    }
}

$titulo_pagina = 'Recuperar Contraseña';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 mb-3 text-center"><i class="bi bi-key-fill text-warning"></i> Recuperar Contraseña</h1>
        <p class="text-muted small text-center mb-4">
          Ingresá tu usuario o correo electrónico registrado para restablecer el acceso a tu cuenta.
        </p>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($mensaje): ?>
          <div class="alert alert-info">
            <?= e($mensaje) ?>
          </div>
          <?php if ($enlaceDev): ?>
            <div class="alert alert-warning">
              <strong>Modo desarrollo / local:</strong><br>
              Enlace directo generado:<br>
              <a href="<?= e($enlaceDev) ?>" class="text-break fw-semibold text-dark"><?= e($enlaceDev) ?></a>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Usuario o Email</label>
            <input type="text" name="identificador" class="form-control" value="<?= e($identificador) ?>" required autofocus placeholder="tu_usuario o correo@ejemplo.com">
          </div>
          <button type="submit" class="btn btn-warning w-100 fw-semibold">Generar solicitud</button>
        </form>

        <div class="text-center mt-3 pt-2 border-top small">
          <a href="<?= APP_URL ?>/login.php" class="text-decoration-none">← Volver al inicio de sesión</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
