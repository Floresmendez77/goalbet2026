<?php
require_once __DIR__ . '/includes/bootstrap.php';
requerir_login();
refrescar_usuario_sesion();

// GAP-002: apuestas combinadas / parlays.
// Ver database/migration_boletos_combinados.sql para la lógica de liquidación
// y reembolso en MySQL, y qa/bugs/GAP-002.md para el diagnóstico original.
//
// Convención importante: cada fila de `apuestas` que es pata de un boleto
// (id_boleto NO NULL) guarda en su columna `monto` el monto TOTAL del
// boleto (no una fracción), porque `apuestas.monto` no puede ser 0 (trigger
// trg_apuestas_validar_ins) y la app no reparte el stake entre patas. El
// pago real del boleto se calcula y se paga UNA sola vez desde `boletos`
// (monto * cuota_total), nunca desde `apuestas.ganancia_potencial` de una
// pata individual — por eso esa columna no se usa para mostrar "ganancia"
// en las patas de un boleto (ver mis_apuestas.php).

const MONTO_MINIMO_COMBINADA = 20.00;
define('MONTO_MAXIMO_COMBINADA', (float) (Env::get('MONTO_MAXIMO_APUESTA', '5000')));
const MIN_PATAS = 2;
const MAX_PATAS = 8;

$usuario = usuario_actual();

/** Trae un partido 'programado' con nombres de equipos/liga, o null. */
function obtener_partido_apostable_combo(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.*, d.nombre AS deporte, d.tiene_empate, lt.nombre AS liga,
                el.nombre AS equipo_local, ev.nombre AS equipo_visita
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

    $patas_raw = (string) ($_POST['patas'] ?? '[]');
    $monto     = (float) ($_POST['monto'] ?? 0);
    $patas     = json_decode($patas_raw, true);

    if (!is_array($patas) || count($patas) < MIN_PATAS || count($patas) > MAX_PATAS) {
        flash('danger', 'Un boleto combinado debe tener entre ' . MIN_PATAS . ' y ' . MAX_PATAS . ' partidos.');
        redirect('/apostar_combinada.php');
    }

    $ids_partido = array_map(fn($p) => (int) ($p['id_partido'] ?? 0), $patas);
    if (count($ids_partido) !== count(array_unique($ids_partido))) {
        flash('danger', 'No podés incluir el mismo partido dos veces en un boleto combinado.');
        redirect('/apostar_combinada.php');
    }

    if ($monto < MONTO_MINIMO_COMBINADA) {
        flash('danger', 'El monto mínimo por boleto combinado es ' . money(MONTO_MINIMO_COMBINADA) . '.');
        redirect('/apostar_combinada.php');
    }
    if ($monto > MONTO_MAXIMO_COMBINADA) {
        flash('danger', 'El monto máximo por boleto combinado es ' . money(MONTO_MAXIMO_COMBINADA) . '.');
        redirect('/apostar_combinada.php');
    }
    if ($monto > (float) $usuario['saldo']) {
        flash('danger', 'No tenés saldo suficiente para ese boleto.');
        redirect('/apostar_combinada.php');
    }

    // Re-validar cada pata contra la BD: nunca se confía en la cuota que
    // manda el cliente (mismo principio anti-manipulación que apostar.php).
    $pdo = db();
    $patas_validadas = [];
    $cuota_total = 1.0;

    foreach ($patas as $p) {
        $id_partido = (int) ($p['id_partido'] ?? 0);
        $tipo       = (string) ($p['tipo_apuesta'] ?? '');

        $partido = obtener_partido_apostable_combo($pdo, $id_partido);
        if (!$partido) {
            flash('danger', 'Uno de los partidos elegidos ya no está disponible para apostar.');
            redirect('/apostar_combinada.php');
        }
        if (!in_array($tipo, ['local', 'empate', 'visita'], true)) {
            flash('danger', 'Elegí un resultado válido en cada partido del boleto.');
            redirect('/apostar_combinada.php');
        }
        if ($tipo === 'empate' && !$partido['tiene_empate']) {
            flash('danger', 'Uno de los deportes elegidos no admite apuestas a empate.');
            redirect('/apostar_combinada.php');
        }

        $cuota_real = match ($tipo) {
            'local'  => $partido['cuota_local'],
            'empate' => $partido['cuota_empate'],
            'visita' => $partido['cuota_visita'],
        };
        if ($cuota_real === null) {
            flash('warning', 'Una de las cuotas cambió, rearmá el boleto antes de confirmar de nuevo.');
            redirect('/apostar_combinada.php');
        }

        $cuota_real = (float) $cuota_real;
        $cuota_total *= $cuota_real;
        $patas_validadas[] = [
            'id_partido'   => $id_partido,
            'tipo_apuesta' => $tipo,
            'cuota'        => $cuota_real,
        ];
    }

    $cuota_total = round($cuota_total, 2);

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

        $ref_boleto = generar_referencia('BOL');
        $pdo->prepare(
            'INSERT INTO boletos (id_usuario, monto, cuota_total, referencia) VALUES (?, ?, ?, ?)'
        )->execute([$usuario['id'], $monto, $cuota_total, $ref_boleto]);
        $id_boleto = (int) $pdo->lastInsertId();

        foreach ($patas_validadas as $pv) {
            $ref_apuesta = generar_referencia('APU');
            $pdo->prepare(
                'INSERT INTO apuestas (id_usuario, id_partido, tipo_apuesta, monto, cuota, referencia, id_boleto)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $usuario['id'], $pv['id_partido'], $pv['tipo_apuesta'], $monto, $pv['cuota'], $ref_apuesta, $id_boleto,
            ]);
        }

        $ref_tx = generar_referencia('TXB');
        $pdo->prepare(
            'INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
             VALUES (?, "apuesta_combinada", ?, ?, ?, ?, ?)'
        )->execute([
            $usuario['id'], $monto, $saldo_actual, $saldo_nuevo, $ref_tx,
            'Boleto combinado #' . $id_boleto . ' (' . count($patas_validadas) . ' partidos, cuota total ' . $cuota_total . ')',
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', 'No se pudo procesar el boleto combinado. Intentá de nuevo.');
        redirect('/apostar_combinada.php');
    }

    refrescar_usuario_sesion();
    flash('success', 'Boleto combinado registrado: ' . money($monto) . ' a ' . count($patas_validadas) . ' partidos (cuota total x' . number_format($cuota_total, 2) . '). ¡Suerte!');
    redirect('/mis_apuestas.php');
}

$partidos_programados = db()->query(
    "SELECT p.id, d.nombre AS deporte, d.tiene_empate, lt.nombre AS liga,
            el.nombre AS equipo_local, ev.nombre AS equipo_visita,
            p.fecha_partido, p.cuota_local, p.cuota_empate, p.cuota_visita
     FROM partidos p
     JOIN deportes d ON d.id = p.id_deporte
     LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
     JOIN equipos el ON el.id = p.id_equipo_local
     JOIN equipos ev ON ev.id = p.id_equipo_visita
     WHERE p.estado = 'programado' AND p.fecha_partido > UTC_TIMESTAMP()
     ORDER BY p.fecha_partido ASC
     LIMIT 50"
)->fetchAll();

$titulo_pagina = 'Boleto combinado';
require __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-2"><i class="bi bi-layers-fill"></i> Boleto combinado</h1>
<p class="text-muted mb-4">Elegí entre <?= MIN_PATAS ?> y <?= MAX_PATAS ?> partidos. El boleto paga la cuota combinada
  (el producto de todas las cuotas elegidas) solo si <strong>todos</strong> los resultados aciertan.</p>

<?php if (!$partidos_programados): ?>
  <div class="alert alert-secondary">No hay partidos programados por ahora.</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="list-group" id="lista-partidos-combo">
      <?php foreach ($partidos_programados as $p): ?>
        <div class="list-group-item" data-cy="match-card-combo">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <span class="badge bg-dark badge-deporte me-1"><?= e($p['deporte']) ?></span>
              <strong><?= e($p['equipo_local']) ?> vs <?= e($p['equipo_visita']) ?></strong>
              <div class="text-muted small"><?= e($p['liga'] ?? 'Amistoso') ?> · <?= fecha_partido_legible($p['fecha_partido']) ?></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-sm btn-outline-dark boleto-pata-btn" data-cy="combo-local"
                      data-id-partido="<?= (int) $p['id'] ?>"
                      data-etiqueta="<?= e($p['equipo_local']) ?> vs <?= e($p['equipo_visita']) ?>"
                      data-tipo="local" data-tipo-label="<?= e($p['equipo_local']) ?>" data-cuota="<?= e($p['cuota_local']) ?>">
                <?= e($p['equipo_local']) ?> <strong><?= e($p['cuota_local']) ?></strong>
              </button>
              <?php if ($p['tiene_empate'] && $p['cuota_empate'] !== null): ?>
                <button type="button" class="btn btn-sm btn-outline-dark boleto-pata-btn" data-cy="combo-empate"
                        data-id-partido="<?= (int) $p['id'] ?>"
                        data-etiqueta="<?= e($p['equipo_local']) ?> vs <?= e($p['equipo_visita']) ?>"
                        data-tipo="empate" data-tipo-label="Empate" data-cuota="<?= e($p['cuota_empate']) ?>">
                  Empate <strong><?= e($p['cuota_empate']) ?></strong>
                </button>
              <?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-dark boleto-pata-btn" data-cy="combo-visita"
                      data-id-partido="<?= (int) $p['id'] ?>"
                      data-etiqueta="<?= e($p['equipo_local']) ?> vs <?= e($p['equipo_visita']) ?>"
                      data-tipo="visita" data-tipo-label="<?= e($p['equipo_visita']) ?>" data-cuota="<?= e($p['cuota_visita']) ?>">
                <?= e($p['equipo_visita']) ?> <strong><?= e($p['cuota_visita']) ?></strong>
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card shadow-sm" style="position: sticky; top: 90px;">
      <div class="card-body">
        <h2 class="h5">Tu boleto</h2>
        <ul class="list-group list-group-flush mb-3" id="boleto-patas-lista">
          <li class="list-group-item text-muted small" id="boleto-vacio">Elegí al menos <?= MIN_PATAS ?> resultados.</li>
        </ul>
        <form method="post" id="form-boleto">
          <?= csrf_field() ?>
          <input type="hidden" name="patas" id="input-patas" value="[]">
          <p class="mb-1 small">Cuota total: <strong id="preview-cuota-total">—</strong></p>
          <div class="mb-2">
            <label class="form-label">Monto a apostar (saldo: <?= money($usuario['saldo']) ?>)</label>
            <input type="number" name="monto" id="input-monto-boleto" data-cy="input-monto-combo" class="form-control"
                   min="<?= MONTO_MINIMO_COMBINADA ?>" max="<?= e((string) $usuario['saldo']) ?>" step="0.01"
                   placeholder="Mínimo <?= money(MONTO_MINIMO_COMBINADA) ?>" required>
          </div>
          <p class="fw-semibold text-success mb-3" id="preview-ganancia-boleto"></p>
          <button type="submit" class="btn btn-warning w-100 fw-semibold" id="btn-confirmar-boleto" disabled data-cy="btn-confirmar-boleto">
            <i class="bi bi-check-circle"></i> Confirmar boleto
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var MIN_PATAS = <?= MIN_PATAS ?>;
  var MAX_PATAS = <?= MAX_PATAS ?>;
  var patas = {}; // id_partido -> { id_partido, tipo_apuesta, cuota, etiqueta, tipoLabel, btn }

  var lista = document.getElementById('boleto-patas-lista');
  var vacio = document.getElementById('boleto-vacio');
  var inputPatas = document.getElementById('input-patas');
  var inputMonto = document.getElementById('input-monto-boleto');
  var previewCuota = document.getElementById('preview-cuota-total');
  var previewGanancia = document.getElementById('preview-ganancia-boleto');
  var btnConfirmar = document.getElementById('btn-confirmar-boleto');

  function cuotaTotal() {
    var total = 1;
    var n = 0;
    for (var k in patas) {
      total *= parseFloat(patas[k].cuota);
      n++;
    }
    return n > 0 ? total : 0;
  }

  function render() {
    var keys = Object.keys(patas);
    lista.innerHTML = '';

    if (keys.length === 0) {
      lista.appendChild(vacio);
    } else {
      keys.forEach(function (k) {
        var pata = patas[k];
        var li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-start small';
        li.innerHTML = '<span>' + pata.etiqueta + '<br><strong>' + pata.tipoLabel + '</strong> @' + pata.cuota + '</span>';
        var btnQuitar = document.createElement('button');
        btnQuitar.type = 'button';
        btnQuitar.className = 'btn btn-sm btn-link text-danger p-0 ms-2';
        btnQuitar.innerHTML = '<i class="bi bi-x-circle"></i>';
        btnQuitar.addEventListener('click', function () {
          if (pata.btn) pata.btn.classList.remove('active', 'btn-dark');
          if (pata.btn) pata.btn.classList.add('btn-outline-dark');
          delete patas[k];
          render();
        });
        li.appendChild(btnQuitar);
        lista.appendChild(li);
      });
    }

    var total = cuotaTotal();
    var n = keys.length;
    previewCuota.textContent = n > 0 ? 'x' + total.toFixed(2) : '—';

    var monto = parseFloat(inputMonto.value || '0');
    if (n >= MIN_PATAS && total > 0 && monto > 0) {
      previewGanancia.textContent = 'Ganancia potencial: L. ' + (total * monto).toFixed(2);
    } else {
      previewGanancia.textContent = '';
    }

    btnConfirmar.disabled = !(n >= MIN_PATAS && n <= MAX_PATAS && monto > 0);

    inputPatas.value = JSON.stringify(keys.map(function (k) {
      return { id_partido: patas[k].id_partido, tipo_apuesta: patas[k].tipo_apuesta };
    }));
  }

  document.querySelectorAll('.boleto-pata-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var idPartido = btn.dataset.idPartido;
      var yaElegido = patas[idPartido];

      // Un solo resultado por partido: al elegir otro botón del mismo partido,
      // se reemplaza la selección anterior.
      if (yaElegido && yaElegido.tipo_apuesta === btn.dataset.tipo) {
        // Click sobre la misma selección: la quita.
        if (yaElegido.btn) {
          yaElegido.btn.classList.remove('active', 'btn-dark');
          yaElegido.btn.classList.add('btn-outline-dark');
        }
        delete patas[idPartido];
        render();
        return;
      }

      if (yaElegido && yaElegido.btn) {
        yaElegido.btn.classList.remove('active', 'btn-dark');
        yaElegido.btn.classList.add('btn-outline-dark');
      }

      if (!yaElegido && Object.keys(patas).length >= MAX_PATAS) {
        alert('Un boleto combinado admite como máximo ' + MAX_PATAS + ' partidos.');
        return;
      }

      btn.classList.remove('btn-outline-dark');
      btn.classList.add('active', 'btn-dark');

      patas[idPartido] = {
        id_partido: parseInt(idPartido, 10),
        tipo_apuesta: btn.dataset.tipo,
        cuota: btn.dataset.cuota,
        etiqueta: btn.dataset.etiqueta,
        tipoLabel: btn.dataset.tipoLabel,
        btn: btn,
      };
      render();
    });
  });

  inputMonto.addEventListener('input', render);

  render();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
