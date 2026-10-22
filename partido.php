<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/DetallePartido.php';
require_once __DIR__ . '/includes/camisetas.php';
requerir_login();

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare(
    "SELECT p.*, d.nombre AS deporte, lt.nombre AS liga,
            el.nombre AS equipo_local, ev.nombre AS equipo_visita,
            el.escudo AS escudo_local, ev.escudo AS escudo_visita,
            es.nombre AS est_nombre, es.ciudad AS est_ciudad, es.capacidad AS est_capacidad, es.imagen AS est_imagen
     FROM partidos p
     JOIN deportes d ON d.id = p.id_deporte
     LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
     JOIN equipos el ON el.id = p.id_equipo_local
     JOIN equipos ev ON ev.id = p.id_equipo_visita
     LEFT JOIN estadios es ON es.id = p.id_estadio
     WHERE p.id = ?"
);
$st->execute([$id]);
$p = $st->fetch();
if (!$p) {
    flash('warning', 'No encontramos ese partido.');
    redirect('/partidos.php');
}

$listo = DetallePartido::tablasListas($pdo);
$ref = $listo ? DetallePartido::referencia($pdo, $id) : null; // null = sin vínculo con ESPN o no es fútbol

// ---- Actualización manual (solo admin, por POST con CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && es_admin() && $listo) {
    verificar_csrf();
    set_time_limit(60);
    $r = DetallePartido::importar($pdo, $id, 2);
    flash($r['ok'] ? 'success' : 'warning', $r['ok'] ? 'Detalle actualizado desde ESPN. ' . $r['mensaje'] : $r['mensaje']);
    redirect('/partido.php?id=' . $id);
}

// ---- Datos guardados (+ importación automática cuando conviene)
$det = null;
$alin = [];
$eventos = [];
if ($listo) {
    $q = $pdo->prepare('SELECT * FROM partido_detalle WHERE id_partido = ?');
    $q->execute([$id]);
    $det = $q->fetch() ?: null;

    if ($ref && DetallePartido::debeImportar((string) $p['estado'], (string) $p['fecha_partido'], $det)) {
        set_time_limit(45);
        DetallePartido::importar($pdo, $id, 1);
        $q->execute([$id]);
        $det = $q->fetch() ?: null;
    }
    if ($det && (int) $det['tiene_datos'] === 1) {
        $q = $pdo->prepare('SELECT * FROM partido_alineacion WHERE id_partido = ? ORDER BY titular DESC, lugar, dorsal+0');
        $q->execute([$id]);
        $alin = $q->fetchAll();
        $q = $pdo->prepare('SELECT * FROM partido_eventos WHERE id_partido = ? ORDER BY orden');
        $q->execute([$id]);
        $eventos = $q->fetchAll();
    }
}
$hayDatos = $det && (int) $det['tiene_datos'] === 1;

$idLocal = (int) $p['id_equipo_local'];
$idVisita = (int) $p['id_equipo_visita'];
$muestraMarcador = in_array($p['estado'], ['finalizado', 'en_curso'], true) && $p['marcador_local'] !== null;

// ---- Marcas por jugador (goles, tarjetas, cambios) para la cancha y la banca
$marca = [];
$m = static function (string $aid) use (&$marca): void {
    $marca[$aid] ??= ['goles' => 0, 'autogoles' => 0, 'asist' => 0, 'amarilla' => 0, 'roja' => false, 'sale' => null, 'entra' => null];
};
$goleadores = [$idLocal => [], $idVisita => []];
foreach ($eventos as $ev) {
    $aid = (string) $ev['jugador_id'];
    $aid2 = (string) $ev['jugador2_id'];
    switch ($ev['tipo']) {
        case 'gol': case 'gol_penal':
            $m($aid); $marca[$aid]['goles']++;
            if ($aid2 !== '') { $m($aid2); $marca[$aid2]['asist']++; }
            $goleadores[(int) $ev['id_equipo']][] = $ev['jugador'] . ' ' . $ev['minuto'] . ($ev['tipo'] === 'gol_penal' ? ' (p)' : '');
            break;
        case 'autogol':
            $m($aid); $marca[$aid]['autogoles']++;
            $lado = (int) $ev['id_equipo'] === $idLocal ? $idVisita : $idLocal; // el gol cuenta para el rival
            $goleadores[$lado][] = $ev['jugador'] . ' ' . $ev['minuto'] . ' (a.g.)';
            break;
        case 'amarilla': $m($aid); $marca[$aid]['amarilla']++; break;
        case 'roja': case 'segunda_amarilla': $m($aid); $marca[$aid]['roja'] = true; break;
        case 'cambio':
            $m($aid); $marca[$aid]['entra'] = $ev['minuto'];
            if ($aid2 !== '') { $m($aid2); $marca[$aid2]['sale'] = $ev['minuto']; }
            break;
    }
}

$titulares = [$idLocal => [], $idVisita => []];
$banca = [$idLocal => [], $idVisita => []];
foreach ($alin as $j) {
    if ($j['titular']) { $titulares[(int) $j['id_equipo']][] = $j; } else { $banca[(int) $j['id_equipo']][] = $j; }
}

// ---- Ficha de cada jugador (se abre al tocarlo): foto, minutos, estadísticas y lo que hizo en el partido
const FOTO_ESPN = 'https://a.espncdn.com/i/headshots/soccer/players/full/';
$minutoNum = static fn(?string $t): int => preg_match("/(\d+)'(?:\+(\d+))?/", (string) $t, $mm) ? (int) $mm[1] + (int) ($mm[2] ?? 0) : 0;
$finPartido = 90;
foreach ($eventos as $ev) { $finPartido = max($finPartido, $minutoNum($ev['minuto'])); }
$nombreEquipo = [$idLocal => $p['equipo_local'], $idVisita => $p['equipo_visita']];
$fotoUrl = static function (string $aid): string {
    if (!ctype_digit($aid)) { return ''; }
    return is_file(__DIR__ . '/assets/jugadores/' . $aid . '.png') // foto propia (opcional) tiene prioridad
        ? APP_URL . '/assets/jugadores/' . $aid . '.png'
        : FOTO_ESPN . $aid . '.png';
};
$fichas = [];
foreach ($alin as $j) {
    $aid = (string) $j['espn_athlete_id'];
    $mk = $marca[$aid] ?? null;
    $min = null;
    if ($j['titular']) {
        $min = ($mk && $mk['sale'] ? $minutoNum($mk['sale']) : $finPartido);
    } elseif ($mk && $mk['entra']) {
        $min = ($mk['sale'] ? $minutoNum($mk['sale']) : $finPartido) - $minutoNum($mk['entra']);
    }
    $acciones = [];
    foreach ($eventos as $ev) {
        $esP1 = (string) $ev['jugador_id'] === $aid;
        $esP2 = (string) $ev['jugador2_id'] === $aid;
        if (!$esP1 && !$esP2) { continue; }
        $marc = $ev['marcador_local'] !== null ? ' (' . (int) $ev['marcador_local'] . '-' . (int) $ev['marcador_visita'] . ')' : '';
        switch ($ev['tipo']) {
            case 'gol': if ($esP1) { $acciones[] = [$ev['minuto'], '⚽', 'Gol' . $marc]; } else { $acciones[] = [$ev['minuto'], '🅰️', 'Asistencia a ' . $ev['jugador']]; } break;
            case 'gol_penal': if ($esP1) { $acciones[] = [$ev['minuto'], '⚽', 'Gol de penal' . $marc]; } break;
            case 'autogol': if ($esP1) { $acciones[] = [$ev['minuto'], '⚽', 'Autogol' . $marc]; } break;
            case 'amarilla': $acciones[] = [$ev['minuto'], '🟨', 'Tarjeta amarilla']; break;
            case 'roja': $acciones[] = [$ev['minuto'], '🟥', 'Tarjeta roja']; break;
            case 'segunda_amarilla': $acciones[] = [$ev['minuto'], '🟥', 'Segunda amarilla (expulsado)']; break;
            case 'penal_fallado': $acciones[] = [$ev['minuto'], '❌', 'Penal fallado']; break;
            case 'cambio':
                $acciones[] = $esP1 ? [$ev['minuto'], '🔼', 'Entró por ' . $ev['jugador2']] : [$ev['minuto'], '🔽', 'Salió (entró ' . $ev['jugador'] . ')'];
                break;
        }
    }
    $fichas[$aid] = [
        'n' => $j['nombre'], 'd' => (string) $j['dorsal'], 'p' => (string) $j['posicion'],
        'e' => $nombreEquipo[(int) $j['id_equipo']] ?? '', 'l' => (int) $j['id_equipo'] === $idLocal,
        'f' => $fotoUrl($aid), 't' => (bool) $j['titular'], 'm' => $min !== null ? max(0, $min) : null,
        's' => json_decode((string) $j['stats'], true) ?: new stdClass(), 'a' => $acciones,
    ];
}

$kits = kits_partido((string) $p['equipo_local'], (string) $p['equipo_visita']); // colores de camiseta por lado

// ---- Estadio: el de la base si está vinculado; si no, el que informa ESPN
$estNombre = $p['est_nombre'] ?: ($det['estadio_nombre'] ?? null);
$estCiudad = $p['est_ciudad'] ?: ($det['estadio_ciudad'] ?? null);
$estCap = (int) ($p['est_capacidad'] ?? 0);
$asistencia = (int) ($det['asistencia'] ?? 0);

$titulo_pagina = $p['equipo_local'] . ' vs ' . $p['equipo_visita'];
require __DIR__ . '/includes/header.php';

/** Nombre corto para la cancha: el último apellido/palabra. */
$corto = static function (string $n): string {
    $partes = preg_split('/\s+/', trim($n));
    return count($partes) > 1 ? end($partes) : $n;
};
$iconos = static function (array $mk): string {
    $h = '';
    if ($mk['goles'] > 0) { $h .= '<span class="pl-ico" title="Gol">⚽' . ($mk['goles'] > 1 ? '×' . $mk['goles'] : '') . '</span>'; }
    if ($mk['autogoles'] > 0) { $h .= '<span class="pl-ico" title="Autogol">⚽<small>a.g.</small></span>'; }
    if ($mk['amarilla'] > 0) { $h .= '<span class="pl-ico" title="Amarilla">🟨</span>'; }
    if ($mk['roja']) { $h .= '<span class="pl-ico" title="Roja">🟥</span>'; }
    return $h;
};
$statsL = $hayDatos ? (json_decode((string) $det['stats_local'], true) ?: []) : [];
$statsV = $hayDatos ? (json_decode((string) $det['stats_visita'], true) ?: []) : [];
?>
<link href="<?= APP_URL ?>/public/css/partido.css?v=<?= (int) @filemtime(__DIR__ . '/public/css/partido.css') ?>" rel="stylesheet">

<div class="mb-3"><a href="<?= APP_URL ?>/partidos.php" class="link-secondary text-decoration-none"><i class="bi bi-arrow-left"></i> Volver a partidos</a></div>

<?php foreach (get_flashes() as $f): ?>
  <div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['mensaje']) ?></div>
<?php endforeach; ?>

<section class="pd-head">
  <div class="pd-meta">
    <span class="badge bg-dark badge-deporte"><?= e($p['deporte']) ?></span>
    <?= e($p['liga'] ?? 'Amistoso') ?><?= $p['jornada'] ? ' · Jornada ' . (int) $p['jornada'] : '' ?>
    · <?= e(fecha_partido_legible($p['fecha_partido'], 'd/m/Y h:i A')) ?> <small>(<?= e(app_tz_etiqueta()) ?>)</small>
    <?= $p['estado'] === 'en_curso' ? badge_en_vivo($p['minuto_actual'] ?? null) : badge_estado($p['estado']) ?>
  </div>
  <div class="pd-teams">
    <div class="pd-team">
      <?= escudo_equipo($p['escudo_local'], $p['equipo_local'], 'lg') ?>
      <h2><?= e($p['equipo_local']) ?></h2>
      <ul class="pd-scorers"><?php foreach ($goleadores[$idLocal] as $g): ?><li>⚽ <?= e($g) ?></li><?php endforeach; ?></ul>
    </div>
    <div class="pd-score">
      <?php if ($muestraMarcador): ?>
        <?= (int) $p['marcador_local'] ?> <span>-</span> <?= (int) $p['marcador_visita'] ?>
      <?php else: ?>
        <span class="pd-vs">VS</span>
      <?php endif; ?>
    </div>
    <div class="pd-team">
      <?= escudo_equipo($p['escudo_visita'], $p['equipo_visita'], 'lg') ?>
      <h2><?= e($p['equipo_visita']) ?></h2>
      <ul class="pd-scorers"><?php foreach ($goleadores[$idVisita] as $g): ?><li>⚽ <?= e($g) ?></li><?php endforeach; ?></ul>
    </div>
  </div>

  <?php if ($estNombre): ?>
    <div class="pd-venue">
      <?php if (!empty($p['est_imagen'])): ?>
        <img src="<?= e($p['est_imagen']) ?>" alt="<?= e($estNombre) ?>" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display='none'">
      <?php endif; ?>
      <div>
        <div class="pd-venue-label"><i class="bi bi-geo-alt-fill"></i> Se juega en</div>
        <strong><?= e($estNombre) ?></strong>
        <div class="text-muted small">
          <?= e((string) $estCiudad) ?>
          <?php if ($estCap > 0): ?> · Capacidad <?= number_format($estCap) ?><?php endif; ?>
          <?php if ($asistencia > 0): ?> · Asistencia <?= number_format($asistencia) ?><?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (es_admin() && $listo && $ref): ?>
    <form method="post" class="pd-admin">
      <?= csrf_field() ?>
      <button class="btn btn-outline-light btn-sm" type="submit"><i class="bi bi-arrow-repeat"></i> Actualizar desde ESPN</button>
      <?php if ($det && $det['importado_at']): ?>
        <small class="text-muted">Última importación: <?= e(fecha_partido_legible($det['importado_at'], 'd/m/Y h:i A')) ?></small>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/alineacion_lista.php'; al_render($pdo, $p, $titulares, $idLocal, $idVisita, false, $estNombre); ?>

<?php if (!$listo): ?>
  <div class="alert alert-secondary mt-3">
    <?php if (es_admin()): ?>Falta crear las tablas del detalle de partido: ejecutá <code>database/detalle_partido.sql</code> en phpMyAdmin.<?php else: ?>El detalle de este partido todavía no está disponible.<?php endif; ?>
  </div>
<?php elseif (!$ref): ?>
  <div class="alert alert-secondary mt-3">El detalle (alineaciones y cronología) está disponible por ahora solo para partidos de fútbol sincronizados con ESPN.</div>
<?php elseif (!$hayDatos): ?>
  <div class="alert alert-secondary mt-3">
    <?php if ($p['estado'] === 'programado'): ?>
      Las alineaciones suelen publicarse poco antes del inicio. Volvé a mirar más tarde.
    <?php else: ?>
      <?= e($det['error'] ?? 'Todavía no hay detalle para este partido.') ?>
    <?php endif; ?>
  </div>
<?php else: ?>

<ul class="nav nav-tabs pd-tabs mt-4" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-cronologia" type="button"><i class="bi bi-clock-history"></i> Cronología</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-alineaciones" type="button"><i class="bi bi-people"></i> Alineaciones</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-stats" type="button"><i class="bi bi-bar-chart"></i> Estadísticas</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-jugadores" type="button"><i class="bi bi-person-lines-fill"></i> Jugadores</button></li>
</ul>

<div class="tab-content pd-panel">

  <!-- ============ CRONOLOGÍA ============ -->
  <div class="tab-pane fade show active" id="tab-cronologia">
    <?php if (!$eventos): ?>
      <p class="text-muted mb-0">Todavía no hay eventos.</p>
    <?php else: ?>
      <div class="tl">
        <?php foreach ($eventos as $ev):
          $local = (int) $ev['id_equipo'] === $idLocal;
          if ($ev['tipo'] === 'medio_tiempo'): ?>
            <div class="tl-break">Descanso</div>
          <?php continue; endif;
          ob_start(); ?>
            <?php switch ($ev['tipo']):
              case 'gol': case 'gol_penal': case 'autogol': ?>
                <div class="tl-card tl-gol">
                  <span class="tl-ico">⚽</span>
                  <div>
                    <strong><?= e((string) $ev['jugador']) ?></strong>
                    <?= $ev['tipo'] === 'gol_penal' ? '<small>(penal)</small>' : '' ?>
                    <?= $ev['tipo'] === 'autogol' ? '<small>(autogol)</small>' : '' ?>
                    <?php if ($ev['marcador_local'] !== null): ?><span class="tl-score"><?= (int) $ev['marcador_local'] ?>-<?= (int) $ev['marcador_visita'] ?></span><?php endif; ?>
                    <?php if ($ev['jugador2']): ?><div class="tl-sub">Asistencia: <?= e($ev['jugador2']) ?></div><?php endif; ?>
                  </div>
                </div>
              <?php break;
              case 'amarilla': case 'roja': case 'segunda_amarilla': ?>
                <div class="tl-card">
                  <span class="tl-ico"><?= $ev['tipo'] === 'amarilla' ? '🟨' : '🟥' ?></span>
                  <div><strong><?= e((string) $ev['jugador']) ?></strong>
                    <div class="tl-sub"><?= $ev['tipo'] === 'amarilla' ? 'Tarjeta amarilla' : ($ev['tipo'] === 'roja' ? 'Tarjeta roja' : 'Segunda amarilla (expulsado)') ?></div></div>
                </div>
              <?php break;
              case 'cambio': ?>
                <div class="tl-card">
                  <span class="tl-ico text-success"><i class="bi bi-arrow-up-circle-fill"></i></span>
                  <div><strong><?= e((string) $ev['jugador']) ?></strong>
                    <div class="tl-sub"><i class="bi bi-arrow-down-circle-fill text-danger"></i> <?= e((string) $ev['jugador2']) ?></div></div>
                </div>
              <?php break;
              case 'penal_fallado': ?>
                <div class="tl-card"><span class="tl-ico">❌</span><div><strong><?= e((string) $ev['jugador']) ?></strong><div class="tl-sub">Penal fallado</div></div></div>
              <?php break;
              default: ?>
                <div class="tl-card"><span class="tl-ico"><i class="bi bi-tv"></i></span><div><strong>Revisión VAR</strong></div></div>
            <?php endswitch;
          $html = ob_get_clean(); ?>
          <div class="tl-row">
            <div class="tl-side tl-left"><?= $local ? $html : '' ?></div>
            <div class="tl-min"><?= e((string) $ev['minuto']) ?></div>
            <div class="tl-side tl-right"><?= !$local ? $html : '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============ ALINEACIONES ============ -->
  <div class="tab-pane fade" id="tab-alineaciones">
    <div class="row g-4">
      <?php foreach ([[$idLocal, $p['equipo_local'], $det['formacion_local'], 'home'], [$idVisita, $p['equipo_visita'], $det['formacion_visita'], 'away']] as [$idEq, $nomEq, $form, $clase]): ?>
        <div class="col-lg-6">
          <div class="d-flex justify-content-between align-items-baseline mb-2">
            <h3 class="h6 mb-0"><?= e($nomEq) ?></h3>
            <span class="badge bg-secondary"><?= e((string) ($form ?: 'Formación N/D')) ?></span>
          </div>
          <?php if (!$titulares[$idEq]): ?>
            <p class="text-muted">Sin alineación publicada.</p>
          <?php else: ?>
            <?php $kit = $kits[$clase]; ?>
            <div class="pitch" style="--k1:<?= e($kit['c1']) ?>;--k2:<?= e($kit['c2']) ?>;--kt:<?= e($kit['tx']) ?>">
              <div class="pitch-lines"></div>
              <?php foreach (DetallePartido::ubicarEnCancha($titulares[$idEq], $form ? (string) $form : null) as $j):
                $mk = $marca[$j['espn_athlete_id']] ?? ['goles' => 0, 'autogoles' => 0, 'asist' => 0, 'amarilla' => 0, 'roja' => false, 'sale' => null, 'entra' => null];
                $foto = $fotoUrl((string) $j['espn_athlete_id']);
                $kp = in_array(strtoupper((string) ($j['posicion'] ?? '')), ['G', 'GK'], true) ? kit_portero($kit, $clase === 'home') : null; ?>
                <div class="pl <?= $clase ?>" data-pid="<?= e((string) $j['espn_athlete_id']) ?>" role="button" tabindex="0" style="left:<?= (float) $j['x'] ?>%;top:<?= (float) $j['y'] ?>%<?= $kp ? ';--k1:' . e($kp['c1']) . ';--k2:' . e($kp['c2']) . ';--kt:' . e($kp['tx']) : '' ?>" title="<?= e($j['nombre'] . ' · ' . ($j['posicion'] ?? '')) ?> — tocá para ver sus datos">
                  <div class="pl-dot">
                    <span class="pl-shirt"><?= kit_svg() ?></span>
                    <span class="pl-n"><?= e((string) $j['dorsal']) ?></span>
                    <span class="pl-head"><?php if ($foto !== ''): ?><img class="pl-photo" src="<?= e($foto) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span>
                    <?= $iconos($mk) ? '<span class="pl-badges">' . $iconos($mk) . '</span>' : '' ?>
                  </div>
                  <div class="pl-name"><?= e($corto($j['nombre'])) ?></div>
                  <?php if ($mk['sale']): ?><div class="pl-out">↓ <?= e($mk['sale']) ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($banca[$idEq]): ?>
            <h4 class="h6 mt-3 text-muted">Banca</h4>
            <ul class="bench">
              <?php foreach ($banca[$idEq] as $j): $mk = $marca[$j['espn_athlete_id']] ?? null; ?>
                <li class="pd-click <?= $mk && $mk['entra'] ? 'in' : '' ?>" data-pid="<?= e((string) $j['espn_athlete_id']) ?>" role="button" tabindex="0">
                  <span class="bench-n"><?= e((string) $j['dorsal']) ?></span>
                  <span class="bench-name"><?= e($j['nombre']) ?></span>
                  <span class="bench-pos"><?= e((string) $j['posicion']) ?></span>
                  <?php if ($mk && $mk['entra']): ?><span class="bench-in"><i class="bi bi-arrow-up-circle-fill text-success"></i> <?= e($mk['entra']) ?></span><?= $mk ? $iconos($mk) : '' ?><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ============ ESTADÍSTICAS ============ -->
  <div class="tab-pane fade" id="tab-stats">
    <?php
    $filas = [
        ['Posesión', 'possessionPct', '%'], ['Tiros', 'totalShots', ''], ['Tiros al arco', 'shotsOnTarget', ''],
        ['Tiros bloqueados', 'blockedShots', ''], ['Tiros de esquina', 'wonCorners', ''], ['Faltas', 'foulsCommitted', ''],
        ['Fuera de juego', 'offsides', ''], ['Tarjetas amarillas', 'yellowCards', ''], ['Tarjetas rojas', 'redCards', ''],
        ['Atajadas', 'saves', ''], ['Pases', 'totalPasses', ''], ['Pases completos', 'accuratePasses', ''],
        ['Entradas', 'totalTackles', ''], ['Intercepciones', 'interceptions', ''], ['Despejes', 'totalClearance', ''],
    ];
    $hay = false;
    foreach ($filas as [$etq, $k, $suf]):
        if (!isset($statsL[$k]) && !isset($statsV[$k])) { continue; }
        $hay = true;
        $a = (float) ($statsL[$k] ?? 0);
        $b = (float) ($statsV[$k] ?? 0);
        $tot = $a + $b;
        $pa = $tot > 0 ? round($a * 100 / $tot) : 50;
        $fmt = static fn(float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . $suf; ?>
      <div class="st-row">
        <div class="st-vals"><strong><?= e($fmt($a)) ?></strong><span><?= e($etq) ?></span><strong><?= e($fmt($b)) ?></strong></div>
        <div class="st-bar"><div class="st-a" style="width:<?= $pa ?>%"></div><div class="st-b" style="width:<?= 100 - $pa ?>%"></div></div>
      </div>
    <?php endforeach;
    if (!$hay): ?><p class="text-muted mb-0">ESPN no publicó estadísticas de equipo para este partido.</p><?php endif; ?>
    <div class="st-legend"><span class="home"></span> <?= e($p['equipo_local']) ?> <span class="away ms-3"></span> <?= e($p['equipo_visita']) ?></div>
  </div>

  <!-- ============ JUGADORES ============ -->
  <div class="tab-pane fade" id="tab-jugadores">
    <?php foreach ([[$idLocal, $p['equipo_local']], [$idVisita, $p['equipo_visita']]] as [$idEq, $nomEq]):
      $jugaron = array_filter($alin, static fn($j) => (int) $j['id_equipo'] === $idEq && ($j['titular'] || $j['entro'])); ?>
      <h3 class="h6 mt-2"><?= e($nomEq) ?></h3>
      <div class="table-responsive mb-3">
        <table class="table table-sm table-dark align-middle pd-table">
          <thead><tr><th>#</th><th>Jugador</th><th>Pos</th><th title="Goles">G</th><th title="Asistencias">A</th><th title="Tiros">T</th><th title="Tiros al arco">TA</th><th title="Faltas cometidas">FC</th><th title="Atajadas">At</th><th>Tarj.</th></tr></thead>
          <tbody>
          <?php foreach ($jugaron as $j): $s = json_decode((string) $j['stats'], true) ?: []; ?>
            <tr class="pd-click" data-pid="<?= e((string) $j['espn_athlete_id']) ?>" role="button" tabindex="0">
              <td><?= e((string) $j['dorsal']) ?></td>
              <td><?= e($j['nombre']) ?><?= !$j['titular'] ? ' <small class="text-muted">(suplente)</small>' : '' ?></td>
              <td><?= e((string) $j['posicion']) ?></td>
              <td><?= (int) ($s['goles'] ?? 0) ?></td><td><?= (int) ($s['asistencias'] ?? 0) ?></td>
              <td><?= (int) ($s['tiros'] ?? 0) ?></td><td><?= (int) ($s['tiros_arco'] ?? 0) ?></td>
              <td><?= (int) ($s['faltas'] ?? 0) ?></td><td><?= (int) ($s['atajadas'] ?? 0) ?></td>
              <td><?= str_repeat('🟨', (int) ($s['amarillas'] ?? 0)) . str_repeat('🟥', (int) ($s['rojas'] ?? 0)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
    <p class="small text-muted mb-0">Fuente: ESPN. ESPN no publica calificaciones por jugador, solo estas estadísticas.</p>
  </div>
</div>

<script type="application/json" id="pd-data"><?= json_encode($fichas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<div class="modal fade pd-modal" id="pdModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header pd-m-head">
        <div class="pd-m-photo"><span id="pdM-n"></span><img id="pdM-img" alt=""></div>
        <div class="pd-m-title">
          <h5 id="pdM-nombre"></h5>
          <div class="text-muted small" id="pdM-sub"></div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="pd-m-tiles" id="pdM-tiles"></div>
        <h6 class="mt-3 mb-2">Lo que hizo en el partido</h6>
        <ul class="pd-m-acc" id="pdM-acc"></ul>
        <p class="small text-muted mt-3 mb-0">Fuente: ESPN. ESPN no publica pases, entradas, duelos ni calificación por jugador; los minutos jugados son aproximados.</p>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var fuente = document.getElementById('pd-data');
  if (!fuente || !window.bootstrap) { return; }
  var datos = JSON.parse(fuente.textContent);
  var modal = new bootstrap.Modal(document.getElementById('pdModal'));
  var $ = function (id) { return document.getElementById(id); };

  function tile(valor, etiqueta) {
    var d = document.createElement('div'); d.className = 'pd-m-tile';
    var v = document.createElement('strong'); v.textContent = valor;
    var e = document.createElement('span'); e.textContent = etiqueta;
    d.appendChild(v); d.appendChild(e); return d;
  }

  function abrir(id) {
    var j = datos[id]; if (!j) { return; }
    var s = j.s || {};
    $('pdM-nombre').textContent = j.n;
    $('pdM-sub').textContent = [j.e, j.p, j.t ? 'Titular' : 'Suplente'].filter(Boolean).join(' · ');
    $('pdM-n').textContent = j.d || '';
    var img = $('pdM-img');
    img.style.display = 'none';
    img.onload = function () { img.style.display = 'block'; };
    img.onerror = function () { img.style.display = 'none'; };
    img.src = j.f || '';
    $('pdM-img').closest('.pd-m-photo').className = 'pd-m-photo ' + (j.l ? 'home' : 'away');

    var cont = $('pdM-tiles'); cont.textContent = '';
    cont.appendChild(tile(j.m === null ? '—' : '≈ ' + j.m + "'", 'Minutos jugados'));
    cont.appendChild(tile(s.goles || 0, 'Goles'));
    cont.appendChild(tile(s.asistencias || 0, 'Asistencias'));
    cont.appendChild(tile(s.tiros || 0, 'Tiros'));
    cont.appendChild(tile(s.tiros_arco || 0, 'Tiros al arco'));
    cont.appendChild(tile(s.faltas || 0, 'Faltas cometidas'));
    cont.appendChild(tile(s.faltas_recibidas || 0, 'Faltas recibidas'));
    cont.appendChild(tile(s.fuera_de_juego || 0, 'Fuera de juego'));
    if (j.p === 'G') {
      cont.appendChild(tile(s.atajadas || 0, 'Atajadas'));
      cont.appendChild(tile(s.goles_contra || 0, 'Goles recibidos'));
    }
    cont.appendChild(tile((s.amarillas || 0) + ' / ' + (s.rojas || 0), 'Amarillas / Rojas'));

    var ul = $('pdM-acc'); ul.textContent = '';
    if (!j.a.length) {
      var li = document.createElement('li'); li.className = 'text-muted';
      li.textContent = j.m === null ? 'No entró al campo.' : 'Sin goles, tarjetas ni cambios.';
      ul.appendChild(li);
    }
    j.a.forEach(function (a) {
      var li = document.createElement('li');
      var m = document.createElement('b'); m.textContent = a[0];
      li.appendChild(m); li.appendChild(document.createTextNode(' ' + a[1] + ' ' + a[2]));
      ul.appendChild(li);
    });
    modal.show();
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pid]'); if (t) { abrir(t.getAttribute('data-pid')); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') { return; }
    var t = e.target.closest && e.target.closest('[data-pid]');
    if (t) { e.preventDefault(); abrir(t.getAttribute('data-pid')); }
  });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>