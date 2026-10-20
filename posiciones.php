<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/posiciones_lib.php';
requerir_login();

/**
 * Tabla de posiciones, calculada en vivo desde `partidos` (temporada actual).
 * Cada liga se muestra con su formato real:
 *  - Ligas normales: tabla única.
 *  - Liga Profesional Argentina: Clausura por Zona A / Zona B + Tabla anual.
 *  - UEFA Champions League: tabla única de 36 con zonas de color.
 *  - NBA: conferencias Oeste / Este (por % de victorias).
 *  - NFL: conferencias AFC / NFC con sus 4 divisiones (con empates).
 */

$pdo = db();
$ligas = pos_ligas_disponibles($pdo);

$idSel = (int) ($_GET['liga'] ?? 0);
$actual = null;
foreach ($ligas as $l) {
    if ((int) $l['id'] === $idSel) { $actual = $l; break; }
}
$actual ??= $ligas[0] ?? null;

$cfg = $actual['cfg'] ?? [];
$vista = $cfg['vista'] ?? 'liga';
$idLiga = (int) ($actual['id'] ?? 0);
$tab = (string) ($_GET['tab'] ?? '');

// ---------- Helpers de dibujo ----------
function pos_tabs(array $items, string $activo, int $idLiga): string
{
    $h = '<ul class="nav nav-pills mb-3">';
    foreach ($items as $clave => $texto) {
        $h .= '<li class="nav-item"><a class="nav-link' . ($clave === $activo ? ' active' : '') . '" href="?liga='
            . $idLiga . '&amp;tab=' . e($clave) . '">' . e($texto) . '</a></li>';
    }
    return $h . '</ul>';
}

function pos_celda_equipo(array $f): string
{
    return '<td class="fw-semibold"><span class="d-inline-flex align-items-center gap-2">'
        . escudo_equipo($f['escudo'], $f['nombre'], 'sm') . ' ' . e($f['nombre']) . '</span></td>';
}

function pos_dif(int $d): string
{
    return '<td class="text-center ' . ($d > 0 ? 'text-success' : ($d < 0 ? 'text-danger' : '')) . '">'
        . ($d > 0 ? '+' : '') . $d . '</td>';
}

/** Tabla de fútbol. $zona(pos) devuelve una clase de color o null. */
function pos_tabla_futbol(array $filas, ?callable $zona = null): void
{
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-posiciones align-middle">
        <thead class="table-dark"><tr>
          <th style="width:48px">#</th><th>Equipo</th>
          <th class="text-center">PJ</th><th class="text-center">PG</th><th class="text-center">PE</th><th class="text-center">PP</th>
          <th class="text-center">GF</th><th class="text-center">GC</th><th class="text-center">DIF</th><th class="text-center">PTS</th>
        </tr></thead>
        <tbody>
        <?php foreach ($filas as $i => $f): $pos = $i + 1; $z = $zona ? $zona($pos) : null; ?>
          <tr class="<?= e($z ?? ($pos === 1 ? 'fila-lider' : '')) ?>">
            <td><span class="pos-badge <?= $pos <= 3 && !$zona ? 'top' : '' ?>"><?= $pos ?></span></td>
            <?= pos_celda_equipo($f) ?>
            <td class="text-center"><?= $f['pj'] ?></td><td class="text-center"><?= $f['pg'] ?></td>
            <td class="text-center"><?= $f['pe'] ?></td><td class="text-center"><?= $f['pp'] ?></td>
            <td class="text-center"><?= $f['gf'] ?></td><td class="text-center"><?= $f['gc'] ?></td>
            <?= pos_dif($f['dif']) ?>
            <td class="text-center fw-bold"><?= $f['pts'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/** Tabla por porcentaje (béisbol y similares). */
function pos_tabla_pct(array $filas, string $lblPf = 'CF', string $lblPc = 'CC'): void
{
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-posiciones align-middle">
        <thead class="table-dark"><tr>
          <th style="width:48px">#</th><th>Equipo</th><th class="text-center">PJ</th>
          <th class="text-center">G</th><th class="text-center">P</th><th class="text-center">%</th>
          <th class="text-center"><?= e($lblPf) ?></th><th class="text-center"><?= e($lblPc) ?></th><th class="text-center">DIF</th>
        </tr></thead>
        <tbody>
        <?php foreach ($filas as $i => $f): $pos = $i + 1; ?>
          <tr class="<?= $pos === 1 ? 'fila-lider' : '' ?>">
            <td><span class="pos-badge <?= $pos <= 3 ? 'top' : '' ?>"><?= $pos ?></span></td>
            <?= pos_celda_equipo($f) ?>
            <td class="text-center"><?= $f['pj'] ?></td><td class="text-center"><?= $f['pg'] ?></td>
            <td class="text-center"><?= $f['pp'] ?></td><td class="text-center"><?= number_format($f['pct'], 3) ?></td>
            <td class="text-center"><?= $f['gf'] ?></td><td class="text-center"><?= $f['gc'] ?></td>
            <?= pos_dif($f['dif']) ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

/** NBA: G P PCT, diferencia con el líder, local, visita, últimos 10, racha. */
function pos_tabla_nba(array $filas): void
{
    $lider = $filas[0] ?? null;
    ?>
    <div class="table-responsive">
      <table class="table table-striped table-posiciones align-middle">
        <thead class="table-dark"><tr>
          <th style="width:48px">#</th><th>Equipo</th>
          <th class="text-center">G</th><th class="text-center">P</th><th class="text-center">PCT</th>
          <th class="text-center">DIF. LÍDER</th><th class="text-center">LOCAL</th><th class="text-center">VISITA</th>
          <th class="text-center">U10</th><th class="text-center">RACHA</th>
        </tr></thead>
        <tbody>
        <?php foreach ($filas as $i => $f): $pos = $i + 1;
            $z = $pos <= 6 ? 'zona-verde' : ($pos <= 10 ? 'zona-azul' : 'zona-rojo');
            $dl = $lider ? (($lider['pg'] - $f['pg']) + ($f['pp'] - $lider['pp'])) / 2 : 0; ?>
          <tr class="<?= $z ?>">
            <td><span class="pos-badge"><?= $pos ?></span></td>
            <?= pos_celda_equipo($f) ?>
            <td class="text-center"><?= $f['pg'] ?></td><td class="text-center"><?= $f['pp'] ?></td>
            <td class="text-center fw-bold"><?= ltrim(number_format($f['pct'], 3), '0') ?: '.000' ?></td>
            <td class="text-center"><?= $dl > 0 ? rtrim(rtrim(number_format($dl, 1), '0'), '.') : '-' ?></td>
            <td class="text-center"><?= $f['loc'][0] ?>-<?= $f['loc'][1] ?></td>
            <td class="text-center"><?= $f['vis'][0] ?>-<?= $f['vis'][1] ?></td>
            <td class="text-center"><?= e(pos_ultimos($f['res'])) ?></td>
            <td class="text-center"><?= e(pos_racha($f['res'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="small text-secondary d-flex flex-wrap gap-3 mb-3">
      <span><span class="leyenda zona-verde"></span> 1-6: Playoffs directo</span>
      <span><span class="leyenda zona-azul"></span> 7-10: Play-In</span>
      <span><span class="leyenda zona-rojo"></span> 11-15: Eliminados</span>
    </div>
    <?php
}

/** NFL: una división. Con empates y porcentaje. */
function pos_tabla_nfl(string $titulo, array $filas): void
{
    ?>
    <h2 class="h6 text-uppercase text-secondary mt-4 mb-2"><?= e($titulo) ?></h2>
    <div class="table-responsive">
      <table class="table table-striped table-posiciones align-middle">
        <thead class="table-dark"><tr>
          <th style="width:48px">#</th><th>Equipo</th>
          <th class="text-center">G</th><th class="text-center">P</th><th class="text-center">E</th><th class="text-center">PCT</th>
          <th class="text-center">PF</th><th class="text-center">PC</th><th class="text-center">DIF</th><th class="text-center">RACHA</th>
        </tr></thead>
        <tbody>
        <?php foreach ($filas as $i => $f): $pos = $i + 1; ?>
          <tr class="<?= $pos === 1 ? 'zona-verde' : '' ?>">
            <td><span class="pos-badge"><?= $pos ?></span></td>
            <?= pos_celda_equipo($f) ?>
            <td class="text-center"><?= $f['pg'] ?></td><td class="text-center"><?= $f['pp'] ?></td><td class="text-center"><?= $f['pe'] ?></td>
            <td class="text-center fw-bold"><?= ltrim(number_format($f['pct'], 3), '0') ?: '.000' ?></td>
            <td class="text-center"><?= $f['gf'] ?></td><td class="text-center"><?= $f['gc'] ?></td>
            <?= pos_dif($f['dif']) ?>
            <td class="text-center"><?= e(pos_racha($f['res'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

// ---------- Datos según la vista ----------
$aviso = null;       // mensaje informativo
$primera = null;     // fecha del primer partido usado
$filas = [];
$zonaA = $zonaB = $anual = [];
$nflConf = [];

if ($actual) {
    switch ($vista) {
        case 'argentina':
            $tab = in_array($tab, ['clausura', 'anual'], true) ? $tab : 'clausura';
            if ($tab === 'clausura') {
                [$todas, $primera] = pos_calcular($pdo, pos_partidos($pdo, $idLiga, $cfg, '%lausura%'));
                $zonaA = pos_ordenar_futbol(array_values(array_filter($todas, fn($f) => $f['grupo'] === 'A')));
                $zonaB = pos_ordenar_futbol(array_values(array_filter($todas, fn($f) => $f['grupo'] === 'B')));
                if (!$zonaA && !$zonaB && $todas) {
                    $aviso = 'Los equipos todavía no tienen zona asignada (equipos.grupo = A o B). Ejecutá el script SQL de zonas.';
                }
            } else {
                [$anual, $primera] = pos_calcular($pdo, pos_partidos($pdo, $idLiga, $cfg));
                $anual = pos_ordenar_futbol($anual);
            }
            break;

        case 'champions':
            [$filas, $primera] = pos_calcular($pdo, pos_partidos($pdo, $idLiga, $cfg));
            $filas = pos_ordenar_futbol($filas);
            if ($filas && count($filas) < 36) {
                $aviso = 'La base solo tiene ' . count($filas) . ' de los 36 equipos de la fase de liga: faltan equipos por cargar '
                    . '(por eso la sincronización reporta partidos "sin match").';
            }
            break;

        case 'nba':
            $grupos = array_keys($cfg['grupos']);
            $tab = in_array($tab, ['Oeste', 'Este'], true) ? $tab : 'Oeste';
            [$todas, $primera] = pos_calcular($pdo, pos_partidos($pdo, $idLiga, $cfg));
            $filas = pos_ordenar_pct(pos_filtrar_por_nombres($todas, $cfg['grupos'][$tab]));
            break;

        case 'nfl':
            $tab = in_array($tab, ['AFC', 'NFC'], true) ? $tab : 'AFC';
            [$todas, $primera] = pos_calcular($pdo, pos_partidos($pdo, $idLiga, $cfg));
            foreach ($cfg['grupos'][$tab] as $division => $nombres) {
                $nflConf[$division] = pos_ordenar_pct(pos_filtrar_por_nombres($todas, $nombres));
            }
            break;

        default: // liga normal
            [$filas, $primera] = pos_calcular($pdo, pos_partidos($pdo, $idLiga, $cfg));
            $filas = ($actual['slug'] === 'futbol') ? pos_ordenar_futbol($filas) : pos_ordenar_pct($filas);
    }

    // Aviso de cobertura incompleta (la base no tiene toda la temporada).
    if (!empty($cfg['inicio_real']) && $primera
        && strtotime($primera) > strtotime($cfg['inicio_real'] . ' +14 days')) {
        $aviso = 'La base solo tiene partidos desde el ' . date('d/m/Y', strtotime($primera))
            . ', así que esta tabla no incluye lo jugado antes. Ampliá el rango de la sincronización para completarla.';
    }
}

$hay_datos = $filas || $zonaA || $zonaB || $anual || array_filter($nflConf);

$titulo_pagina = 'Tabla de posiciones';
require __DIR__ . '/includes/header.php';
?>

<style>
  .zona-verde td:first-child { box-shadow: inset 4px 0 0 #22c55e; background: rgba(34,197,94,.08) !important; }
  .zona-azul td:first-child  { box-shadow: inset 4px 0 0 #3b82f6; background: rgba(59,130,246,.08) !important; }
  .zona-azul2 td:first-child { box-shadow: inset 4px 0 0 #60a5fa; background: rgba(96,165,250,.05) !important; }
  .zona-rojo td:first-child  { box-shadow: inset 4px 0 0 #ef4444; background: rgba(239,68,68,.08) !important; }
  .leyenda { display:inline-block; width:12px; height:12px; border-radius:3px; vertical-align:-1px; }
  .leyenda.zona-verde { background:#22c55e; } .leyenda.zona-azul { background:#3b82f6; }
  .leyenda.zona-azul2 { background:#60a5fa; } .leyenda.zona-rojo { background:#ef4444; }
</style>

<h1 class="h3 mb-4"><i class="bi bi-list-ol"></i> Tabla de posiciones</h1>

<?php if (!$actual): ?>
  <div class="alert alert-secondary">No hay ligas configuradas para mostrar.</div>
<?php else: ?>
  <form method="get" class="mb-4" style="max-width: 460px">
    <label class="form-label small">Liga / torneo</label>
    <select name="liga" class="form-select" onchange="this.form.submit()">
      <?php $dep = null; foreach ($ligas as $l):
        if ($dep !== $l['deporte']) {
            if ($dep !== null) echo '</optgroup>';
            echo '<optgroup label="' . e($l['deporte']) . '">';
            $dep = $l['deporte'];
        } ?>
        <option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === $idLiga ? 'selected' : '' ?>>
          <?= e($l['nombre']) ?> · <?= e($l['cfg']['etiqueta']) ?>
        </option>
      <?php endforeach; ?></optgroup>
    </select>
  </form>

  <?php if ($aviso): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($aviso) ?></div>
  <?php endif; ?>

  <?php if ($vista === 'argentina'): ?>
    <?= pos_tabs(['clausura' => 'Clausura 2026 (por zona)', 'anual' => 'Tabla anual'], $tab, $idLiga) ?>
  <?php elseif ($vista === 'nba'): ?>
    <?= pos_tabs(['Oeste' => 'Conferencia Oeste', 'Este' => 'Conferencia Este'], $tab, $idLiga) ?>
  <?php elseif ($vista === 'nfl'): ?>
    <?= pos_tabs(['AFC' => 'AFC', 'NFC' => 'NFC'], $tab, $idLiga) ?>
  <?php endif; ?>

  <?php if (!$hay_datos): ?>
    <div class="alert alert-secondary">
      Todavía no hay partidos de la temporada <?= e($cfg['etiqueta']) ?> en la base para esta liga.
      La tabla aparecerá cuando se sincronice la liga.
    </div>
  <?php elseif ($vista === 'argentina' && $tab === 'clausura'): ?>
    <?php foreach (['A' => $zonaA, 'B' => $zonaB] as $z => $lista): if (!$lista) continue; ?>
      <h2 class="h6 text-uppercase text-secondary mt-3 mb-2">Zona <?= $z ?></h2>
      <?php pos_tabla_futbol($lista, fn(int $p) => $p <= 8 ? 'zona-verde' : null); ?>
    <?php endforeach; ?>
    <div class="small text-secondary"><span class="leyenda zona-verde"></span> 1-8: clasifican a la fase eliminatoria</div>
  <?php elseif ($vista === 'argentina'): ?>
    <p class="small text-secondary">Suma de los puntos de la fase regular del Apertura y del Clausura. Define los cupos a copas internacionales y el descenso.</p>
    <?php pos_tabla_futbol($anual); ?>
  <?php elseif ($vista === 'champions'): ?>
    <?php pos_tabla_futbol($filas, fn(int $p) => $p <= 8 ? 'zona-verde' : ($p <= 16 ? 'zona-azul' : ($p <= 24 ? 'zona-azul2' : 'zona-rojo'))); ?>
    <div class="small text-secondary d-flex flex-wrap gap-3">
      <span><span class="leyenda zona-verde"></span> 1-8: Octavos de final directo</span>
      <span><span class="leyenda zona-azul"></span> 9-16: Play-offs (cabezas de serie)</span>
      <span><span class="leyenda zona-azul2"></span> 17-24: Play-offs (no cabezas de serie)</span>
      <span><span class="leyenda zona-rojo"></span> 25-36: Eliminados</span>
    </div>
  <?php elseif ($vista === 'nba'): ?>
    <?php pos_tabla_nba($filas); ?>
  <?php elseif ($vista === 'nfl'): ?>
    <?php foreach ($nflConf as $division => $lista): pos_tabla_nfl($tab . ' ' . $division, $lista); endforeach; ?>
    <div class="small text-secondary mt-2"><span class="leyenda zona-verde"></span> Líder de división. Los empates cuentan medio partido ganado en el PCT.</div>
  <?php elseif ($actual['slug'] === 'futbol'): ?>
    <?php pos_tabla_futbol($filas); ?>
  <?php else: ?>
    <?php pos_tabla_pct($filas); ?>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>