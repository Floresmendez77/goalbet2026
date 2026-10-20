<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$pdo = db();
$admin = usuario_actual();
$busqueda = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $accion = $_POST['accion'] ?? '';
    $id_usuario = (int) ($_POST['id_usuario'] ?? 0);

    if ($id_usuario === (int) $admin['id'] && in_array($accion, ['desactivar', 'cambiar_rol'], true)) {
        flash('danger', 'No podés desactivarte ni cambiarte el rol a vos mismo.');
        redirect('/admin/usuarios.php');
    }

    if ($accion === 'toggle_activo') {
        $pdo->prepare('UPDATE usuarios SET activo = NOT activo WHERE id = ?')->execute([$id_usuario]);
        flash('success', 'Estado de la cuenta actualizado.');
    }

    if ($accion === 'cambiar_rol') {
        $nuevo_rol = $_POST['rol'] ?? '';
        if (in_array($nuevo_rol, ['admin', 'cliente'], true)) {
            $pdo->prepare('UPDATE usuarios SET rol = ? WHERE id = ?')->execute([$nuevo_rol, $id_usuario]);
            flash('success', 'Rol actualizado.');
        }
    }

    if ($accion === 'generar_reset_password') {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE password_resets SET utilizado = 1 WHERE id_usuario = ? AND utilizado = 0')->execute([$id_usuario]);
        $pdo->prepare('INSERT INTO password_resets (id_usuario, token, expira_at, utilizado, created_at) VALUES (?, ?, NOW() + INTERVAL 2 HOUR, 0, NOW())')
            ->execute([$id_usuario, $token]);

        $enlace = APP_URL . '/reset_password.php?token=' . $token;
        flash('success', 'Enlace de reseteo generado para el usuario: ' . $enlace . ' (válido por 2 horas).');
        redirect('/admin/usuarios.php' . ($busqueda ? '?q=' . urlencode($busqueda) : ''));
    }

    if ($accion === 'ajuste_saldo') {
        $monto = (float) ($_POST['monto'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');

        if ($monto == 0.0) {
            flash('danger', 'El ajuste no puede ser 0. Usá negativo para restar saldo.');
            redirect('/admin/usuarios.php');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT saldo FROM usuarios WHERE id = ? FOR UPDATE');
            $stmt->execute([$id_usuario]);
            $saldo_anterior = $stmt->fetchColumn();
            if ($saldo_anterior === false) {
                throw new RuntimeException('Usuario no encontrado.');
            }
            $saldo_anterior = (float) $saldo_anterior;
            $saldo_nuevo = $saldo_anterior + $monto;

            if ($saldo_nuevo < 0) {
                throw new RuntimeException('El ajuste dejaría el saldo negativo.');
            }

            $pdo->prepare('UPDATE usuarios SET saldo = ? WHERE id = ?')->execute([$saldo_nuevo, $id_usuario]);

            $ref = generar_referencia('ADJ');
            $pdo->prepare(
                'INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
                 VALUES (?, "ajuste_admin", ?, ?, ?, ?, ?)'
            )->execute([$id_usuario, $monto, $saldo_anterior, $saldo_nuevo, $ref, $descripcion ?: ('Ajuste manual por admin ' . $admin['username'])]);

            $pdo->commit();
            flash('success', 'Ajuste de saldo aplicado (' . money($monto) . ').');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('danger', 'No se pudo aplicar el ajuste: ' . $e->getMessage());
        }
    }

    redirect('/admin/usuarios.php' . ($busqueda ? '?q=' . urlencode($busqueda) : ''));
}

$sql = 'SELECT * FROM usuarios';
$params = [];
if ($busqueda !== '') {
    $sql .= ' WHERE nombre LIKE ? OR apellido LIKE ? OR username LIKE ? OR email LIKE ?';
    $like = '%' . $busqueda . '%';
    $params = [$like, $like, $like, $like];
}
$sql .= ' ORDER BY created_at DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll();

$titulo_pagina = 'Admin · Usuarios';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h1 class="h3 mb-0"><i class="bi bi-people"></i> Usuarios</h1>
  <form method="get" class="d-flex gap-2">
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar..." value="<?= e($busqueda) ?>">
    <button class="btn btn-sm btn-dark">Buscar</button>
  </form>
</div>

<div class="table-responsive">
  <table class="table table-hover bg-white align-middle">
    <thead class="table-dark">
      <tr>
        <th>Usuario</th>
        <th>Email</th>
        <th>Saldo</th>
        <th>Rol</th>
        <th>Activo</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr>
          <td><?= e($u['nombre'] . ' ' . $u['apellido']) ?> <span class="text-muted small">@<?= e($u['username']) ?></span></td>
          <td class="small"><?= e($u['email']) ?></td>
          <td class="fw-semibold"><?= money($u['saldo']) ?></td>
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="cambiar_rol">
              <input type="hidden" name="id_usuario" value="<?= (int) $u['id'] ?>">
              <select name="rol" class="form-select form-select-sm" onchange="this.form.submit()" <?= (int) $u['id'] === (int) $admin['id'] ? 'disabled' : '' ?>>
                <option value="cliente" <?= $u['rol'] === 'cliente' ? 'selected' : '' ?>>Cliente</option>
                <option value="admin" <?= $u['rol'] === 'admin' ? 'selected' : '' ?>>Admin</option>
              </select>
            </form>
          </td>
          <td>
            <form method="post" data-confirm="¿Cambiar el estado de esta cuenta?">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="toggle_activo">
              <input type="hidden" name="id_usuario" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $u['activo'] ? 'btn-success' : 'btn-outline-secondary' ?>" <?= (int) $u['id'] === (int) $admin['id'] ? 'disabled' : '' ?>>
                <?= $u['activo'] ? 'Activo' : 'Inactivo' ?>
              </button>
            </form>
          </td>
          <td>
            <div class="d-flex gap-1">
              <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#ajusteModal<?= (int) $u['id'] ?>">
                <i class="bi bi-cash"></i> Saldo
              </button>
              <form method="post" class="d-inline" data-confirm="¿Generar un enlace de restablecimiento de contraseña para este usuario?">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="generar_reset_password">
                <input type="hidden" name="id_usuario" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary" title="Generar enlace de reseteo">
                  <i class="bi bi-key"></i> Reseteo
                </button>
              </form>
            </div>
          </td>
        </tr>

        <div class="modal fade" id="ajusteModal<?= (int) $u['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="ajuste_saldo">
                <input type="hidden" name="id_usuario" value="<?= (int) $u['id'] ?>">
                <div class="modal-header">
                  <h5 class="modal-title">Ajustar saldo de <?= e($u['username']) ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <p class="text-muted small">Saldo actual: <?= money($u['saldo']) ?>. Usá un valor negativo para restar.</p>
                  <div class="mb-3">
                    <label class="form-label">Monto (+/-)</label>
                    <input type="number" step="0.01" name="monto" class="form-control" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Motivo</label>
                    <input type="text" name="descripcion" class="form-control" maxlength="255" placeholder="Ej: corrección manual, bono, etc.">
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                  <button type="submit" class="btn btn-warning">Aplicar ajuste</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
