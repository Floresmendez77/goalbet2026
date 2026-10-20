<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Sincronizador.php';
requerir_admin();

$sincronizador = new Sincronizador();
$ligas = $sincronizador->obtenerTodasLigas();
$ultimosLogs = $sincronizador->obtenerUltimosLogs(25);

$fechaDefectoDesde = date('Y-m-d', strtotime('-7 days'));
$fechaDefectoHasta = date('Y-m-d', strtotime('+14 days'));

$titulo_pagina = 'Admin · Refrescar partidos';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-arrow-repeat"></i> Sincronización de Partidos (ESPN)</h1>
    <p class="text-muted small mb-0">Trae marcadores reales, liquida apuestas automáticamente vía trigger y actualiza calendario.</p>
  </div>
  <div>
    <a href="<?= APP_URL ?>/admin/partidos.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Volver a partidos</a>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-6">
    <div class="card h-100 shadow-sm">
      <div class="card-header bg-dark text-white fw-semibold">
        <i class="bi bi-sliders"></i> Opciones de sincronización
      </div>
      <div class="card-body">
        <form id="form-sincronizar" onsubmit="iniciarSincronizacion(event)">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Alcance</label>
            <select id="select-liga" class="form-select">
              <option value="todas">-- Todas las ligas activas (<?= count(array_filter($ligas, fn($l) => (int)$l['activa'] === 1)) ?>) --</option>
              <?php foreach ($ligas as $l): ?>
                <option value="<?= (int) $l['id_liga'] ?>" <?= (int)$l['activa'] === 0 ? 'class="text-muted"' : '' ?>>
                  <?= e($l['nombre']) ?> (<?= e(ucfirst($l['deporte'])) ?> · <?= e($l['slug_espn']) ?>) <?= (int)$l['activa'] === 0 ? '[Inactiva]' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Desde</label>
              <input type="date" id="input-desde" class="form-control" value="<?= $fechaDefectoDesde ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold">Hasta</label>
              <input type="date" id="input-hasta" class="form-control" value="<?= $fechaDefectoHasta ?>">
            </div>
          </div>

          <div class="alert alert-info small mb-3">
            <i class="bi bi-info-circle"></i> <strong>Automatización integrada:</strong> Al recibir marcadores finales, el trigger <code>trg_partidos_resolver_apuestas</code> liquida y paga las apuestas pendientes en el acto.
          </div>

          <button type="submit" id="btn-iniciar" class="btn btn-warning w-100 fw-bold py-2">
            <i class="bi bi-play-circle-fill"></i> Iniciar sincronización ahora
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100 shadow-sm">
      <div class="card-header bg-dark text-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-activity"></i> Progreso en vivo</span>
        <span id="badge-estado" class="badge bg-secondary">Inactivo</span>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <div class="d-flex justify-content-between small text-muted mb-1">
            <span id="label-progreso">Listo para sincronizar</span>
            <span id="porcentaje-progreso">0%</span>
          </div>
          <div class="progress" style="height: 18px;">
            <div id="barra-progreso" class="progress-bar progress-bar-striped progress-bar-animated bg-warning text-dark fw-bold" role="progressbar" style="width: 0%">0%</div>
          </div>
        </div>

        <div class="row text-center g-2 mb-3">
          <div class="col-4">
            <div class="p-2 border rounded bg-dark-subtle">
              <div class="small text-muted">Actualizados</div>
              <div class="fs-5 fw-bold text-success" id="cont-actualizados">0</div>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 border rounded bg-dark-subtle">
              <div class="small text-muted">Nuevos</div>
              <div class="fs-5 fw-bold text-info" id="cont-creados">0</div>
            </div>
          </div>
          <div class="col-4">
            <div class="p-2 border rounded bg-dark-subtle">
              <div class="small text-muted">Errores</div>
              <div class="fs-5 fw-bold text-danger" id="cont-errores">0</div>
            </div>
          </div>
        </div>

        <div class="card bg-black border-secondary">
          <div class="card-header py-1 small text-muted font-monospace d-flex justify-content-between">
            <span>Registro de eventos</span>
            <button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="document.getElementById('log-consola').innerHTML=''">Limpiar</button>
          </div>
          <div id="log-consola" class="card-body p-2 font-monospace small text-light" style="max-height: 160px; overflow-y: auto; font-size: 0.8rem;">
            <div class="text-muted">Esperando inicio...</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header bg-dark text-white fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clock-history"></i> Historial de sincronizaciones recientes</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-striped align-middle mb-0 small">
        <thead class="table-dark">
          <tr>
            <th>Fecha</th>
            <th>Usuario/Origen</th>
            <th>Liga</th>
            <th>Actualizados</th>
            <th>Nuevos</th>
            <th>Errores</th>
            <th>Detalle</th>
          </tr>
        </thead>
        <tbody id="tbody-logs">
          <?php if (empty($ultimosLogs)): ?>
            <tr><td colspan="7" class="text-center text-muted py-3">No hay registros de sincronización aún.</td></tr>
          <?php else: ?>
            <?php foreach ($ultimosLogs as $log): ?>
              <tr>
                <td class="text-nowrap"><?= fecha_legible($log['fecha']) ?></td>
                <td><span class="badge bg-secondary"><?= e($log['usuario_admin']) ?></span></td>
                <td class="fw-semibold"><?= e($log['liga']) ?></td>
                <td class="text-success fw-bold"><?= (int) $log['resultados_actualizados'] ?></td>
                <td class="text-info fw-bold"><?= (int) $log['partidos_nuevos'] ?></td>
                <td>
                  <?php if ((int)$log['errores'] > 0): ?>
                    <span class="badge bg-danger"><?= (int) $log['errores'] ?></span>
                  <?php else: ?>
                    <span class="badge bg-success">0</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= e($log['detalle']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
var csrfToken = <?= json_encode(csrf_token()) ?>;
var todasLasLigas = <?= json_encode(array_values(array_filter($ligas, fn($l) => (int)$l['activa'] === 1))) ?>;

function agregarLog(msg, tipo = 'info') {
  var consola = document.getElementById('log-consola');
  var d = new Date();
  var hora = d.toLocaleTimeString();
  var color = tipo === 'success' ? '#10b981' : (tipo === 'danger' ? '#ef4444' : '#94a3b8');
  var div = document.createElement('div');
  div.style.color = color;
  div.innerHTML = '[' + hora + '] ' + msg;
  consola.appendChild(div);
  consola.scrollTop = consola.scrollHeight;
}

async function iniciarSincronizacion(e) {
  e.preventDefault();
  var btn = document.getElementById('btn-iniciar');
  var select = document.getElementById('select-liga');
  var desde = document.getElementById('input-desde').value;
  var hasta = document.getElementById('input-hasta').value;
  var barra = document.getElementById('barra-progreso');
  var badge = document.getElementById('badge-estado');
  var labelProgreso = document.getElementById('label-progreso');
  var labelPorcentaje = document.getElementById('porcentaje-progreso');

  var contAct = document.getElementById('cont-actualizados');
  var contCre = document.getElementById('cont-creados');
  var contErr = document.getElementById('cont-errores');

  var totalActualizados = 0;
  var totalCreados = 0;
  var totalErrores = 0;

  var cola = [];
  if (select.value === 'todas') {
    cola = todasLasLigas.slice();
  } else {
    var idSel = parseInt(select.value, 10);
    var encontrada = todasLasLigas.find(l => parseInt(l.id_liga, 10) === idSel);
    if (!encontrada) {
      // Buscar entre todas si estaba inactiva
      var todas = <?= json_encode($ligas) ?>;
      encontrada = todas.find(l => parseInt(l.id_liga, 10) === idSel);
    }
    if (encontrada) {
      cola = [encontrada];
    }
  }

  if (cola.length === 0) {
    alert('No hay ligas seleccionadas para sincronizar.');
    return;
  }

  btn.disabled = true;
  badge.className = 'badge bg-warning text-dark';
  badge.textContent = 'En progreso';
  agregarLog('Iniciando proceso para ' + cola.length + ' liga(s)...');

  var total = cola.length;
  for (var i = 0; i < total; i++) {
    var l = cola[i];
    var pct = Math.round((i / total) * 100);
    barra.style.width = pct + '%';
    barra.textContent = pct + '%';
    labelPorcentaje.textContent = pct + '%';
    labelProgreso.textContent = 'Sincronizando: ' + l.nombre + ' (' + (i + 1) + '/' + total + ')...';

    agregarLog('Consultando ESPN para ' + l.nombre + ' (' + l.slug_espn + ')...');

    try {
      var formData = new FormData();
      formData.append('csrf_token', csrfToken);
      formData.append('id_liga', l.id_liga);
      formData.append('desde', desde);
      formData.append('hasta', hasta);

      var resp = await fetch('<?= APP_URL ?>/admin/api_sincronizar.php?action=sincronizar_liga', {
        method: 'POST',
        body: formData
      });
      var txt = await resp.text();
      var data;
      try {
        data = JSON.parse(txt);
      } catch (e) {
        var vista = txt ? txt.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) : '(respuesta vacía: revisá seeder/logs/php_errors.log)';
        throw new Error('HTTP ' + resp.status + ' - ' + vista);
      }

      if (data.exito) {
        totalActualizados += (data.actualizados || 0);
        totalCreados += (data.creados || 0);
        agregarLog(l.nombre + ': ' + data.actualizados + ' actualizados, ' + data.creados + ' creados.', 'success');
      } else {
        totalErrores++;
        agregarLog('Error en ' + l.nombre + ': ' + (data.error || 'Fallo desconocido'), 'danger');
      }
    } catch (err) {
      totalErrores++;
      agregarLog('Fallo de red en ' + l.nombre + ': ' + err.message, 'danger');
    }

    contAct.textContent = totalActualizados;
    contCre.textContent = totalCreados;
    contErr.textContent = totalErrores;
  }

  barra.style.width = '100%';
  barra.textContent = '100%';
  labelPorcentaje.textContent = '100%';
  labelProgreso.textContent = 'Sincronización completada';
  badge.className = totalErrores > 0 ? 'badge bg-warning text-dark' : 'badge bg-success';
  badge.textContent = totalErrores > 0 ? 'Completado con advertencias' : 'Finalizado con éxito';
  btn.disabled = false;

  agregarLog('Proceso finalizado. Total: ' + totalActualizados + ' actualizados, ' + totalCreados + ' creados, ' + totalErrores + ' errores.', 'success');
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
