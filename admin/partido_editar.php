<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requerir_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$pdo = db();

function cargar_partido(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.*, d.nombre AS deporte, d.tiene_empate, lt.nombre AS liga,
                el.nombre AS equipo_local, ev.nombre AS equipo_visita
         FROM partidos p
         JOIN deportes d ON d.id = p.id_deporte
         LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
         JOIN equipos el ON el.id = p.id_equipo_local
         JOIN equipos ev ON ev.id = p.id_equipo_visita
         WHERE p.id = ?"
    );
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

$partido = cargar_partido($pdo, $id);
if (!$partido) {
    flash('danger', 'Partido no encontrado.');
    redirect('/admin/partidos.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'cuotas') {
        $cuota_local  = (float) $_POST['cuota_local'];
        $cuota_empate = $partido['tiene_empate'] ? (float) $_POST['cuota_empate'] : null;
        $cuota_visita = (float) $_POST['cuota_visita'];

        if ($cuota_local < 1.01 || $cuota_visita < 1.01 || ($cuota_empate !== null && $cuota_empate < 1.01)) {
            flash('danger', 'Las cuotas deben ser mayores o iguales a 1.01.');
        } else {
            $pdo->prepare('UPDATE partidos SET cuota_local = ?, cuota_empate = ?, cuota_visita = ? WHERE id = ?')
                ->execute([$cuota_local, $cuota_empate, $cuota_visita, $id]);
            flash('success', 'Cuotas actualizadas (queda registrado en el historial de cuotas).');
        }
        redirect('/admin/partido_editar.php?id=' . $id);
    }

    if ($accion === 'estado') {
        $nuevo_estado = $_POST['estado'] ?? '';
        if (!in_array($nuevo_estado, ['programado', 'en_curso', 'pospuesto', 'cancelado', 'suspendido'], true)) {
            flash('danger', 'Estado inválido.');
            redirect('/admin/partido_editar.php?id=' . $id);
        }

        // Retry con backoff aleatorio ante deadlocks InnoDB (1213 / SQLSTATE 40001).
        // Ver qa/bugs/BUG-001.md Sección 5: apuestas_ibfk_2 hace que este UPDATE
        // compita por locks con la cancelación de apuestas del cliente.
        $maxIntentos = 3;
        $exito = false;

        for ($intento = 1; $intento <= $maxIntentos; $intento++) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE partidos SET estado = ? WHERE id = ?')->execute([$nuevo_estado, $id]);
                $pdo->commit();

                if ($intento > 1) {
                    error_log(sprintf(
                        'partido_editar.php: UPDATE estado partido #%d tuvo éxito en el intento %d (deadlock recuperado por retry)',
                        $id, $intento
                    ));
                }

                $exito = true;
                break;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $esDeadlock = ($e instanceof PDOException && (string) $e->getCode() === '40001');
                if ($esDeadlock && $intento < $maxIntentos) {
                    usleep(random_int(50000, 150000)); // backoff aleatorio 50-150ms
                    continue;
                }

                error_log(sprintf(
                    'partido_editar.php: UPDATE estado partido #%d falló tras %d intento(s): %s',
                    $id, $intento, $e->getMessage()
                ));
                break;
            }
        }

        if ($exito) {
            flash('success', 'Estado actualizado a "' . $nuevo_estado . '".');
        } else {
            flash('danger', 'No se pudo actualizar el estado (conflicto de concurrencia). Por favor, reintentá.');
        }
        redirect('/admin/partido_editar.php?id=' . $id);
    }

    if ($accion === 'resultado') {
        $marcador_local_raw  = trim((string) ($_POST['marcador_local'] ?? ''));
        $marcador_visita_raw = trim((string) ($_POST['marcador_visita'] ?? ''));

        if ($marcador_local_raw === '' || $marcador_visita_raw === '') {
            flash('danger', 'No se puede finalizar un partido sin ingresar los marcadores de ambos equipos.');
            redirect('/admin/partido_editar.php?id=' . $id);
        }

        $marcador_local  = (int) $marcador_local_raw;
        $marcador_visita = (int) $marcador_visita_raw;

        if ($marcador_local < 0 || $marcador_visita < 0) {
            flash('danger', 'El marcador no puede ser negativo.');
            redirect('/admin/partido_editar.php?id=' . $id);
        }

        // Un solo UPDATE: el trigger trg_partidos_resolver_apuestas se encarga
        // de liquidar todas las apuestas de este partido automáticamente.
        $pdo->prepare(
            'UPDATE partidos SET marcador_local = ?, marcador_visita = ?, estado = "finalizado" WHERE id = ?'
        )->execute([$marcador_local, $marcador_visita, $id]);

        flash('success', 'Resultado cargado y partido finalizado. Las apuestas se liquidaron automáticamente.');
        redirect('/admin/partido_editar.php?id=' . $id);
    }
}

$partido = cargar_partido($pdo, $id);

// Apuestas de este partido, para que el admin vea el impacto de la liquidación.
$stmt = $pdo->prepare(
    "SELECT a.*, u.username FROM apuestas a JOIN usuarios u ON u.id = a.id_usuario
     WHERE a.id_partido = ? ORDER BY a.created_at DESC"
);
$stmt->execute([$id]);
$apuestas = $stmt->fetchAll();

$titulo_pagina = 'Editar partido';
require __DIR__ . '/../includes/header.php';
?>

<a href="<?= APP_URL ?>/admin/partidos.php" class="small">← Volver al listado</a>
<h1 class="h3 mt-2 mb-4">
  <?= e($partido['equipo_local']) ?> vs <?= e($partido['equipo_visita']) ?>
  <?= badge_estado($partido['estado']) ?>
</h1>
<p class="text-muted">
  <?= e($partido['deporte']) ?> · <?= e($partido['liga'] ?? 'Amistoso') ?> · <?= fecha_partido_legible($partido['fecha_partido']) ?>
</p>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h5">Cargar resultado final</h2>
        <?php if ($partido['estado'] === 'finalizado'): ?>
          <div class="alert alert-success small mb-3">
            Este partido ya está finalizado (<?= (int) $partido['marcador_local'] ?> - <?= (int) $partido['marcador_visita'] ?>).
            Podés volver a guardar el mismo resultado sin riesgo: el trigger solo liquida apuestas <em>pendientes</em>,
            así que no se vuelve a pagar dos veces.
          </div>
        <?php endif; ?>
        <form method="post" data-confirm="¿Confirmás finalizar el partido con este resultado? Esto liquida las apuestas automáticamente.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="accion" value="resultado">
          <div class="row g-2 align-items-end">
            <div class="col-5">
              <label class="form-label small"><?= e($partido['equipo_local']) ?></label>
              <input type="number" name="marcador_local" class="form-control" min="0" value="<?= (int) ($partido['marcador_local'] ?? 0) ?>" required>
            </div>
            <div class="col-2 text-center fw-bold">VS</div>
            <div class="col-5">
              <label class="form-label small"><?= e($partido['equipo_visita']) ?></label>
              <input type="number" name="marcador_visita" class="form-control" min="0" value="<?= (int) ($partido['marcador_visita'] ?? 0) ?>" required>
            </div>
          </div>
          <button type="submit" class="btn btn-danger w-100 mt-3 fw-semibold">
            <i class="bi bi-flag-fill"></i> Guardar y finalizar partido
          </button>
        </form>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h5">Cambiar estado</h2>
        <form method="post" class="d-flex gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="accion" value="estado">
          <select name="estado" class="form-select">
            <?php foreach (['programado', 'en_curso', 'pospuesto', 'cancelado', 'suspendido'] as $es): ?>
              <option value="<?= $es ?>" <?= $partido['estado'] === $es ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $es)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline-dark">Guardar</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h2 class="h5">Cuotas</h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="accion" value="cuotas">
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label small">Local</label>
              <input type="number" step="0.01" min="1.01" name="cuota_local" class="form-control" value="<?= e($partido['cuota_local']) ?>" required>
            </div>
            <div class="col-4">
              <label class="form-label small">Empate</label>
              <input type="number" step="0.01" min="1.01" name="cuota_empate" class="form-control" value="<?= e($partido['cuota_empate'] ?? '') ?>" <?= $partido['tiene_empate'] ? 'required' : 'disabled' ?>>
            </div>
            <div class="col-4">
              <label class="form-label small">Visita</label>
              <input type="number" step="0.01" min="1.01" name="cuota_visita" class="form-control" value="<?= e($partido['cuota_visita']) ?>" required>
            </div>
          </div>
          <button type="submit" class="btn btn-outline-dark w-100 mt-3">Actualizar cuotas</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <h2 class="h5 mb-3">Apuestas de este partido (<?= count($apuestas) ?>)</h2>
    <?php if (!$apuestas): ?>
      <div class="alert alert-secondary">Nadie apostó a este partido todavía.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover bg-white align-middle">
          <thead class="table-dark">
            <tr><th>Usuario</th><th>Tipo</th><th>Monto</th><th>Cuota</th><th>Estado</th></tr>
          </thead>
          <tbody>
            <?php foreach ($apuestas as $a): ?>
              <tr>
                <td><?= e($a['username']) ?></td>
                <td><?= e(ucfirst($a['tipo_apuesta'])) ?></td>
                <td><?= money($a['monto']) ?></td>
                <td><?= e($a['cuota']) ?></td>
                <td><?= badge_estado($a['estado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
