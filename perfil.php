<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
requerir_login();

$pdo = db();
$uid = (int) usuario_actual()['id'];
$ip  = obtener_ip_cliente();

const PERFIL_MAX_FALLOS = 5;

$cargarUsuario = static function () use ($pdo, $uid): array {
    $st = $pdo->prepare(
        'SELECT id, nombre, apellido, username, email, telefono, password, saldo, avisos_email, email_verificado, created_at
         FROM usuarios WHERE id = ?'
    );
    $st->execute([$uid]);
    return $st->fetch() ?: [];
};

$u = $cargarUsuario();
if (!$u) {
    redirect('/logout.php');
}

$errores = ['datos' => [], 'password' => [], 'email' => []];
$valores = ['nombre' => $u['nombre'], 'apellido' => $u['apellido'], 'telefono' => (string) ($u['telefono'] ?? ''), 'email_nuevo' => ''];

/**
 * Verifica la contraseña actual con límite de intentos (5 fallos / 15 min), igual que el login.
 * @return ?string mensaje de error, o null si la contraseña es correcta
 */
$comprobarPasswordActual = static function (string $actual) use ($u, $ip): ?string {
    $clave = 'perfil:' . $u['id'];
    $fallos = contar_fallos_login_recientes($clave, $ip, 15);
    if ($fallos >= PERFIL_MAX_FALLOS) {
        return 'Demasiados intentos fallidos. Por seguridad, esperá 15 minutos antes de volver a intentar.';
    }
    if (!password_verify($actual, (string) $u['password'])) {
        registrar_intento_login($clave, $ip, false);
        $restantes = PERFIL_MAX_FALLOS - ($fallos + 1);
        return $restantes > 0
            ? "La contraseña actual no es correcta. Te quedan {$restantes} intento(s)."
            : 'Demasiados intentos fallidos. Por seguridad, esperá 15 minutos antes de volver a intentar.';
    }
    reiniciar_intentos_fallidos($clave, $ip);
    return null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    switch ($accion) {
        // ------------------------------------------------------------ datos personales
        case 'datos':
            $valores['nombre']   = trim((string) ($_POST['nombre'] ?? ''));
            $valores['apellido'] = trim((string) ($_POST['apellido'] ?? ''));
            $valores['telefono'] = trim((string) ($_POST['telefono'] ?? ''));

            if ($valores['nombre'] === '' || $valores['apellido'] === '') {
                $errores['datos'][] = 'Nombre y apellido son obligatorios.';
            }
            if (mb_strlen($valores['nombre']) > 100 || mb_strlen($valores['apellido']) > 100) {
                $errores['datos'][] = 'Nombre y apellido pueden tener hasta 100 caracteres.';
            }
            if ($valores['telefono'] !== '' && !preg_match('/^\+?[0-9 ()\-]{7,25}$/', $valores['telefono'])) {
                $errores['datos'][] = 'El teléfono no es válido (solo números, espacios, + ( ) y guiones).';
            }
            if (!$errores['datos']) {
                $pdo->prepare('UPDATE usuarios SET nombre = ?, apellido = ?, telefono = ? WHERE id = ?')
                    ->execute([$valores['nombre'], $valores['apellido'], $valores['telefono'] !== '' ? $valores['telefono'] : null, $uid]);
                refrescar_usuario_sesion();
                flash('success', 'Tus datos se actualizaron.');
                redirect('/perfil.php');
            }
            break;

        // ------------------------------------------------------------ cambiar contraseña
        case 'password':
            $actual = (string) ($_POST['password_actual'] ?? '');
            $nueva  = (string) ($_POST['password_nueva'] ?? '');
            $nueva2 = (string) ($_POST['password_nueva2'] ?? '');

            if ($actual === '' || $nueva === '' || $nueva2 === '') {
                $errores['password'][] = 'Completá los tres campos.';
            } elseif (strlen($nueva) < 8) {
                $errores['password'][] = 'La nueva contraseña debe tener al menos 8 caracteres.';
            } elseif ($nueva !== $nueva2) {
                $errores['password'][] = 'Las contraseñas nuevas no coinciden.';
            } elseif ($nueva === $actual) {
                $errores['password'][] = 'La nueva contraseña debe ser distinta de la actual.';
            }
            if (!$errores['password'] && ($msg = $comprobarPasswordActual($actual)) !== null) {
                $errores['password'][] = $msg;
            }
            if (!$errores['password']) {
                $pdo->prepare('UPDATE usuarios SET password = ? WHERE id = ?')
                    ->execute([password_hash($nueva, PASSWORD_DEFAULT), $uid]);
                // Cualquier enlace de «olvidé mi contraseña» que estuviera pendiente deja de servir.
                $pdo->prepare('UPDATE password_resets SET utilizado = 1 WHERE id_usuario = ? AND utilizado = 0')->execute([$uid]);
                session_regenerate_id(true);
                correo_password_cambiada($u);
                flash('success', 'Tu contraseña se cambió correctamente. Te enviamos un correo de aviso.');
                redirect('/perfil.php');
            }
            break;

        // ------------------------------------------------------------ cambiar correo (con confirmación)
        case 'email':
            $valores['email_nuevo'] = trim((string) ($_POST['email_nuevo'] ?? ''));
            $actual = (string) ($_POST['password_actual_email'] ?? '');

            if (!filter_var($valores['email_nuevo'], FILTER_VALIDATE_EMAIL) || mb_strlen($valores['email_nuevo']) > 150) {
                $errores['email'][] = 'El correo nuevo no es válido.';
            } elseif (strcasecmp($valores['email_nuevo'], (string) $u['email']) === 0) {
                $errores['email'][] = 'Ese ya es el correo de tu cuenta.';
            } elseif ($actual === '') {
                $errores['email'][] = 'Ingresá tu contraseña actual para confirmar el cambio.';
            }
            if (!$errores['email'] && ($msg = $comprobarPasswordActual($actual)) !== null) {
                $errores['email'][] = $msg;
            }
            if (!$errores['email']) {
                $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1');
                $st->execute([$valores['email_nuevo'], $uid]);
                if ($st->fetch()) {
                    $errores['email'][] = 'Ya existe otra cuenta con ese correo.';
                } elseif (!puede_enviar_verificacion($uid)) {
                    $errores['email'][] = 'Ya te enviamos un correo hace instantes. Esperá un minuto antes de pedir otro.';
                }
            }
            if (!$errores['email']) {
                $envio = enviar_cambio_email($u, $valores['email_nuevo']);
                if ($envio['enviado']) {
                    flash('success', 'Te enviamos un correo a ' . $valores['email_nuevo'] . '. Abrí el enlace para confirmar el cambio; hasta entonces tu correo actual sigue siendo el mismo.');
                } else {
                    flash('warning', 'No pudimos enviar el correo de confirmación. Probá de nuevo en unos minutos.');
                }
                if (Env::get('APP_ENV') === 'local' && !mail_configurado()) {
                    flash('info', 'Modo local: enlace de confirmación → ' . $envio['enlace']);
                }
                redirect('/perfil.php');
            }
            break;

        case 'cancelar_cambio':
            cancelar_cambio_email($uid);
            flash('info', 'Cancelaste el cambio de correo.');
            redirect('/perfil.php');

        // ------------------------------------------------------------ reenviar confirmación del correo actual
        case 'reenviar':
            if ((int) $u['email_verificado'] === 0) {
                if (puede_enviar_verificacion($uid)) {
                    $envio = enviar_verificacion_a_usuario($u);
                    flash($envio['enviado'] ? 'success' : 'warning', $envio['enviado']
                        ? 'Te enviamos un correo a ' . $u['email'] . ' para confirmarlo.'
                        : 'No pudimos enviar el correo. Probá de nuevo en unos minutos.');
                    if (Env::get('APP_ENV') === 'local' && !mail_configurado()) {
                        flash('info', 'Modo local: enlace de confirmación → ' . $envio['enlace']);
                    }
                } else {
                    flash('info', 'Ya te enviamos un correo hace instantes. Esperá un minuto antes de pedir otro.');
                }
            }
            redirect('/perfil.php');

        // ------------------------------------------------------------ preferencia de avisos
        case 'avisos':
            $pdo->prepare('UPDATE usuarios SET avisos_email = ? WHERE id = ?')->execute([!empty($_POST['avisos_email']) ? 1 : 0, $uid]);
            flash('success', !empty($_POST['avisos_email'])
                ? 'Avisos por correo activados.'
                : 'Avisos por correo desactivados. Los correos de tu cuenta (contraseña, recargas) se siguen enviando.');
            redirect('/perfil.php');

        default:
            flash('danger', 'Acción no válida.');
            redirect('/perfil.php');
    }

    $u = $cargarUsuario();
}

$pendiente = cambio_email_pendiente($uid);

$titulo_pagina = 'Mi perfil';
require __DIR__ . '/includes/header.php';

$erroresHtml = static function (array $lista): string {
    if (!$lista) {
        return '';
    }
    $h = '<div class="alert alert-danger py-2" data-cy="perfil-error">';
    foreach ($lista as $m) {
        $h .= '<div>' . e($m) . '</div>';
    }
    return $h . '</div>';
};
?>

<div class="mb-4">
  <h1 class="h3 mb-1"><i class="bi bi-person-gear text-warning"></i> Mi perfil</h1>
  <p class="text-muted mb-0">Administrá tus datos, tu contraseña y tu correo.</p>
</div>

<div class="row g-4">
  <div class="col-lg-6">

    <!-- Cuenta -->
    <div class="card shadow-sm mb-4">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Mi cuenta</h2>
        <dl class="row mb-0 small">
          <dt class="col-5 text-muted fw-normal">Usuario</dt>
          <dd class="col-7">@<?= e($u['username']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Correo</dt>
          <dd class="col-7 text-break">
            <?= e($u['email']) ?>
            <?php if ((int) $u['email_verificado'] === 1): ?>
              <span class="badge text-bg-success">Verificado</span>
            <?php else: ?>
              <span class="badge text-bg-warning">Sin verificar</span>
            <?php endif; ?>
          </dd>
          <dt class="col-5 text-muted fw-normal">Saldo</dt>
          <dd class="col-7"><?= e(money($u['saldo'])) ?></dd>
          <dt class="col-5 text-muted fw-normal">Miembro desde</dt>
          <dd class="col-7 mb-0"><?= e(fecha_legible($u['created_at'], 'd/m/Y')) ?></dd>
        </dl>
        <?php if ((int) $u['email_verificado'] === 0): ?>
          <form method="post" class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="reenviar">
            <button type="submit" class="btn btn-sm btn-outline-warning">Enviar correo de confirmación</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Datos personales -->
    <div class="card shadow-sm mb-4">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Datos personales</h2>
        <?= $erroresHtml($errores['datos']) ?>
        <form method="post" novalidate data-cy="form-datos">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="datos">
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label">Nombre</label>
              <input type="text" name="nombre" class="form-control" maxlength="100" value="<?= e($valores['nombre']) ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Apellido</label>
              <input type="text" name="apellido" class="form-control" maxlength="100" value="<?= e($valores['apellido']) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Teléfono <span class="text-muted small">(opcional)</span></label>
              <input type="tel" name="telefono" class="form-control" maxlength="25" value="<?= e($valores['telefono']) ?>" placeholder="+504 9999-9999">
            </div>
          </div>
          <button type="submit" class="btn btn-warning fw-semibold mt-3">Guardar cambios</button>
        </form>
      </div>
    </div>

    <!-- Avisos -->
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Avisos por correo</h2>
        <form method="post" data-cy="form-avisos">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="avisos">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="avisos_email" name="avisos_email" value="1" <?= (int) $u['avisos_email'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="avisos_email">Recibir avisos de mis equipos favoritos y apuestas</label>
          </div>
          <p class="text-muted small">Recordatorios antes de los partidos, 11 titular, resultados y resultado de tus apuestas.</p>
          <button type="submit" class="btn btn-outline-warning btn-sm">Guardar preferencia</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">

    <!-- Contraseña -->
    <div class="card shadow-sm mb-4">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Cambiar contraseña</h2>
        <?= $erroresHtml($errores['password']) ?>
        <form method="post" novalidate data-cy="form-password">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="password">
          <div class="mb-3">
            <label class="form-label">Contraseña actual</label>
            <input type="password" name="password_actual" class="form-control" autocomplete="current-password" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nueva contraseña</label>
            <input type="password" name="password_nueva" class="form-control" minlength="8" autocomplete="new-password" required>
            <div class="form-text">Mínimo 8 caracteres.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Repetir nueva contraseña</label>
            <input type="password" name="password_nueva2" class="form-control" minlength="8" autocomplete="new-password" required>
          </div>
          <button type="submit" class="btn btn-warning fw-semibold">Cambiar contraseña</button>
        </form>
      </div>
    </div>

    <!-- Correo -->
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Cambiar correo electrónico</h2>

        <?php if ($pendiente): ?>
          <div class="alert alert-info py-2" data-cy="cambio-pendiente">
            Tenés un cambio pendiente a <strong><?= e($pendiente) ?></strong>. Abrí el enlace que te enviamos a esa dirección para confirmarlo.
            <form method="post" class="mt-2">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="cancelar_cambio">
              <button type="submit" class="btn btn-sm btn-outline-secondary">Cancelar cambio</button>
            </form>
          </div>
        <?php endif; ?>

        <?= $erroresHtml($errores['email']) ?>
        <form method="post" novalidate data-cy="form-email">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="email">
          <div class="mb-3">
            <label class="form-label">Correo nuevo</label>
            <input type="email" name="email_nuevo" class="form-control" maxlength="150" value="<?= e($valores['email_nuevo']) ?>" autocomplete="email" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Contraseña actual</label>
            <input type="password" name="password_actual_email" class="form-control" autocomplete="current-password" required>
            <div class="form-text">Te enviamos un enlace al correo nuevo. Tu correo actual no cambia hasta que lo confirmes.</div>
          </div>
          <button type="submit" class="btn btn-warning fw-semibold">Enviar enlace de confirmación</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
