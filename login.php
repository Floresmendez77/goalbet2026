<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (esta_logueado()) {
    redirect('/dashboard.php');
}

$error = null;
$mostrarReenvio = false;
$mostrarAyuda = false;
$identificador = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $identificador = trim($_POST['identificador'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $ip = obtener_ip_cliente();

    // Limpieza oportunista de registros antiguos (> 24h)
    limpiar_intentos_login_antiguos(24);

    if ($identificador === '' || $password === '') {
        $error = 'Por favor ingresá tu usuario/email y contraseña.';
    } else {
        // Bloqueo si hay 5 o más fallos en los últimos 15 minutos
        $fallosPrevios = contar_fallos_login_recientes($identificador, $ip, 15);
        if ($fallosPrevios >= 5) {
            $error = 'Demasiados intentos fallidos. Tu acceso ha sido bloqueado temporalmente por 15 minutos.';
        } else {
            $stmt = db()->prepare('SELECT * FROM usuarios WHERE (username = ? OR email = ?) LIMIT 1');
            $stmt->execute([$identificador, $identificador]);
            $usuario = $stmt->fetch();

            if (!$usuario || !password_verify($password, $usuario['password'])) {
                registrar_intento_login($identificador, $ip, false);
                $fallosActuales = $fallosPrevios + 1;
                if ($fallosActuales >= 5) {
                    $error = 'Demasiados intentos fallidos. Tu acceso ha sido bloqueado temporalmente por 15 minutos.';
                } else {
                    $restantes = 5 - $fallosActuales;
                    $error = "Usuario/email o contraseña incorrectos. Te quedan {$restantes} intento(s) antes del bloqueo temporal.";
                }
            } elseif ((int) $usuario['activo'] === 0) {
                $error = 'Esta cuenta está desactivada. Contactá a un administrador.';
                $mostrarAyuda = true;
            } elseif (email_verificacion_requerida() && (int) ($usuario['email_verificado'] ?? 1) === 0) {
                $error = 'Todavía no confirmaste tu correo. Revisá tu bandeja de entrada (y spam) y abrí el enlace que te enviamos.';
                $mostrarReenvio = true;
            } else {
                registrar_intento_login($identificador, $ip, true);
                reiniciar_intentos_fallidos($identificador, $ip);

                unset($usuario['password']);
                $_SESSION['usuario'] = $usuario;
                session_regenerate_id(true);
                flash('success', 'Bienvenido, ' . $usuario['nombre'] . '.');
                redirect('/dashboard.php');
            }
        }
    }
}

$titulo_pagina = 'Ingresar';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 mb-4 text-center"><i class="bi bi-box-arrow-in-right"></i> Ingresar</h1>

        <?php if ($error): ?>
          <div class="alert alert-danger" data-cy="login-error">
            <?= e($error) ?>
            <?php if ($mostrarReenvio): ?>
              <div class="mt-2"><a href="<?= APP_URL ?>/reenviar_verificacion.php?id=<?= urlencode($identificador) ?>" class="alert-link">Reenviar correo de confirmación</a></div>
            <?php endif; ?>
            <?php if ($mostrarAyuda): ?>
              <div class="mt-2"><a href="<?= APP_URL ?>/ayuda.php" class="alert-link">Contactar a soporte</a></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Usuario o email</label>
            <input type="text" name="identificador" data-cy="input-identificador" class="form-control" value="<?= e($identificador) ?>" required autofocus>
          </div>
          <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label class="form-label mb-0">Contraseña</label>
              <a href="<?= APP_URL ?>/recuperar.php" class="small text-muted text-decoration-none">¿Olvidaste tu contraseña?</a>
            </div>
            <input type="password" name="password" data-cy="input-password" class="form-control" required>
          </div>
          <button type="submit" data-cy="btn-login" class="btn btn-warning w-100 fw-semibold">Ingresar</button>
        </form>

        <p class="text-center mt-3 mb-0 small">
          ¿No tenés cuenta? <a href="<?= APP_URL ?>/register.php">Creá una</a>
        </p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>