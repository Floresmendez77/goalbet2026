<?php
require_once __DIR__ . '/includes/bootstrap.php';
requerir_login();
refrescar_usuario_sesion();

const MONTO_MINIMO = 20.00;
define('MONTO_MAXIMO', (float) (Env::get('MONTO_MAXIMO_APUESTA', '5000')));

$usuario = usuario_actual();
$id_partido_get = isset($_GET['partido']) ? (int) $_GET['partido'] : null;

/** Trae un partido 'programado' con nombres de equipos/liga, o null. */
function obtener_partido_apostable(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.*, d.nombre AS deporte, d.tiene_empate, lt.nombre AS liga,
                el.nombre AS equipo_local, ev.nombre AS equipo_visita,
                el.escudo AS escudo_local, ev.escudo AS escudo_visita
         FROM partidos p
         JOIN deportes d ON d.id = p.id_deporte
         LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
         JOIN equipos el ON el.id = p.id_equipo_local
         JOIN equipos ev ON ev.id = p.id_equipo_visita
         WHERE p.id = ? AND p.estado = 'programado' AND p.fecha_partido > UTC_TIMESTAMP()"
    );
    $stmt->execute([$id]);
    $partido = $stmt->fetch();
    return $partido ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $id_partido   = (int) ($_POST['id_partido'] ?? 0);
    $tipo_apuesta = $_POST['tipo_apuesta'] ?? '';
    $cuota_form   = (string) ($_POST['cuota'] ?? '');
    $monto        = (float) ($_POST['monto'] ?? 0);

    $partido = obtener_partido_apostable(db(), $id_partido);

    if (!$partido) {
        flash('danger', 'Ese partido ya no está disponible para apostar.');
        redirect('/partidos.php');
    }

    if (!in_array($tipo_apuesta, ['local', 'empate', 'visita'], true)) {
        flash('danger', 'Elegí un resultado válido (local, empate o visita).');
        redirect('/apostar.php?partido=' . $id_partido);
    }
    if ($tipo_apuesta === 'empate' && !$partido['tiene_empate']) {
        flash('danger', 'Este deporte no admite apuestas a empate.');
        redirect('/apostar.php?partido=' . $id_partido);
    }
    if ($monto < MONTO_MINIMO) {
        flash('danger', 'El monto mínimo por apuesta es ' . money(MONTO_MINIMO) . '.');
        redirect('/apostar.php?partido=' . $id_partido);
    }
    if ($monto > MONTO_MAXIMO) {
        flash('danger', 'El monto máximo por apuesta es ' . money(MONTO_MAXIMO) . '.');
        redirect('/apostar.php?partido=' . $id_partido);
    }
    if ($monto > (float) $usuario['saldo']) {
        flash('danger', 'No tenés saldo suficiente para esa apuesta.');
        redirect('/apostar.php?partido=' . $id_partido);
    }

    // La cuota real siempre se lee de la BD (nunca se confía en el valor del form).
    $cuota_real = match ($tipo_apuesta) {
        'local'  => $partido['cuota_local'],
        'empate' => $partido['cuota_empate'],
        'visita' => $partido['cuota_visita'],
    };
    if ($cuota_real === null || (float) $cuota_real !== round((float) $cuota_form, 2)) {
        // La cuota cambió entre que se cargó la página y se envió el form: se re-consulta y se avisa.
        flash('warning', 'La cuota cambió, revisá el partido antes de confirmar de nuevo.');
        redirect('/apostar.php?partido=' . $id_partido);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Bloquear la fila del usuario para evitar condiciones de carrera con el saldo.
        $stmt = $pdo->prepare('SELECT saldo FROM usuarios WHERE id = ? FOR UPDATE');
        $stmt->execute([$usuario['id']]);
        $saldo_actual = (float) $stmt->fetchColumn();

        if ($monto > $saldo_actual) {
            throw new RuntimeException('Saldo insuficiente.');
        }

        $saldo_nuevo = $saldo_actual - $monto;
        $pdo->prepare('UPDATE usuarios SET saldo = ? WHERE id = ?')
            ->execute([$saldo_nuevo, $usuario['id']]);

        $ref_apuesta = generar_referencia('APU');
        $pdo->prepare(
            'INSERT INTO apuestas (id_usuario, id_partido, tipo_apuesta, monto, cuota, referencia)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$usuario['id'], $id_partido, $tipo_apuesta, $monto, $cuota_real, $ref_apuesta]);
        $id_apuesta = (int) $pdo->lastInsertId();

        $ref_tx = generar_referencia('TXA');
        $pdo->prepare(
            'INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
             VALUES (?, "apuesta", ?, ?, ?, ?, ?)'
        )->execute([
            $usuario['id'], $monto, $saldo_actual, $saldo_nuevo, $ref_tx,
            'Apuesta #' . $id_apuesta . ' - ' . $partido['equipo_local'] . ' vs ' . $partido['equipo_visita'],
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('danger', 'No se pudo procesar la apuesta. Intentá de nuevo.');
        redirect('/apostar.php?partido=' . $id_partido);
    }

    refrescar_usuario_sesion();
    flash('success', 'Apuesta registrada: ' . money($monto) . ' a ' . ucfirst($tipo_apuesta) . '. ¡Suerte!');
    redirect('/mis_apuestas.php');
}

$partido_seleccionado = $id_partido_get ? obtener_partido_apostable(db(), $id_partido_get) : null;

$partidos_programados = db()->query(
    "SELECT p.id, d.nombre AS deporte, lt.nombre AS liga, el.nombre AS equipo_local, ev.nombre AS equipo_visita,
            el.escudo AS escudo_local, ev.escudo AS escudo_visita,
            p.fecha_partido
     FROM partidos p
     JOIN deportes d ON d.id = p.id_deporte
     LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
     JOIN equipos el ON el.id = p.id_equipo_local
     JOIN equipos ev ON ev.id = p.id_equipo_visita
     WHERE p.estado = 'programado' AND p.fecha_partido > UTC_TIMESTAMP()
     ORDER BY p.fecha_partido ASC
     LIMIT 50"
)->fetchAll();

$titulo_pagina = 'Apostar';
require __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4"><i class="bi bi-cash-coin"></i> Apostar</h1>

<?php if ($id_partido_get && !$partido_seleccionado): ?>
  <div class="alert alert-warning">Ese partido no está disponible para apostar (ya empezó, terminó o no existe).</div>
<?php endif; ?>

<?php if ($partido_seleccionado): $p = $partido_seleccionado; ?>
  <div class="card mb-4 shadow-sm" data-cy="match-card">
    <div class="card-body">
      <span class="badge bg-dark badge-deporte mb-2"><?= e($p['deporte']) ?></span>
      <span class="text-muted small ms-1"><?= e($p['liga'] ?? 'Amistoso') ?></span>
      <h2 class="h4 d-flex align-items-center flex-wrap gap-2">
        <?= escudo_equipo($p['escudo_local'], $p['equipo_local'], 'lg') ?> <?= e($p['equipo_local']) ?>
        <span class="text-muted fs-6">vs</span>
        <?= escudo_equipo($p['escudo_visita'], $p['equipo_visita'], 'lg') ?> <?= e($p['equipo_visita']) ?>
      </h2>
      <p class="text-muted"><?= fecha_partido_legible($p['fecha_partido']) ?></p>

      <form method="post" class="form-apuesta">
        <?= csrf_field() ?>
        <input type="hidden" name="id_partido" value="<?= (int) $p['id'] ?>">
        <input type="hidden" name="tipo_apuesta" value="">
        <input type="hidden" name="cuota" value="">

        <label class="form-label fw-semibold">Elegí el resultado</label>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button type="button" class="btn btn-outline-dark cuota-btn" data-cy="cuota-local" data-tipo="local" data-cuota="<?= e($p['cuota_local']) ?>">
            <?= e($p['equipo_local']) ?><br><strong><?= e($p['cuota_local']) ?></strong>
          </button>
          <?php if ($p['tiene_empate'] && $p['cuota_empate'] !== null): ?>
            <button type="button" class="btn btn-outline-dark cuota-btn" data-tipo="empate" data-cuota="<?= e($p['cuota_empate']) ?>">
              Empate<br><strong><?= e($p['cuota_empate']) ?></strong>
            </button>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-dark cuota-btn" data-tipo="visita" data-cuota="<?= e($p['cuota_visita']) ?>">
            <?= e($p['equipo_visita']) ?><br><strong><?= e($p['cuota_visita']) ?></strong>
          </button>
        </div>

        <div class="mb-2">
          <label class="form-label">Monto a apostar (saldo: <?= money($usuario['saldo']) ?>)</label>
          <input type="number" name="monto" data-cy="input-monto" class="form-control" min="<?= MONTO_MINIMO ?>" max="<?= e((string) min((float) $usuario['saldo'], MONTO_MAXIMO)) ?>" step="0.01" placeholder="Mínimo <?= money(MONTO_MINIMO) ?>" required>
        </div>
        <p class="preview-ganancia fw-semibold text-success mb-3"></p>

        <button type="submit" data-cy="btn-confirmar-apuesta" class="btn btn-warning fw-semibold btn-confirmar-apuesta" disabled>
          <i class="bi bi-check-circle"></i> Confirmar apuesta
        </button>
      </form>
    </div>
  </div>
<?php endif; ?>

<h2 class="h5 mb-3">Elegí un partido</h2>
<?php if (!$partidos_programados): ?>
  <div class="alert alert-secondary">No hay partidos programados por ahora.</div>
<?php endif; ?>
<div class="list-group">
  <?php foreach ($partidos_programados as $pp): ?>
    <a href="<?= APP_URL ?>/apostar.php?partido=<?= (int) $pp['id'] ?>"
       data-cy="match-card"
       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $id_partido_get === (int) $pp['id'] ? 'active' : '' ?>">
      <span>
        <span class="badge bg-dark badge-deporte me-1"><?= e($pp['deporte']) ?></span>
        <?= escudo_equipo($pp['escudo_local'], $pp['equipo_local'], 'sm') ?> <?= e($pp['equipo_local']) ?>
        <span class="text-muted">vs</span>
        <?= escudo_equipo($pp['escudo_visita'], $pp['equipo_visita'], 'sm') ?> <?= e($pp['equipo_visita']) ?>
        <span class="text-muted small">(<?= e($pp['liga'] ?? 'Amistoso') ?>)</span>
      </span>
      <span class="small"><?= fecha_partido_legible($pp['fecha_partido']) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
