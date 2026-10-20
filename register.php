<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (esta_logueado()) {
    redirect('/dashboard.php');
}

$errores = [];
$valores = ['nombre' => '', 'apellido' => '', 'username' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $valores['nombre']   = trim($_POST['nombre'] ?? '');
    $valores['apellido'] = trim($_POST['apellido'] ?? '');
    $valores['username'] = trim($_POST['username'] ?? '');
    $valores['email']    = trim($_POST['email'] ?? '');
    $password            = (string) ($_POST['password'] ?? '');
    $password2           = (string) ($_POST['password2'] ?? '');

    if ($valores['nombre'] === '' || $valores['apellido'] === '') {
        $errores[] = 'Nombre y apellido son obligatorios.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.]{3,60}$/', $valores['username'])) {
        $errores[] = 'El usuario debe tener entre 3 y 60 caracteres (letras, números, "." o "_").';
    }
    if (!filter_var($valores['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El email no es válido.';
    }
    if (strlen($password) < 8) {
        $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if ($password !== $password2) {
        $errores[] = 'Las contraseñas no coinciden.';
    }
    if (empty($_POST['mayor_edad'])) {
        $errores[] = 'Debes confirmar que eres mayor de 18 años y aceptas las políticas de juego responsable.';
    }

    if (!$errores) {
        $stmt = db()->prepare('SELECT id FROM usuarios WHERE username = ? OR email = ?');
        $stmt->execute([$valores['username'], $valores['email']]);
        if ($stmt->fetch()) {
            $errores[] = 'Ya existe una cuenta con ese usuario o email.';
        }
    }

    if (!$errores) {
        $stmt = db()->prepare(
            'INSERT INTO usuarios (nombre, apellido, username, email, password, saldo, rol)
             VALUES (?, ?, ?, ?, ?, 0.00, "cliente")'
        );
        $stmt->execute([
            $valores['nombre'],
            $valores['apellido'],
            $valores['username'],
            $valores['email'],
            password_hash($password, PASSWORD_DEFAULT),
        ]);

        flash('success', 'Cuenta creada. Ya podés iniciar sesión.');
        redirect('/login.php');
    }
}

$titulo_pagina = 'Crear cuenta';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 mb-4 text-center"><i class="bi bi-person-plus"></i> Crear cuenta</h1>

        <?php if ($errores): ?>
          <div class="alert alert-danger">
            <ul class="mb-0">
              <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Nombre</label>
              <input type="text" name="nombre" data-cy="input-nombre" class="form-control" value="<?= e($valores['nombre']) ?>" required>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Apellido</label>
              <input type="text" name="apellido" data-cy="input-apellido" class="form-control" value="<?= e($valores['apellido']) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Usuario</label>
            <input type="text" name="username" data-cy="input-username" class="form-control" value="<?= e($valores['username']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" data-cy="input-email" class="form-control" value="<?= e($valores['email']) ?>" required>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Contraseña</label>
              <input type="password" name="password" data-cy="input-password" class="form-control" required minlength="8">
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Repetir contraseña</label>
              <input type="password" name="password2" data-cy="input-password2" class="form-control" required minlength="8">
            </div>
          </div>
          <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="mayor_edad" id="mayor_edad" required data-cy="check-mayor-edad">
            <label class="form-check-label small text-muted" for="mayor_edad">
              <span class="badge bg-danger me-1">+18</span> Confirmo que soy mayor de 18 años y acepto las políticas de <strong>juego responsable</strong>. El juego compulsivo es perjudicial para la salud.
            </label>
          </div>
          <button type="submit" class="btn btn-warning w-100 fw-semibold" data-cy="btn-register">Crear cuenta</button>
        </form>

        <p class="text-center mt-3 mb-0 small">
          ¿Ya tenés cuenta? <a href="<?= APP_URL ?>/login.php">Iniciá sesión</a>
        </p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>