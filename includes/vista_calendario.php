<?php
/**
 * Vista "Calendario" de partidos con equipo favorito.
 * Se incluye desde partidos.php (ya cargó header, $pdo, $uid y $prefs).
 */

$tz = cal_tz();
$ahora = new DateTimeImmutable('now', $tz);
$hoyStr = $ahora->format('Y-m-d');

$favoritos = cal_favoritos($pdo, $uid);
$favIds = array_map(static fn($f) => (int) $f['id'], $favoritos);

// ---- Mes que se muestra ----
if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', (string) ($_GET['mes'] ?? ''), $m)) {
    $anio = (int) $m[1];
    $mes = (int) $m[2];
} else {
    $anio = (int) $ahora->format('Y');
    $mes = (int) $ahora->format('n');
}
$mesStr = sprintf('%04d-%02d', $anio, $mes);
$primero = new DateTimeImmutable($mesStr . '-01', $tz);
$ultimo = $primero->modify('last day of this month');
$mesPrev = $primero->modify('-1 month')->format('Y-m');
$mesNext = $primero->modify('+1 month')->format('Y-m');

// ---- Qué equipos se muestran: mis equipos / uno solo / todos ----
$equipoGet = (string) ($_GET['equipo'] ?? '');
if ($equipoGet === 'todos') {
    $modo = 'todos';
} elseif (ctype_digit($equipoGet) && in_array((int) $equipoGet, $favIds, true)) {
    $modo = 'uno';
} elseif ($equipoGet === 'mis' && $favIds) {
    $modo = 'mis';
} else {
    $modo = $favIds ? 'mis' : 'todos';
}
$ids = match ($modo) {
    'todos' => null,
    'uno'   => [(int) $equipoGet],
    default => $favIds,
};
$equipoParam = $modo === 'uno' ? (string) (int) $equipoGet : $modo;
$deportes = $pdo->query('SELECT id, nombre FROM deportes ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
$deporte = ($modo === 'todos' && isset($_GET['deporte'])) ? (int) $_GET['deporte'] : 0;

// ---- Partidos del mes (con margen para los días de las semanas vecinas) ----
$rangoDesde = $primero->modify('-7 days')->setTime(0, 0);
$rangoHasta = $ultimo->modify('+8 days')->setTime(0, 0);
$partidos = cal_partidos_rango($pdo, $rangoDesde, $rangoHasta, $ids, $deporte ?: null);
$porDia = cal_agrupar_por_dia($partidos);
$semanas = cal_grid($anio, $mes, $porDia, $ahora);
$delMes = array_values(array_filter($partidos, static fn($p) => str_starts_with($p['_dia'], $mesStr)));
$resumen = $ids ? cal_resumen($delMes, $ids) : null;
$prox = $ids ? cal_proximo_partido($pdo, $ids) : null;

// ---- Día seleccionado ----
$sel = null;
$diaGet = (string) ($_GET['dia'] ?? '');
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $diaGet, $d) && checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
    $sel = $diaGet;
}
if ($sel === null) {
    if ($prox && str_starts_with($prox['_dia'], $mesStr)) {
        $sel = $prox['_dia'];
    } elseif (str_starts_with($hoyStr, $mesStr)) {
        $sel = $hoyStr;
    } else {
        $dias = array_filter(array_keys($porDia), static fn($k) => str_starts_with($k, $mesStr));
        sort($dias);
        $sel = $dias[0] ?? $mesStr . '-01';
    }
}
$selFecha = new DateTimeImmutable($sel, $tz);
$delDia = $porDia[$sel] ?? [];

$u = static function (array $over = []) use ($mesStr, $equipoParam, $deporte, $sel): string {
    $q = array_merge(['vista' => 'calendario', 'mes' => $mesStr, 'equipo' => $equipoParam, 'deporte' => $deporte ?: null, 'dia' => $sel], $over);
    return '?' . http_build_query(array_filter($q, static fn($v) => $v !== null && $v !== ''));
};

$elegibles = array_map(static fn($t) => [
    'id' => (int) $t['id'], 'n' => $t['nombre'], 'e' => $t['escudo'], 'd' => $t['deporte'], 'l' => $t['ligas'],
], cal_equipos_elegibles($pdo));
$preguntar = !$favoritos && !$prefs['favoritos_omitido'];
$etiquetaRes = ['G' => 'Ganó', 'E' => 'Empató', 'P' => 'Perdió'];
?>
<link href="<?= APP_URL ?>/public/css/calendario.css" rel="stylesheet">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0"><i class="bi bi-calendar-event"></i> Partidos</h1>
  <div class="cal-toggle" role="group" aria-label="Vista">
    <a href="?vista=calendario" class="cal-toggle-btn active"><i class="bi bi-calendar3"></i> Calendario</a>
    <a href="?vista=lista" class="cal-toggle-btn"><i class="bi bi-list-ul"></i> Lista</a>
  </div>
</div>

<!-- Equipos: elegir qué calendario ver -->
<div class="cal-chips mb-3">
  <?php if ($favoritos): ?>
    <a class="cal-chip <?= $modo === 'mis' ? 'active' : '' ?>" href="<?= e($u(['equipo' => 'mis', 'dia' => null, 'deporte' => null])) ?>">
      <i class="bi bi-star-fill"></i> Mis equipos
    </a>
    <?php foreach ($favoritos as $f): ?>
      <span class="cal-chip-wrap <?= $modo === 'uno' && (int) $equipoGet === (int) $f['id'] ? 'active' : '' ?>">
        <a class="cal-chip" href="<?= e($u(['equipo' => (string) $f['id'], 'dia' => null, 'deporte' => null])) ?>">
          <?= escudo_equipo($f['escudo'], $f['nombre'], 'sm') ?> <?= e($f['nombre']) ?>
        </a>
        <form method="post" action="<?= APP_URL ?>/favoritos.php" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="quitar">
          <input type="hidden" name="id_equipo" value="<?= (int) $f['id'] ?>">
          <button type="submit" class="cal-chip-x" title="Quitar de favoritos" aria-label="Quitar <?= e($f['nombre']) ?> de favoritos">&times;</button>
        </form>
      </span>
    <?php endforeach; ?>
  <?php endif; ?>
  <a class="cal-chip <?= $modo === 'todos' ? 'active' : '' ?>" href="<?= e($u(['equipo' => 'todos', 'dia' => null])) ?>">
    <i class="bi bi-globe2"></i> Todos los partidos
  </a>
  <button type="button" class="cal-chip cal-chip-add" data-bs-toggle="modal" data-bs-target="#modalFavorito">
    <i class="bi bi-plus-lg"></i> <?= $favoritos ? 'Agregar equipo' : 'Elegir mi equipo' ?>
  </button>
  <?php if ($modo === 'todos'): ?>
    <form method="get" class="d-inline-flex">
      <input type="hidden" name="vista" value="calendario">
      <input type="hidden" name="mes" value="<?= e($mesStr) ?>">
      <input type="hidden" name="equipo" value="todos">
      <select name="deporte" class="form-select form-select-sm cal-select" onchange="this.form.submit()" aria-label="Deporte">
        <option value="">Todos los deportes</option>
        <?php foreach ($deportes as $dep): ?>
          <option value="<?= (int) $dep['id'] ?>" <?= $deporte === (int) $dep['id'] ? 'selected' : '' ?>><?= e($dep['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
</div>

<?php if ($prox): ?>
  <?php
    $focoProx = cal_equipo_foco($prox, $ids);
    $pp = cal_perspectiva($prox, $focoProx);
    $nombreFoco = (int) $prox['id_equipo_local'] === $focoProx ? $prox['equipo_local'] : $prox['equipo_visita'];
  ?>
  <div class="cal-next">
    <div class="cal-next-label"><i class="bi bi-stopwatch"></i> Próximo partido de <strong><?= e($nombreFoco) ?></strong></div>
    <div class="cal-next-body">
      <div class="cal-next-teams">
        <?= escudo_equipo($prox['escudo_local'], $prox['equipo_local'], 'lg') ?>
        <span class="cal-next-name"><?= e($prox['equipo_local']) ?></span>
        <span class="match-vs">VS</span>
        <span class="cal-next-name"><?= e($prox['equipo_visita']) ?></span>
        <?= escudo_equipo($prox['escudo_visita'], $prox['equipo_visita'], 'lg') ?>
      </div>
      <div class="cal-next-when">
        <strong><?= e(cal_fecha_larga($prox['_local'])) ?></strong>
        <span><?= e(cal_hora($prox['_local'])) ?> · <?= e(cal_relativo($prox['_local'], $ahora)) ?> · <?= e($prox['liga'] ?? 'Amistoso') ?></span>
      </div>
      <div class="cal-next-actions">
        <a class="btn btn-outline-light btn-sm" href="<?= e($u(['mes' => $prox['_local']->format('Y-m'), 'dia' => $prox['_dia']])) ?>"><i class="bi bi-calendar-check"></i> Ver en el calendario</a>
        <?php if (cal_apostable($prox)): ?>
          <a class="btn btn-warning btn-sm" href="<?= APP_URL ?>/apostar.php?partido=<?= (int) $prox['id'] ?>"><i class="bi bi-cash-coin"></i> Apostar</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <!-- ================= Calendario ================= -->
  <div class="col-xl-9">
    <div class="cal-box">
      <div class="cal-head">
        <a class="cal-nav" href="<?= e($u(['mes' => $mesPrev, 'dia' => null])) ?>" aria-label="Mes anterior"><i class="bi bi-chevron-left"></i></a>
        <div class="cal-title">
          <h2><?= e(CAL_MESES[$mes]) ?> <span><?= $anio ?></span></h2>
          <small>Horario <?= e(cal_tz_etiqueta()) ?></small>
        </div>
        <a class="cal-nav" href="<?= e($u(['mes' => $mesNext, 'dia' => null])) ?>" aria-label="Mes siguiente"><i class="bi bi-chevron-right"></i></a>
        <a class="cal-hoy" href="<?= e($u(['mes' => $ahora->format('Y-m'), 'dia' => $hoyStr])) ?>">Hoy</a>
      </div>

      <?php if ($resumen): ?>
        <div class="cal-stats">
          <span><b><?= $resumen['jugados'] ?></b> jugados</span>
          <span class="s-g"><b><?= $resumen['g'] ?></b> G</span>
          <span class="s-e"><b><?= $resumen['e'] ?></b> E</span>
          <span class="s-p"><b><?= $resumen['p'] ?></b> P</span>
          <span><b><?= $resumen['proximos'] ?></b> por jugar</span>
        </div>
      <?php endif; ?>

      <div class="cal-grid" role="grid">
        <?php for ($i = 1; $i <= 7; $i++): ?>
          <div class="cal-dow"><?= e(CAL_DIAS_CORTO[$i]) ?></div>
        <?php endfor; ?>

        <?php foreach ($semanas as $semana): foreach ($semana as $dia): ?>
          <?php
            $n = count($dia['partidos']);
            $clases = 'cal-day' . ($dia['en_mes'] ? '' : ' out') . ($dia['hoy'] ? ' today' : '') . ($dia['fecha'] === $sel ? ' selected' : '') . ($n ? ' has' : '');
          ?>
          <a class="<?= $clases ?>" href="<?= e($u(['dia' => $dia['fecha'], 'mes' => $dia['en_mes'] ? $mesStr : substr($dia['fecha'], 0, 7)])) ?>"
             aria-label="<?= e($dia['fecha']) ?>: <?= $n ?> partido(s)">
            <span class="cal-num"><?= $dia['numero'] ?></span>
            <?php if ($ids !== null): ?>
              <?php foreach (array_slice($dia['partidos'], 0, 3) as $p):
                $foco = cal_equipo_foco($p, $ids); $pp = cal_perspectiva($p, $foco);
                $cls = $pp['resultado'] !== '' ? 'res-' . $pp['resultado'] : ($p['estado'] === 'en_curso' ? 'res-live' : 'res-pend');
                $txt = $pp['resultado'] !== '' ? $pp['mio'] . '-' . $pp['suyo'] : ($p['estado'] === 'en_curso' ? 'VIVO' : cal_hora($p['_local']));
              ?>
                <span class="cal-pill <?= $cls ?>" title="<?= e(($pp['es_local'] ? 'vs ' : '@ ') . $pp['rival'] . ' · ' . ($p['liga'] ?? '')) ?>">
                  <?= escudo_equipo($pp['rival_esc'], $pp['rival'], 'sm') ?>
                  <span class="cal-pill-txt"><span class="cal-ha"><?= $pp['es_local'] ? 'L' : 'V' ?></span><?= e($txt) ?></span>
                </span>
              <?php endforeach; ?>
              <?php if ($n > 3): ?><span class="cal-more">+<?= $n - 3 ?> más</span><?php endif; ?>
            <?php elseif ($n): ?>
              <span class="cal-count"><?= $n ?></span>
              <?php foreach (array_slice($dia['partidos'], 0, 3) as $p):
                $fin = $p['estado'] === 'finalizado';
              ?>
                <span class="cal-line">
                  <?= escudo_equipo($p['escudo_local'], $p['equipo_local'], 'xs') ?>
                  <span class="cal-line-t"><?= $fin ? e(cal_marcador($p)) : e(cal_hora($p['_local'])) ?></span>
                  <?= escudo_equipo($p['escudo_visita'], $p['equipo_visita'], 'xs') ?>
                </span>
              <?php endforeach; ?>
              <?php if ($n > 3): ?><span class="cal-more">+<?= $n - 3 ?> más</span><?php endif; ?>
            <?php endif; ?>
          </a>
        <?php endforeach; endforeach; ?>
      </div>

      <?php if ($ids !== null): ?>
        <div class="cal-legend">
          <span><i class="dot res-G"></i> Ganó</span>
          <span><i class="dot res-E"></i> Empató</span>
          <span><i class="dot res-P"></i> Perdió</span>
          <span><i class="dot res-pend"></i> Por jugar</span>
          <span><b class="cal-ha">L</b> Local &nbsp;<b class="cal-ha">V</b> Visita</span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ================= Detalle del día ================= -->
  <div class="col-xl-3">
    <div class="cal-side">
      <div class="cal-side-head">
        <small><?= e(cal_relativo($selFecha, $ahora)) ?></small>
        <h3><?= e(cal_fecha_larga($selFecha)) ?></h3>
      </div>

      <?php if (!$delDia): ?>
        <div class="cal-empty">
          <i class="bi bi-calendar-x"></i>
          <p>No hay partidos este día<?= $ids !== null ? ' para ' . ($modo === 'uno' ? 'este equipo' : 'tus equipos') : '' ?>.</p>
          <?php if ($prox): ?>
            <a class="btn btn-outline-light btn-sm" href="<?= e($u(['mes' => $prox['_local']->format('Y-m'), 'dia' => $prox['_dia']])) ?>">Ir al próximo partido</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="cal-side-list">
        <?php foreach (array_slice($delDia, 0, 40) as $p):
          $foco = $ids !== null ? cal_equipo_foco($p, $ids) : null;
          $res = $foco !== null ? cal_perspectiva($p, $foco)['resultado'] : '';
        ?>
          <div class="cal-card <?= $res !== '' ? 'res-' . $res : '' ?>">
            <div class="match-top">
              <span class="match-liga"><span class="badge bg-dark badge-deporte"><?= e($p['deporte']) ?></span> <?= e($p['liga'] ?? 'Amistoso') ?></span>
              <?= badge_estado($p['estado']) ?>
            </div>
            <div class="match-teams">
              <div class="team">
                <?= escudo_equipo($p['escudo_local'], $p['equipo_local'], 'lg') ?>
                <span class="team-name"><?= e($p['equipo_local']) ?></span>
              </div>
              <div class="match-center">
                <?php if ($p['estado'] === 'finalizado'): ?>
                  <span class="match-score"><?= e(cal_marcador($p)) ?></span>
                <?php else: ?>
                  <span class="match-vs">VS</span>
                <?php endif; ?>
                <span class="match-time"><?= e(cal_hora($p['_local'])) ?></span>
              </div>
              <div class="team">
                <?= escudo_equipo($p['escudo_visita'], $p['equipo_visita'], 'lg') ?>
                <span class="team-name"><?= e($p['equipo_visita']) ?></span>
              </div>
            </div>
            <?php if ($res !== ''): ?>
              <div class="cal-res-chip res-<?= $res ?>"><?= e($etiquetaRes[$res]) ?></div>
            <?php endif; ?>
            <?php if ($p['estado'] === 'programado'): ?>
              <div class="odds-row <?= $p['cuota_empate'] !== null ? 'odds-3' : 'odds-2' ?>">
                <div class="odd"><small>Local</small><strong><?= e($p['cuota_local']) ?></strong></div>
                <?php if ($p['cuota_empate'] !== null): ?>
                  <div class="odd"><small>Empate</small><strong><?= e($p['cuota_empate']) ?></strong></div>
                <?php endif; ?>
                <div class="odd"><small>Visita</small><strong><?= e($p['cuota_visita']) ?></strong></div>
              </div>
              <?php if (cal_apostable($p)): ?>
                <a href="<?= APP_URL ?>/apostar.php?partido=<?= (int) $p['id'] ?>" class="btn btn-warning btn-sm w-100 fw-semibold"><i class="bi bi-cash-coin"></i> Apostar</a>
              <?php else: ?>
                <button class="btn btn-secondary btn-sm w-100" disabled><i class="bi bi-lock-fill"></i> Cerrado para apuestas (en curso)</button>
              <?php endif; ?>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/partido.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2"><i class="bi bi-info-circle"></i> Detalle del partido</a>
          </div>
        <?php endforeach; ?>
        <?php if (count($delDia) > 40): ?>
          <p class="small text-secondary text-center mb-0">Mostrando 40 de <?= count($delDia) ?> partidos. Elegí un deporte arriba para acotar.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ================= Modal: elegir equipo favorito ================= -->
<div class="modal fade" id="modalFavorito" tabindex="-1" aria-labelledby="modalFavoritoTitulo" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <div>
          <h2 class="modal-title h5" id="modalFavoritoTitulo">
            <?= $preguntar ? '¿Tenés un equipo favorito?' : 'Agregar equipo favorito' ?>
          </h2>
          <p class="small text-secondary mb-0">
            Tu calendario va a mostrar sus partidos, resultados y el próximo juego.
            Podés elegir más de uno.
          </p>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="search" id="favBuscar" class="form-control mb-2" placeholder="Buscá tu equipo (ej. Boca, Real Madrid, Lakers)" autocomplete="off">
        <div class="cal-sport-chips" id="favDeportes"></div>
        <div id="favLista" class="cal-fav-list" role="list"></div>
      </div>
      <?php if ($preguntar): ?>
        <div class="modal-footer border-0 justify-content-between">
          <form method="post" action="<?= APP_URL ?>/favoritos.php">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="omitir">
            <button type="submit" class="btn btn-link btn-sm text-secondary">No volver a preguntar</button>
          </form>
          <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">Ahora no</button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<form id="formAgregar" method="post" action="<?= APP_URL ?>/favoritos.php" hidden>
  <?= csrf_field() ?>
  <input type="hidden" name="accion" value="agregar">
  <input type="hidden" name="id_equipo" id="formAgregarId" value="">
</form>

<script type="application/json" id="datosEquipos"><?= json_encode(['equipos' => $elegibles, 'favoritos' => $favIds], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
  var datos = JSON.parse(document.getElementById('datosEquipos').textContent);
  var equipos = datos.equipos, favs = datos.favoritos;
  var lista = document.getElementById('favLista');
  var buscar = document.getElementById('favBuscar');
  var chipsDep = document.getElementById('favDeportes');
  var deporteSel = '';

  function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }

  function crest(url, alt) {
    var s = document.createElement('span');
    s.className = 'crest crest-md';
    var i = document.createElement('i');
    i.className = 'bi bi-shield-fill';
    s.appendChild(i);
    if (url && /^(https?:)?\/\//i.test(url)) {
      var img = document.createElement('img');
      img.src = url; img.alt = alt; img.loading = 'lazy'; img.referrerPolicy = 'no-referrer';
      img.onerror = function () { img.style.display = 'none'; };
      s.appendChild(img);
    }
    return s;
  }

  // Chips de deporte
  var deportes = [];
  equipos.forEach(function (t) { if (deportes.indexOf(t.d) === -1) deportes.push(t.d); });
  [''].concat(deportes).forEach(function (d) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'cal-sport-chip' + (d === '' ? ' active' : '');
    b.textContent = d === '' ? 'Todos' : d;
    b.addEventListener('click', function () {
      deporteSel = d;
      Array.prototype.forEach.call(chipsDep.children, function (c) { c.classList.remove('active'); });
      b.classList.add('active');
      pintar();
    });
    chipsDep.appendChild(b);
  });

  function pintar() {
    var q = norm(buscar.value.trim());
    var res = equipos.filter(function (t) {
      if (favs.indexOf(t.id) !== -1) return false;
      if (deporteSel && t.d !== deporteSel) return false;
      return !q || norm(t.n).indexOf(q) !== -1 || norm(t.l).indexOf(q) !== -1;
    });
    var total = res.length;
    res = res.slice(0, 60);
    lista.textContent = '';
    if (!res.length) {
      var vacio = document.createElement('p');
      vacio.className = 'text-secondary small text-center my-3';
      vacio.textContent = 'No encontré ese equipo. Solo aparecen equipos con partidos cercanos.';
      lista.appendChild(vacio);
      return;
    }
    res.forEach(function (t) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'cal-fav-item';
      b.setAttribute('role', 'listitem');
      b.appendChild(crest(t.e, t.n));
      var txt = document.createElement('span');
      txt.className = 'cal-fav-txt';
      var n = document.createElement('strong'); n.textContent = t.n;
      var l = document.createElement('small'); l.textContent = (t.d || '') + (t.l ? ' · ' + t.l : '');
      txt.appendChild(n); txt.appendChild(l);
      b.appendChild(txt);
      var plus = document.createElement('i'); plus.className = 'bi bi-plus-circle';
      b.appendChild(plus);
      b.addEventListener('click', function () {
        document.getElementById('formAgregarId').value = t.id;
        document.getElementById('formAgregar').submit();
      });
      lista.appendChild(b);
    });
    if (total > res.length) {
      var mas = document.createElement('p');
      mas.className = 'text-secondary small text-center my-2';
      mas.textContent = 'Hay ' + (total - res.length) + ' equipos más: escribí para filtrar.';
      lista.appendChild(mas);
    }
  }
  buscar.addEventListener('input', pintar);
  pintar();

  // Primera vez: preguntar por el equipo favorito (una vez por sesión del navegador)
  <?php if ($preguntar): ?>
  window.addEventListener('load', function () {
    var el = document.getElementById('modalFavorito');
    try { if (sessionStorage.getItem('gb_fav_cerrado')) return; } catch (e) {}
    el.addEventListener('hidden.bs.modal', function () { try { sessionStorage.setItem('gb_fav_cerrado', '1'); } catch (e) {} });
    new bootstrap.Modal(el).show();
  });
  <?php endif; ?>
  document.getElementById('modalFavorito').addEventListener('shown.bs.modal', function () { buscar.focus(); });
})();
</script>