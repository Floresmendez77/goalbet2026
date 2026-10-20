<?php

declare(strict_types=1);

/**
 * Banner "Matchday" para partido.php: estadio de fondo, escudos de los dos equipos,
 * logo y colores de la competición, y (opcional) la lista de titulares.
 *
 * No consulta nada del detalle de ESPN ni modifica nada: recibe lo que partido.php
 * ya tiene cargado. Solo hace una consulta de lectura al logo de la liga.
 *
 * Uso (una sola línea en partido.php, justo después de </section> del marcador):
 *   <?php require_once __DIR__ . '/includes/alineacion_lista.php'; al_render($pdo, $p, $titulares, $idLocal, $idVisita); ?>
 *
 * Para mostrar también la lista de 11 titulares por equipo, agregá un 6.º argumento:
 *   al_render($pdo, $p, $titulares, $idLocal, $idVisita, true);
 *
 * 7.º argumento opcional: nombre del estadio según ESPN ($estNombre en partido.php). Sirve para
 * encontrar la foto del estadio cuando partidos.id_estadio es NULL:
 *   al_render($pdo, $p, $titulares, $idLocal, $idVisita, false, $estNombre);
 */

/** Colores [principal, acento] de cada competición. Si no la reconoce, usa el dorado del tema. */
function al_paleta_competicion(string $nombre): array
{
    $n = strtolower(strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']));
    $mapa = [
        'champions'   => ['#0b1f5e', '#3d8bff'],
        'premier'     => ['#38003c', '#00e676'],
        'la liga'     => ['#7a0c14', '#ff4b44'],
        'laliga'      => ['#7a0c14', '#ff4b44'],
        'serie a'     => ['#06316b', '#1fa7e0'],
        'bundesliga'  => ['#6b0712', '#e2001a'],
        'ligue 1'     => ['#071b3a', '#dae025'],
        'brasil'      => ['#00552b', '#ffdf00'],
        'argentina'   => ['#0a3a66', '#75aadb'],
        'liga mx'     => ['#0b3d2e', '#c8102e'],
        'major league' => ['#0b1a33', '#e31b3b'],
    ];
    foreach ($mapa as $clave => $colores) {
        if (str_contains($n, $clave)) {
            return $colores;
        }
    }
    return ['#1e293b', '#f59e0b'];
}

/** Solo URLs http(s): nada de javascript: ni data: en src. */
function al_url(?string $u): string
{
    return ($u !== null && preg_match('#^https?://#i', $u)) ? $u : '';
}

/** Apellido para la lista: "Alexander Bernabei" -> "Bernabei". */
function al_apellido(string $n): string
{
    $p = preg_split('/\s+/u', trim($n)) ?: [$n];
    return count($p) > 1 ? (string) end($p) : $n;
}

/** Minúsculas, sin tildes ni signos, espacios colapsados: "Stade Bollaert-Delelis" -> "stade bollaert delelis". */
function al_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
    ]);
    $s = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    return trim((string) preg_replace('/\s+/', ' ', $s));
}

/**
 * Foto del estadio para el fondo del banner (solo lectura). Orden:
 *   1) estadios.imagen del estadio del partido (partidos.id_estadio)
 *   2) estadios.imagen del estadio del equipo local (equipos.id_estadio)
 *   3) por nombre: $nombreEspn contra estadios.nombre (normalizado), exacto o "contiene";
 *      si hay más de un candidato igual de bueno, no elige ninguno
 *   4) '' -> el banner queda con el degradado
 * Cualquier error de base de datos devuelve '' y no rompe la página.
 */
function al_imagen_estadio(PDO $pdo, array $p, ?string $nombreEspn): string
{
    try {
        // 1) estadio del partido. partido.php ya lo trae en est_imagen (JOIN por id_estadio).
        if (array_key_exists('est_imagen', $p)) {
            $u = al_url(is_string($p['est_imagen']) ? $p['est_imagen'] : null);
        } else {
            $u = '';
            $idEst = (int) ($p['id_estadio'] ?? 0);
            if ($idEst > 0) {
                $q = $pdo->prepare('SELECT imagen FROM estadios WHERE id = ? LIMIT 1');
                $q->execute([$idEst]);
                $u = al_url(((string) $q->fetchColumn()) ?: null);
            }
        }
        if ($u !== '') {
            return $u;
        }

        // 2) estadio del equipo local
        $idLocal = (int) ($p['id_equipo_local'] ?? 0);
        if ($idLocal > 0) {
            $q = $pdo->prepare(
                'SELECT es.imagen FROM equipos eq JOIN estadios es ON es.id = eq.id_estadio WHERE eq.id = ? LIMIT 1'
            );
            $q->execute([$idLocal]);
            $u = al_url(((string) $q->fetchColumn()) ?: null);
            if ($u !== '') {
                return $u;
            }
        }

        // 3) por nombre
        $buscado = al_norm((string) $nombreEspn);
        if (mb_strlen($buscado) >= 4) {
            $q = $pdo->prepare("SELECT nombre, imagen FROM estadios WHERE imagen IS NOT NULL AND imagen <> ''");
            $q->execute();
            $exactos = [];
            $contiene = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $img = al_url((string) $r['imagen']);
                $nom = al_norm((string) $r['nombre']);
                if ($img === '' || $nom === '') {
                    continue;
                }
                if ($nom === $buscado) {
                    $exactos[$img] = true;
                } elseif (mb_strlen($nom) >= 5 && (str_contains($nom, $buscado) || str_contains($buscado, $nom))) {
                    $contiene[$img] = true;
                }
            }
            // Gana el mejor nivel que tenga candidatos; si ese nivel tiene más de uno distinto, ninguno.
            if ($exactos) {
                return count($exactos) === 1 ? (string) array_key_first($exactos) : '';
            }
            if (count($contiene) === 1) {
                return (string) array_key_first($contiene);
            }
        }
    } catch (Throwable) {
        return '';
    }
    return ''; // 4) sin coincidencia: degradado
}

/**
 * @param array $p          fila del partido que ya arma partido.php (equipo_local, escudo_local, liga, id_liga, est_*, fecha_partido...)
 * @param array $titulares  [id_equipo => [filas de alineación titulares]]
 * @param ?string $nombreEspn nombre del estadio según el detalle de ESPN (opcional)
 */
function al_render(PDO $pdo, array $p, array $titulares, int $idLocal, int $idVisita, bool $conLista = false, ?string $nombreEspn = null): void
{
    $tl = $titulares[$idLocal] ?? [];
    $tv = $titulares[$idVisita] ?? [];
    if (!$tl && !$tv) {
        return; // sin alineaciones: el bloque no se muestra
    }

    // Logo de la competición (lectura simple; si falla o no hay, queda solo el nombre).
    $logo = '';
    if (!empty($p['id_liga'])) {
        try {
            $q = $pdo->prepare('SELECT logo FROM ligas_torneos WHERE id = ?');
            $q->execute([(int) $p['id_liga']]);
            $logo = al_url(($q->fetchColumn() ?: null));
        } catch (Throwable) {
            $logo = '';
        }
    }

    $liga = (string) ($p['liga'] ?? 'Amistoso');
    [$c1, $c2] = al_paleta_competicion($liga);
    $estadio = (string) ($p['est_nombre'] ?? '') !== '' ? (string) $p['est_nombre'] : trim((string) $nombreEspn);
    $ciudad = (string) ($p['est_ciudad'] ?? '');
    $imgEstadio = al_imagen_estadio($pdo, $p, $nombreEspn);
    $hora = fecha_partido_legible($p['fecha_partido'] ?? null, 'd/m/Y h:i A') . ' · ' . app_tz_etiqueta();
    ?>
<section class="al-wrap<?= $conLista ? ' al-con-lista' : '' ?>" style="--al-c1:<?= e($c1) ?>;--al-c2:<?= e($c2) ?>" aria-label="Presentación del partido">
  <?php if ($imgEstadio !== ''): ?>
    <img class="al-bg" src="<?= e($imgEstadio) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display='none'">
  <?php endif; ?>
  <div class="al-veil"></div>

  <header class="al-bar">
    <strong class="al-title"><?= e((string) $p['equipo_local']) ?> <span>vs</span> <?= e((string) $p['equipo_visita']) ?></strong>
    <span class="al-when">
      <span class="al-comp"><?= e($liga) ?><?= !empty($p['jornada']) ? ' · Jornada ' . (int) $p['jornada'] : '' ?></span>
      <span class="al-kick"><i class="bi bi-clock"></i> Inicio: <?= e($hora) ?></span>
    </span>
  </header>

  <div class="al-stage">
    <?php foreach ([['LOCAL', 'al-home', $p['escudo_local'] ?? null, $p['equipo_local'], $tl], ['VISITANTE', 'al-away', $p['escudo_visita'] ?? null, $p['equipo_visita'], $tv]] as $i => [$rol, $clase, $escudo, $nombre, $lista]): ?>
      <?php if ($i === 1): ?>
        <div class="al-center">
          <?php if ($logo !== ''): ?>
            <img class="al-logo" src="<?= e($logo) ?>" alt="<?= e($liga) ?>" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display='none'">
          <?php endif; ?>
          <span class="al-vs">VS</span>
          <span class="al-liga"><?= e($liga) ?></span>
        </div>
      <?php endif; ?>
      <div class="al-team <?= $clase ?>">
        <div class="al-panel">
          <div class="al-crest"><?= escudo_equipo($escudo, (string) $nombre, 'lg') ?></div>
        </div>
        <div class="al-plate"><span class="al-role"><?= $rol ?></span><strong><?= e((string) $nombre) ?></strong></div>
        <?php if ($conLista && $lista): ?>
          <ol class="al-list">
            <?php foreach ($lista as $j): ?>
              <li>
                <span class="al-num"><?= $j['dorsal'] !== null ? (int) $j['dorsal'] : '–' ?></span>
                <?= e(al_apellido((string) $j['nombre'])) ?><?= in_array(strtoupper((string) ($j['posicion'] ?? '')), ['G', 'GK'], true) ? ' <em>(GK)</em>' : '' ?>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($estadio !== ''): ?>
    <footer class="al-foot">
      <i class="bi bi-geo-alt-fill"></i> <?= e($estadio) ?><?= $ciudad !== '' ? ', ' . e($ciudad) : '' ?>
    </footer>
  <?php endif; ?>
</section>
<?php
}
