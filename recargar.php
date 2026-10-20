<?php
require_once __DIR__ . '/includes/bootstrap.php';
requerir_login();
refrescar_usuario_sesion();

const RECARGA_MINIMA = 50.00;
const RECARGA_MAXIMA = 50000.00;

$usuario = usuario_actual();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $monto = (float) ($_POST['monto'] ?? 0);
    $metodo = trim($_POST['metodo'] ?? '');
    $comprobante = trim($_POST['comprobante'] ?? '');

    $errores = [];
    if ($monto < RECARGA_MINIMA || $monto > RECARGA_MAXIMA) {
        $errores[] = 'El monto debe estar entre ' . money(RECARGA_MINIMA) . ' y ' . money(RECARGA_MAXIMA) . '.';
    }
    if ($metodo === '') {
        $errores[] = 'Indicá el método (transferencia, depósito, efectivo, etc).';
    }

    if (!$errores) {
        $ref = generar_referencia('REC');
        db()->prepare(
            'INSERT INTO recargas (id_usuario, monto, metodo, comprobante, referencia)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$usuario['id'], $monto, $metodo, $comprobante ?: null, $ref]);

        flash('success', 'Solicitud de recarga enviada (' . $ref . '). Un admin la revisará pronto.');
        redirect('/recargar.php');
    } else {
        foreach ($errores as $err) {
            flash('danger', $err);
        }
    }
}

$stmt = db()->prepare('SELECT * FROM recargas WHERE id_usuario = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$usuario['id']]);
$recargas = $stmt->fetchAll();

$titulo_pagina = 'Recargar saldo';
require __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4"><i class="bi bi-wallet2"></i> Recargar saldo</h1>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h5">Pedir recarga</h2>
        <p class="text-muted small">
          Tu saldo actual es <strong><?= money($usuario['saldo']) ?></strong>. Al enviar la solicitud,
          un administrador la va a revisar y aprobar o rechazar. El saldo se acredita recién
          cuando la solicitud queda <strong>aprobada</strong>.
        </p>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Monto a recargar</label>
            <input type="number" name="monto" data-cy="input-monto-recarga" class="form-control" min="<?= RECARGA_MINIMA ?>" max="<?= RECARGA_MAXIMA ?>" step="0.01" required>
            <div class="form-text">Entre <?= money(RECARGA_MINIMA) ?> y <?= money(RECARGA_MAXIMA) ?>.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Método</label>
            <select name="metodo" data-cy="select-metodo-recarga" class="form-select" required>
              <option value="">Elegí uno...</option>
              <option value="Transferencia bancaria">Transferencia bancaria</option>
              <option value="Depósito en agencia">Depósito en agencia</option>
              <option value="Efectivo">Efectivo</option>
              <option value="Billetera móvil">Billetera móvil</option>
              <option value="Otro">Otro</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Comprobante / referencia (opcional)</label>
            <input type="text" name="comprobante" data-cy="input-comprobante-recarga" class="form-control" maxlength="255" placeholder="Nº de transacción, nota, etc.">
          </div>
          <button type="submit" class="btn btn-warning w-100 fw-semibold" data-cy="btn-enviar-recarga">
            <i class="bi bi-send"></i> Enviar solicitud
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <h2 class="h5 mb-3">Mis solicitudes</h2>
    <?php if (!$recargas): ?>
      <div class="alert alert-secondary">Todavía no pediste ninguna recarga.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover bg-white align-middle">
          <thead class="table-dark">
            <tr>
              <th>Referencia</th>
              <th>Monto</th>
              <th>Método</th>
              <th>Estado</th>
              <th>Fecha</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recargas as $r): ?>
              <tr>
                <td class="small font-monospace"><?= e($r['referencia']) ?></td>
                <td><?= money($r['monto']) ?></td>
                <td><?= e($r['metodo']) ?></td>
                <td>
                  <?= badge_estado($r['estado']) ?>
                  <?php if ($r['estado'] === 'rechazada' && $r['motivo_rechazo']): ?>
                    <div class="small text-muted"><?= e($r['motivo_rechazo']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= fecha_legible($r['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
