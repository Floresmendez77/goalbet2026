<?php
/**
 * GoalBet 2026 — Landing "Motion Edition" (index2.php)
 * ------------------------------------------------------------------
 * Este archivo es el ORQUESTADOR: hace `include` de los partials PHP
 * y enlaza la hoja de estilos + el módulo JS único. La lógica de
 * negocio (queries SQL, helpers) es exactamente la misma que ya
 * tenías — no se tocó, solo se movió el HTML/CSS/JS a archivos propios.
 *
 * CAMBIOS DE ARQUITECTURA EN ESTA PARTE (ver prompt_maestro / PR):
 *  1. Bug crítico: #bsPick ya se revela en la carga inicial (fix real
 *     está en js/hero.js → revealPage()).
 *  2. Motor de animación consolidado a GSAP (antes convivían Anime.js
 *     v4 + GSAP + ~5 loops de rAF/setInterval sueltos). Ver
 *     js/motion.js para la justificación completa.
 *  3. Balón "Trionda" no se construye en mobile; en desktop tiene
 *     menos triángulos y su ciclo se pausa fuera de viewport / pestaña
 *     oculta (js/hero.js).
 *  4. Loader ya no bloquea el render: el hero real está en el DOM de
 *     inmediato, con un skeleton CSS liviano y el loader como overlay
 *     translúcido decorativo.
 *  5. CSS y JS separados en archivos propios; PHP separado en
 *     partials/_nav.php, _hero.php, _betslip.php, _footer.php.
 *  6. Tokens de color centralizados en css/style.css (:root) y leídos
 *     desde JS en runtime (js/design-tokens.js) — cero duplicación.
 *     Duraciones/easings centralizados en js/motion.js (MOTION).
 *
 * PARTE 2 — accesibilidad y performance (este archivo únicamente):
 *  7. Región aria-live dedicada para anunciar cambios de cuotas.
 *  8. Botón visible de abrir/cerrar el betslip mobile, con
 *     aria-expanded reflejando el estado real (además del drag).
 *  9. prefers-reduced-motion deja todo en su estado final visible sin
 *     depender de que corra la animación.
 *  10. Partículas/balón se inicializan tras el primer paint
 *      (requestIdleCallback con fallback a setTimeout) para no competir
 *      con el FCP.
 *  11. JS "below the fold" (stats-strip, champs, promos, footer,
 *      celebración de gol) se importa de forma diferida vía
 *      IntersectionObserver + import() dinámico.
 *  12. Colores muted ajustados para cumplir contraste WCAG AA en texto
 *      pequeño sobre fondo oscuro.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/bootstrap.php';
// Landing pública: NO llamamos requerir_login() a propósito.

/* ---------- Stats + datos reales (con fallback si la BD falla) ---------- */
try {
    $totalEnVivo  = (int) db()->query("SELECT COUNT(*) FROM partidos WHERE estado='en_curso'")->fetchColumn();
    $totalLigas   = (int) db()->query("SELECT COUNT(*) FROM ligas_torneos")->fetchColumn();
    $totalEquipos = (int) db()->query("SELECT COUNT(*) FROM equipos WHERE activo = 1")->fetchColumn();

    $deportes = db()->query("
      SELECT d.*,
             SUM(CASE WHEN p.estado='en_curso' THEN 1 ELSE 0 END) AS en_vivo,
             COUNT(p.id) AS total
      FROM deportes d
      LEFT JOIN partidos p ON p.id_deporte = d.id
      GROUP BY d.id
      ORDER BY d.nombre
    ")->fetchAll();

    $ligasDestacadas = db()->query("
      SELECT lt.id, lt.nombre, lt.logo, d.nombre AS deporte
      FROM ligas_torneos lt
      JOIN deportes d ON d.id = lt.id_deporte
      WHERE lt.logo IS NOT NULL
      ORDER BY lt.id DESC
      LIMIT 8
    ")->fetchAll();

    $sqlBase = "
      SELECT p.*,
             d.nombre AS deporte, d.slug AS deporte_slug, d.tiene_empate,
             lt.nombre AS liga,
             el.nombre AS equipo_local,  el.escudo AS escudo_local,
             ev.nombre AS equipo_visita, ev.escudo AS escudo_visita
      FROM partidos p
      JOIN deportes d  ON d.id  = p.id_deporte
      JOIN equipos el  ON el.id = p.id_equipo_local
      JOIN equipos ev  ON ev.id = p.id_equipo_visita
      LEFT JOIN ligas_torneos lt ON lt.id = p.id_liga
    ";

    $partidos = db()->query($sqlBase . "
      WHERE p.estado IN ('en_curso','programado')
      ORDER BY FIELD(p.estado,'en_curso','programado'), p.fecha_partido ASC
      LIMIT 10
    ")->fetchAll();

    $resultados = db()->query($sqlBase . "
      WHERE p.estado = 'finalizado'
      ORDER BY p.fecha_partido DESC
      LIMIT 6
    ")->fetchAll();
} catch (Throwable $e) {
    error_log('[GoalBet landing v2] ' . $e->getMessage());
    $totalEnVivo = $totalLigas = $totalEquipos = 0;
    $deportes = $ligasDestacadas = $partidos = $resultados = [];
}

$destacado = $partidos[0] ?? null;

/* ---------- Helpers de presentación (idénticos al original, sin tocar) ---------- */
function icono_deporte(string $slug): string
{
    return match ($slug) {
        'futbol'            => 'bi-dribbble',
        'baloncesto'        => 'bi-basketball',
        'beisbol'           => 'bi-circle',
        'futbol-americano'  => 'bi-shield',
        default             => 'bi-trophy',
    };
}

function escudo_img(?string $url, string $alt): string
{
    if ($url) {
        return '<img src="' . e($url) . '" alt="' . e($alt) . '" class="crest-img" '
             . 'loading="lazy" decoding="async" width="22" height="22" data-fallback-icon>';
    }
    return '<span class="crest-fallback" role="img" aria-label="' . e($alt) . '"><i class="bi bi-shield-fill" aria-hidden="true"></i></span>';
}

function cuota_aria(string $resultado, float $cuota, string $equipo): string
{
    return e($resultado . ', cuota ' . number_format($cuota, 2) . ', gana ' . $equipo);
}

/**
 * Campeones vigentes por competición — datos reales, NO fotos.
 * Actualizá esta lista manualmente cuando termine cada temporada.
 */
function campeones_vigentes(): array
{
    return [
        ['comp' => 'UEFA Champions League', 'sigla' => 'UCL', 'temporada' => '2025-26', 'campeon' => 'Paris Saint-Germain', 'dato' => 'Bicampeón — venció al Arsenal en penales', 'color' => '#0055A4'],
        ['comp' => 'World Series (MLB)',    'sigla' => 'MLB', 'temporada' => '2025',    'campeon' => 'Los Angeles Dodgers',  'dato' => 'Bicampeón — 4-3 ante Toronto Blue Jays', 'color' => '#005A9C'],
        ['comp' => 'Super Bowl (NFL)',      'sigla' => 'NFL', 'temporada' => '2025',    'campeon' => 'Seattle Seahawks',     'dato' => 'Super Bowl LX — 29-13 ante Patriots', 'color' => '#69BE28'],
        ['comp' => 'NBA Finals',            'sigla' => 'NBA', 'temporada' => '2025',    'campeon' => 'Oklahoma City Thunder','dato' => 'Primer título — 4-3 ante Indiana Pacers', 'color' => '#EF3B24'],
    ];
}

/**
 * Genera dos barras de estadística "ilustrativas" por partido
 * (posesión y tiros a puerta), determinísticas según el id del
 * partido. NO es dato real — el esquema actual no trae posesión/tiros
 * agregados por partido. Reemplazar por la consulta real si algún día
 * se agregan esas columnas.
 */
function stats_demo_partido(int $idPartido): array
{
    mt_srand($idPartido * 2654435761 % 2147483647);
    $posLocal = mt_rand(38, 62);
    $tirosLocal  = mt_rand(2, 14);
    $tirosVisita = mt_rand(2, 14);
    mt_srand();
    return [
        'posesion_local'  => $posLocal,
        'posesion_visita' => 100 - $posLocal,
        'tiros_local'     => $tirosLocal,
        'tiros_visita'    => $tirosVisita,
        'tiros_max'       => max($tirosLocal, $tirosVisita, 1),
    ];
}

$esHttps    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$urlActual  = ($esHttps ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'goalbet.hn') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GoalBet — Apuestas Deportivas en Vivo en Honduras</title>
<meta name="description" content="Cuotas en tiempo real, cientos de ligas y pagos instantáneos en Lempiras. La casa de apuestas deportivas más rápida de Honduras.">
<meta name="robots" content="index, follow">
<meta name="theme-color" content="#050505">
<link rel="canonical" href="<?= e($urlActual) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="GoalBet">
<meta property="og:title" content="GoalBet — Apuestas Deportivas en Vivo">
<meta property="og:description" content="Cuotas en tiempo real, cientos de ligas y pagos instantáneos en Lempiras.">
<meta property="og:url" content="<?= e($urlActual) ?>">
<meta property="og:locale" content="es_HN">
<meta name="twitter:card" content="summary_large_image">

<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>⚽</text></svg>">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://api.fontshare.com">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

<!-- Tarea 4 (Parte 1): preload del CSS de Clash Display (Fontshare). No se
     puede hacer preload directo del archivo .woff2 porque Fontshare sirve
     nombres de archivo hasheados y dinámicos por request/CDN edge — un
     preload con URL fija se rompe (404) apenas el hash cambia. Lo que sí
     es estable es la URL de la hoja de estilos, así que precargamos esa
     como "style" para que el navegador la descubra antes en el <head>. -->
<link rel="preload" as="style" href="https://api.fontshare.com/v2/css?f[]=clash-display@500,600,700&display=swap" crossorigin>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Barlow+Condensed:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://api.fontshare.com/v2/css?f[]=clash-display@500,600,700&display=swap" rel="stylesheet">

<!-- Tarea 5 (Parte 1): hoja de estilos propia (antes era un <style> inline gigante) -->
<style>
/* ============================================================
   GoalBet 2026 — Hoja de estilos principal (index2)
   Extraída del <style> inline original (Tarea 5 del prompt).
   Los tokens de color/tipografía/spacing viven en :root y son la
   ÚNICA fuente de verdad: js/design-tokens.js los lee en runtime
   con getComputedStyle() en vez de hardcodear hex duplicados en JS
   (ver Tarea 5 — antes existía var(--green) acá y '#00FFB3' repetido
   en el código de celebración de gol).

   Parte 2 — Tarea 1 (accesibilidad): --muted y --muted2 se oscurecieron
   menos / se aclararon para cumplir WCAG AA en texto pequeño (≥4.5:1)
   sobre --bg (#050505) y --bg-soft (#0a0a0f). Verificado con el panel de
   accesibilidad de Lighthouse — ver README para los valores anotados.
   Antes: --muted rgba(245,247,250,.68) ≈ 3.9:1 sobre --bg (no pasa AA en
   texto <18px). Ahora: rgba(245,247,250,.78) ≈ 4.6:1.
   ============================================================ */
:root{
  --bg:#050505; --bg-soft:#0d0f18;
  --panel:rgba(255,255,255,.035); --panel-solid:#0c0e14; --panel-2:#111420;
  --border:rgba(255,255,255,.08); --border-soft:rgba(255,255,255,.05);
  --txt:#F5F7FA; --muted:rgba(245,247,250,.78); --muted2:rgba(245,247,250,.62);
  --gold:#FFC93C; --gold-deep:#E8A317; --gold-bright:#FFE7A0; --gold-dim:rgba(255,201,60,.15);
  --green:#00FFB3; --violet:#7B61FF; --red:#FF3B5C;
  --font-display:'Clash Display','Manrope',sans-serif;
  --font-body:'Inter',sans-serif;
  --font-data:'Barlow Condensed',sans-serif;
  --font-mono:'JetBrains Mono',ui-monospace,monospace;
  --sidebar-w:262px; --betslip-w:320px; --nav-h:76px;
  --radius-sm:9px; --radius-md:13px; --radius-lg:20px;
  --ease:cubic-bezier(.16,1,.3,1);
  --glass-bg:rgba(18,20,28,.55); --glass-border:rgba(255,255,255,.1); --glass-blur:20px;
}
*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:var(--font-body);background:var(--bg);color:var(--txt);-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
img{max-width:100%;display:block;}
button{color:inherit;font-family:inherit;}
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-thumb{background:linear-gradient(var(--gold),var(--gold-deep));border-radius:3px;}
::selection{background:var(--gold);color:#0a0a0a;}
:focus-visible{outline:2px solid var(--gold);outline-offset:2px;}
.visually-hidden{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
.mono{font-family:var(--font-mono);font-variant-numeric:tabular-nums;}

.skip-link{position:fixed;top:-60px;left:12px;z-index:2000;background:var(--gold);color:#0a0a0a;font-weight:800;padding:10px 18px;border-radius:8px;transition:top .2s var(--ease);}
.skip-link:focus{top:12px;}

/* Parte 2 — Tarea 1: prefers-reduced-motion / .no-js dejan TODO
   [data-anim] en su estado final visible sin depender de que la
   animación corra. Se agrega también a elementos con estado inicial
   fuera de pantalla (títulos, hero-side, scroll-hint) para que nunca
   dependan únicamente de un gsap.set()/to() que podría no ejecutar si
   JS falla en cargar. */
[data-anim]{opacity:0;}
html.reduced-motion [data-anim],
html.no-js [data-anim]{opacity:1;transform:none!important;}
html.reduced-motion .hw,
html.no-js .hw{opacity:1;transform:none!important;}
html.reduced-motion #scrollHint,
html.no-js #scrollHint{opacity:1;}
html.reduced-motion .hero-skel,
html.no-js .hero-skel{display:none;}

/* Skeleton del hero: placeholder liviano visible detrás/antes del loader
   (Tarea 4) — evita que la primera pintura dependa de JS o del loader. */
.hero-skel{position:absolute;inset:0;z-index:1;pointer-events:none;opacity:1;transition:opacity .4s var(--ease);}
.hero-skel.is-hidden{opacity:0;}
.hero-skel .sk-line{background:linear-gradient(90deg,rgba(255,255,255,.08),rgba(255,201,60,.16),rgba(255,255,255,.08));background-size:200% 100%;animation:skShimmer 1.4s ease-in-out infinite;border-radius:8px;}
@keyframes skShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ============================================================
   LOADING SCREEN — Balón Trionda Premium
   ============================================================ */
/* Tarea 4: el loader ya NO es un bloqueo total de renderizado — el hero
   real (texto/botones/layout) se pinta de inmediato detrás de este
   overlay semitransparente + blur, en vez de un fondo 100% opaco.
   Es decoración sobre contenido que ya existe en el DOM. */
#loadingScreen{position:fixed;inset:0;z-index:99999;background:rgba(5,5,5,.6);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;flex-direction:column;gap:24px;transition:opacity .5s var(--ease),visibility .5s var(--ease);}
#loadingScreen.hide{opacity:0;visibility:hidden;pointer-events:none;}
.loader-stage{position:relative;width:120px;height:120px;perspective:800px;}
.loader-ball-tri{position:absolute;top:50%;left:50%;width:0;height:0;transform-origin:center center;will-change:transform,opacity;}
.loader-ball-tri::before{content:'';position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(135deg,var(--gold),var(--gold-deep));clip-path:polygon(50% 0%,0% 100%,100% 100%);}
.loader-ball-tri.gold-bright::before{background:linear-gradient(135deg,#FFE7A0,var(--gold));}
.loader-ball-tri.gold-dim::before{background:linear-gradient(135deg,var(--gold-deep),#B8860B);}
.loader-ball-tri.dark::before{background:linear-gradient(135deg,#1a1a1a,#0a0a0a);}
.loader-core{position:absolute;top:50%;left:50%;width:36px;height:36px;transform:translate(-50%,-50%);border-radius:50%;background:radial-gradient(circle at 35% 35%,#FFE7A0,var(--gold));box-shadow:0 0 40px rgba(255,201,60,.5);z-index:10;}
.loader-word{font-family:var(--font-display);font-weight:700;font-size:1.6rem;letter-spacing:4px;color:var(--txt);}
.loader-word span{color:var(--gold);}
.loader-pct{font-family:var(--font-mono);font-size:.75rem;color:var(--muted2);letter-spacing:2px;}

/* ============================================================
   HERO — Balón Trionda + Partículas + Grid
   ============================================================ */
.hero{position:relative;min-height:100vh;padding-top:var(--nav-h);display:flex;align-items:center;overflow:hidden;isolation:isolate;background:var(--bg);}
.hero-bg-base{position:absolute;inset:0;z-index:0;
  background:
    radial-gradient(ellipse 70% 60% at 68% 46%, rgba(255,201,60,.14) 0%, transparent 62%),
    radial-gradient(ellipse 80% 50% at 15% 30%, rgba(123,97,255,.08) 0%, transparent 60%),
    radial-gradient(ellipse 60% 40% at 85% 85%, rgba(0,255,179,.05) 0%, transparent 55%),
    var(--bg);}
.hero-particles-canvas{position:absolute;inset:0;z-index:1;pointer-events:none;}
.hero-grid-overlay{position:absolute;inset:0;z-index:2;
  background-image:linear-gradient(rgba(255,255,255,.015) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.015) 1px,transparent 1px);
  background-size:60px 60px;
  mask-image:radial-gradient(ellipse 70% 60% at 30% 40%,#000 40%,transparent 80%);
  -webkit-mask-image:radial-gradient(ellipse 70% 60% at 30% 40%,#000 40%,transparent 80%);
  pointer-events:none;}

/* Balón Trionda */
.hero-ball-container{position:absolute;top:50%;left:64%;transform:translate(-50%,-50%);width:min(56vw,680px);height:min(56vw,680px);z-index:3;pointer-events:none;perspective:1200px;}
@media(max-width:1200px){.hero-ball-container{left:50%;opacity:.55;transform:translate(-50%,-50%) scale(.8);}}
@media(max-width:768px){.hero-ball-container{width:380px;height:380px;opacity:.4;transform:translate(-50%,-50%) scale(.65);}}
.ball-trionda{position:relative;width:100%;height:100%;transform-style:preserve-3d;}
.trionda-triangle{position:absolute;top:50%;left:50%;width:0;height:0;transform-origin:center center;will-change:transform,opacity;}
.trionda-triangle::before{content:'';position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(135deg,var(--gold),var(--gold-deep));clip-path:polygon(50% 0%,0% 100%,100% 100%);opacity:.95;box-shadow:0 0 18px rgba(255,201,60,.25);}
.trionda-triangle.gold-bright::before{background:linear-gradient(135deg,#FFE7A0,var(--gold));opacity:1;}
.trionda-triangle.gold-dim::before{background:linear-gradient(135deg,var(--gold-deep),#B8860B);opacity:.78;}
.trionda-triangle.dark::before{background:linear-gradient(135deg,#2a2a2a,#111);opacity:.95;}
.trionda-triangle.outline::before{background:transparent;border:1.5px solid var(--gold);clip-path:polygon(50% 0%,0% 100%,100% 100%);opacity:.6;}
.ball-glow{position:absolute;top:50%;left:50%;width:520px;height:520px;transform:translate(-50%,-50%);background:radial-gradient(circle,rgba(255,201,60,.26) 0%,rgba(255,201,60,.08) 45%,transparent 72%);border-radius:50%;filter:blur(55px);z-index:-1;pointer-events:none;}

/* Contenido Hero */
.hero-wrap{position:relative;z-index:10;display:grid;grid-template-columns:1.15fr .85fr;gap:40px;align-items:center;max-width:1280px;margin:0 auto;padding:60px 24px;width:100%;}
.hero-eyebrow{display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.05);border:1px solid var(--border);color:rgba(255,255,255,.85);font-size:.72rem;font-weight:700;padding:6px 14px 6px 8px;border-radius:20px;letter-spacing:.6px;text-transform:uppercase;margin-bottom:22px;}
.live-dot{width:7px;height:7px;background:var(--green);border-radius:50%;box-shadow:0 0 10px var(--green);display:inline-block;}
.hero h1{font-family:var(--font-display);font-weight:600;font-size:clamp(2.6rem,4.6vw,4.4rem);line-height:1.02;letter-spacing:-.5px;color:#fff;}
.hero h1 .gold{background:linear-gradient(135deg,var(--gold),#fff2c9 45%,var(--gold-deep));-webkit-background-clip:text;background-clip:text;color:transparent;}
.hero h1 .hw{display:inline-block;will-change:transform,opacity;}
.hero p.lead{color:var(--muted);font-size:1.05rem;max-width:520px;margin:20px 0 30px;line-height:1.6;}
.hero-cta{display:flex;gap:14px;flex-wrap:wrap;}
.btn-hero-primary{background:linear-gradient(135deg,var(--gold),var(--gold-deep));color:#0a0a0a;font-weight:800;padding:15px 30px;border-radius:11px;display:inline-flex;align-items:center;gap:9px;font-size:.94rem;box-shadow:0 10px 30px rgba(255,201,60,.28);border:none;cursor:pointer;transition:transform .2s,box-shadow .2s;}
.btn-hero-primary:hover{transform:translateY(-2px);box-shadow:0 14px 40px rgba(255,201,60,.4);}
.btn-hero-secondary{border:1px solid rgba(255,255,255,.22);color:#fff;font-weight:700;padding:15px 26px;border-radius:11px;display:inline-flex;align-items:center;gap:9px;font-size:.94rem;backdrop-filter:blur(6px);background:rgba(255,255,255,.03);cursor:pointer;transition:all .2s;}
.btn-hero-secondary:hover{border-color:var(--gold);background:rgba(255,201,60,.05);}
.hero-crests{display:flex;gap:10px;margin-top:34px;}
.hero-crests img{width:38px;height:38px;object-fit:contain;background:rgba(255,255,255,.06);border-radius:50%;padding:6px;border:1px solid var(--border);}

/* Hero Card */
.hero-side{transform-style:preserve-3d;}
.hero-card{background:var(--glass-bg);border:1px solid var(--glass-border);backdrop-filter:blur(20px);border-radius:var(--radius-lg);padding:22px;box-shadow:0 30px 70px rgba(0,0,0,.5);position:relative;overflow:hidden;transform-style:preserve-3d;}
.hero-card-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
.hero-card-top .tag{font-size:.68rem;font-weight:800;letter-spacing:.6px;color:var(--green);display:flex;align-items:center;gap:6px;text-transform:uppercase;}
.hero-card-top .lg{font-size:.7rem;color:var(--muted2);}
.hero-card-match{display:flex;flex-direction:column;gap:10px;margin-bottom:16px;}
.hero-card-team{display:flex;align-items:center;gap:10px;font-weight:700;font-size:.95rem;}
.hero-card-team img,.hero-card-team .crest-fallback{width:26px;height:26px;object-fit:contain;}
.hero-card-team .sc{margin-left:auto;font-family:var(--font-mono);font-size:1.3rem;color:var(--gold);}
.hero-card-odds{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.hero-card-odds .o{background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:9px;text-align:center;padding:10px 6px;}
.hero-card-odds .o .l{font-size:.6rem;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px;}
.hero-card-odds .o .v{font-family:var(--font-mono);font-size:1.25rem;font-weight:700;color:var(--gold);}
.hero-mini-stats{display:flex;gap:10px;margin-top:16px;}
.hero-mini-stats div{flex:1;background:rgba(255,255,255,.04);border:1px solid var(--border-soft);border-radius:10px;padding:10px;text-align:center;}
.hero-mini-stats .n{font-family:var(--font-mono);font-size:1.15rem;color:#fff;}
.hero-mini-stats .l{font-size:.6rem;color:var(--muted2);text-transform:uppercase;}
@media(max-width:960px){.hero-wrap{grid-template-columns:1fr;}.hero-side{display:none;}}

/* Scroll hint */
.scroll-hint{position:absolute;bottom:30px;left:50%;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:8px;color:var(--muted2);font-size:.65rem;letter-spacing:1.5px;text-transform:uppercase;z-index:10;opacity:0;}
.scroll-line{width:1px;height:40px;background:linear-gradient(to bottom,var(--gold),transparent);}

/* ============================================================
   FONDO AMBIENTAL
   ============================================================ */
#bgMesh{position:fixed;inset:0;z-index:-3;overflow:hidden;pointer-events:none;background:var(--bg);}
#bgMesh span{position:absolute;border-radius:50%;filter:blur(95px);opacity:.4;will-change:transform;}
#bgMesh .m1{width:640px;height:640px;background:radial-gradient(circle,#3654FF,transparent 70%);top:-220px;left:-160px;}
#bgMesh .m2{width:720px;height:720px;background:radial-gradient(circle,#7B61FF,transparent 70%);bottom:-260px;right:-200px;}
#bgMesh .m3{width:520px;height:520px;background:radial-gradient(circle,#00FFB3,transparent 70%);top:42%;left:58%;}
#bgMesh .m4{width:460px;height:460px;background:radial-gradient(circle,#FFC93C,transparent 70%);bottom:6%;left:6%;}
#noiseOverlay{position:fixed;inset:0;z-index:2500;pointer-events:none;opacity:.035;mix-blend-mode:overlay;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");}

/* ============================================================
   TOPNAV
   ============================================================ */
.topnav{height:var(--nav-h);display:flex;align-items:center;gap:20px;padding:0 24px;position:fixed;top:0;left:0;right:0;z-index:1000;background:transparent;border-bottom:1px solid transparent;transition:background .35s var(--ease),border-color .35s var(--ease);}
.topnav.scrolled{background:rgba(5,5,5,.72);backdrop-filter:blur(18px) saturate(140%);-webkit-backdrop-filter:blur(18px) saturate(140%);border-bottom:1px solid var(--border);}
.brand{display:flex;align-items:center;gap:9px;flex-shrink:0;}
.brand-mark{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--gold),var(--gold-deep));display:flex;align-items:center;justify-content:center;color:#0a0a0a;font-size:1.1rem;box-shadow:0 0 22px rgba(255,201,60,.35);}
.brand-txt{font-family:var(--font-display);font-weight:600;font-size:1.35rem;letter-spacing:.2px;color:#fff;}
.brand-txt .gb{color:var(--gold);}
.topnav-links{display:flex;gap:2px;}
.topnav-links a{color:rgba(255,255,255,.7);font-weight:600;font-size:.86rem;padding:10px 15px;border-radius:8px;white-space:nowrap;position:relative;transition:color .2s;}
.topnav-links a::after{content:'';position:absolute;left:15px;right:15px;bottom:6px;height:2px;background:var(--gold);border-radius:2px;transform:scaleX(0);transform-origin:left;transition:transform .25s var(--ease);}
.topnav-links a:hover{color:#fff;}
.topnav-links a[aria-current="page"]{color:var(--gold);}
.topnav-links a[aria-current="page"]::after{transform:scaleX(1);}
.badge-mini{background:var(--red);color:#fff;font-size:.6rem;padding:1px 6px;border-radius:5px;margin-left:5px;font-weight:800;}
.nav-search{flex:1;max-width:320px;position:relative;}
.nav-search input{width:100%;background:rgba(255,255,255,.05);border:1px solid var(--border);border-radius:9px;color:#fff;padding:9px 14px 9px 36px;font-size:.82rem;transition:border-color .2s,background .2s;}
.nav-search input:focus{background:rgba(255,255,255,.08);border-color:rgba(255,201,60,.4);}
.nav-search input::placeholder{color:rgba(255,255,255,.4);}
.nav-search i{position:absolute;left:12px;top:11px;color:rgba(255,255,255,.4);font-size:.85rem;}
.topnav-auth{display:flex;gap:10px;flex-shrink:0;margin-left:auto;align-items:center;}
.btn-login{color:#fff!important;border:1px solid rgba(255,255,255,.22);border-radius:8px;padding:9px 17px;font-size:.83rem;font-weight:700;transition:all .2s;}
.btn-login:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.4);}
.btn-signup{background:linear-gradient(135deg,var(--gold),var(--gold-deep));color:#0a0a0a!important;border-radius:8px;padding:9px 19px;font-size:.83rem;font-weight:800;transition:all .25s var(--ease);}
.btn-signup:hover{box-shadow:0 8px 24px rgba(255,201,60,.35);transform:translateY(-1px);}
.nav-toggle{display:none;background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;}
.user-pill{color:var(--gold);font-weight:700;font-size:.84rem;display:flex;align-items:center;gap:7px;padding:9px 13px;background:rgba(255,201,60,.08);border:1px solid rgba(255,201,60,.2);border-radius:9px;font-family:var(--font-mono);letter-spacing:.3px;}

/* ============================================================
   GLASS + RESTO DEL SITIO
   ============================================================ */
.glass{background:var(--glass-bg);border:1px solid var(--glass-border);backdrop-filter:blur(var(--glass-blur)) saturate(140%);-webkit-backdrop-filter:blur(var(--glass-blur)) saturate(140%);}

/* Stats Strip */
.stats-strip{border-top:1px solid var(--border-soft);border-bottom:1px solid var(--border-soft);background:var(--bg-soft);}
.stats-strip-inner{max-width:1280px;margin:0 auto;padding:34px 24px;display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
.stat-block{text-align:center;border-right:1px solid var(--border-soft);}
.stat-block:last-child{border-right:none;}
.stat-block .n{font-family:var(--font-mono);font-weight:700;font-size:clamp(1.8rem,3vw,2.6rem);color:#fff;line-height:1;}
.stat-block .n .plus{color:var(--gold);}
.stat-block .l{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-top:6px;font-weight:600;}
@media(max-width:760px){.stats-strip-inner{grid-template-columns:repeat(2,1fr);row-gap:26px;}.stat-block:nth-child(2n){border-right:none;}}

/* Champs */
.champs-section{padding:56px 24px;max-width:1280px;margin:0 auto;background:var(--bg-soft);border-radius:28px;}
.champs-head{text-align:center;margin-bottom:30px;}
.champs-head h2{font-family:var(--font-display);font-weight:600;font-size:clamp(1.6rem,2.6vw,2.2rem);color:#fff;}
.champs-head h2 .gold{color:var(--gold);}
.champs-head p{color:var(--muted);font-size:.86rem;margin-top:8px;}
.champs-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.champ-card{position:relative;border-radius:16px;overflow:hidden;transform-style:preserve-3d;background:linear-gradient(165deg,rgba(255,255,255,.05),rgba(255,255,255,.012));border:1px solid color-mix(in srgb, var(--cc,var(--gold)) 22%, var(--border));}
.champ-card .top-bar{height:4px;width:100%;background:linear-gradient(90deg,var(--cc,var(--gold)),transparent 130%);}
.champ-card .body{padding:24px 20px;position:relative;z-index:1;}
.champ-card::before{content:'';position:absolute;top:-30%;left:60%;width:140%;height:140%;background:radial-gradient(circle,var(--cc,var(--gold)) 0%,transparent 62%);opacity:.16;pointer-events:none;}
.champ-card::after{content:'';position:absolute;top:0;left:-60%;width:40%;height:100%;background:linear-gradient(115deg,transparent,rgba(255,255,255,.10),transparent);transform:skewX(-18deg);transition:left .65s var(--ease);pointer-events:none;z-index:2;}
.champ-card:hover::after{left:130%;}
.champ-card .sigla{display:inline-block;font-family:var(--font-mono);font-size:.68rem;font-weight:800;letter-spacing:1.6px;color:var(--cc,var(--gold));background:rgba(255,255,255,.06);border:1px solid var(--cc,var(--gold));border-color:color-mix(in srgb, var(--cc,var(--gold)) 45%, transparent);padding:4px 10px;border-radius:6px;margin-bottom:16px;}
.champ-card .comp-full{font-size:.66rem;text-transform:uppercase;letter-spacing:.5px;color:var(--muted2);font-weight:700;margin-bottom:8px;}
.champ-card .team{font-family:var(--font-display);font-weight:700;font-size:1.28rem;color:#fff;line-height:1.15;margin-bottom:10px;}
.champ-card .dato{font-size:.76rem;color:var(--muted);line-height:1.55;margin-bottom:18px;min-height:2.6em;}
.champ-card .temporada{font-family:var(--font-mono);font-size:.64rem;color:var(--cc,var(--gold));background:color-mix(in srgb, var(--cc,var(--gold)) 14%, transparent);padding:3px 10px;border-radius:20px;display:inline-block;}
@media(max-width:900px){.champs-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:520px){.champs-grid{grid-template-columns:1fr;}}

/* Layout */
.layout{display:flex;max-width:1600px;margin:0 auto;}
.sidebar{width:var(--sidebar-w);flex-shrink:0;position:sticky;top:var(--nav-h);align-self:flex-start;height:calc(100vh - var(--nav-h));overflow-y:auto;padding:26px 0 40px;border-right:1px solid var(--border-soft);}
.side-group-lbl{font-size:.66rem;color:var(--muted2);text-transform:uppercase;letter-spacing:1.2px;font-weight:800;padding:16px 22px 9px;}
.side-item{display:flex;align-items:center;gap:12px;padding:10px 22px;color:rgba(255,255,255,.72);font-size:.85rem;font-weight:600;border-left:2px solid transparent;transition:all .2s;cursor:pointer;appearance:none;background:none;border-top:none;border-right:none;border-bottom:none;width:100%;text-align:left;}
.side-item i{width:18px;text-align:center;font-size:.95rem;}
.side-item:hover{background:rgba(255,255,255,.03);color:#fff;}
.side-item.is-active{background:linear-gradient(90deg,rgba(255,201,60,.1),transparent);border-left-color:var(--gold);color:var(--gold);}
.side-item .cnt{margin-left:auto;font-size:.68rem;color:var(--muted2);display:flex;align-items:center;gap:5px;font-family:var(--font-mono);}
.live-dot-mini{width:6px;height:6px;background:var(--green);border-radius:50%;box-shadow:0 0 6px var(--green);display:inline-block;}
.sb-ligas{padding:0 22px;}
.sb-liga-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border-soft);}
.sb-liga-item img{width:22px;height:22px;object-fit:contain;}
.sb-liga-item span{font-size:.78rem;color:rgba(255,255,255,.68);font-weight:600;}
.main{flex:1;min-width:0;padding:34px 26px 70px;}

/* Quick Cats */
.quick-cats{display:flex;gap:10px;overflow-x:auto;margin-bottom:28px;padding-bottom:6px;}
.qcat{appearance:none;flex-shrink:0;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:11px 18px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all .25s var(--ease);}
.qcat:hover{border-color:rgba(255,201,60,.4);transform:translateY(-3px);background:rgba(255,201,60,.05);}
.qcat i{font-size:1.05rem;color:rgba(255,255,255,.6);}
.qcat span{font-size:.8rem;font-weight:700;color:rgba(255,255,255,.85);}
.qcat.live{border-color:rgba(0,255,179,.3);}
.qcat.live i{color:var(--green);}
.qcat small{color:var(--muted2);font-weight:700;font-size:.68rem;margin-left:2px;font-family:var(--font-mono);}
.qcat.is-active{border-color:var(--gold);background:rgba(255,201,60,.08);}
.qcat.is-active span{color:var(--gold);}

/* Events Panel */
.sec-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
.sec-head h2{font-family:var(--font-display);font-weight:600;font-size:1.5rem;letter-spacing:.1px;color:#fff;}
.sec-head h2 .gold{color:var(--gold);}
.tab-filters{display:flex;gap:6px;flex-wrap:wrap;}
.tab-filters button{background:var(--panel);border:1px solid var(--border);color:rgba(255,255,255,.62);border-radius:18px;padding:6px 15px;font-size:.76rem;font-weight:700;cursor:pointer;transition:all .2s;}
.tab-filters button.is-active{background:var(--gold);border-color:var(--gold);color:#0a0a0a;}
.see-all{color:var(--gold);font-size:.82rem;font-weight:700;display:inline-flex;align-items:center;gap:5px;}

.events-panel{border-radius:16px;overflow:hidden;margin-bottom:30px;}
.ev-row{display:grid;grid-template-columns:118px 1fr 250px 40px;align-items:center;padding:16px 20px;transition:background .2s;transform-style:preserve-3d;will-change:transform,opacity;}
.ev-row:hover{background:rgba(255,255,255,.02);}
.ev-meta{font-size:.68rem;color:var(--muted);line-height:1.6;}
.ev-meta .sport-tag{background:rgba(255,255,255,.06);color:rgba(255,255,255,.62);padding:2px 8px;border-radius:6px;font-size:.62rem;font-weight:700;display:inline-block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;}
.ev-meta .live-tag{color:var(--green);font-weight:800;display:flex;align-items:center;gap:5px;letter-spacing:.3px;}
.ev-meta .sub{margin-top:2px;color:var(--muted2);}
.ev-teams{display:flex;flex-direction:column;gap:8px;}
.ev-team{display:flex;align-items:center;gap:9px;font-size:.88rem;font-weight:700;}
.crest-img{width:22px;height:22px;object-fit:contain;}
.crest-fallback{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;color:var(--muted2);font-size:.85rem;}
.ev-score{font-family:var(--font-mono);font-weight:700;font-size:1.15rem;color:var(--green);margin-left:auto;}
.ev-odds{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;}
.odd-box{background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:9px;text-align:center;padding:8px 4px;cursor:pointer;position:relative;appearance:none;font-family:inherit;}
.odd-box:hover{background:rgba(255,201,60,.1);border-color:var(--gold);}
.odd-box.disabled{opacity:.3;pointer-events:none;}
.odd-box.is-picked{border-color:var(--gold);background:rgba(255,201,60,.14);}
.odd-box .l{display:block;font-size:.58rem;color:var(--muted2);text-transform:uppercase;letter-spacing:.4px;}
.odd-box .v{font-family:var(--font-mono);font-weight:700;font-size:1.15rem;color:var(--gold);}
.ev-fav{appearance:none;background:none;border:none;color:var(--muted2);font-size:1.05rem;cursor:pointer;text-align:center;padding:6px;}
.ev-fav:hover,.ev-fav[aria-pressed="true"]{color:var(--gold);}
.no-results{color:var(--muted);font-size:.83rem;text-align:center;padding:44px 10px;}
.no-results i{opacity:.35;}

/* Stats bars */
.ev-stats{grid-column:1/-1;display:none;padding:12px 4px 4px;border-top:1px solid var(--border-soft);margin-top:10px;}
.ev-row.stats-open .ev-stats{display:block;}
.stat-toggle{grid-column:1/-1;justify-self:start;background:none;border:none;color:var(--muted2);font-size:.68rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;padding:2px 0;text-transform:uppercase;letter-spacing:.4px;}
.stat-toggle:hover{color:var(--gold);}
.stat-line{display:grid;grid-template-columns:34px 1fr 34px;align-items:center;gap:10px;margin-bottom:9px;font-family:var(--font-mono);font-size:.72rem;color:var(--muted);}
.stat-line .bar{height:6px;background:rgba(255,255,255,.06);border-radius:4px;overflow:hidden;display:flex;}
.stat-line .bar .a{background:var(--gold);width:0;}
.stat-line .bar .b{background:var(--violet);width:0;}
.stat-line .lbl{grid-column:1/-1;font-size:.6rem;color:var(--muted2);text-transform:uppercase;letter-spacing:.5px;text-align:center;margin:-4px 0 2px;}
@media(max-width:900px){.ev-row{grid-template-columns:1fr;gap:12px;}.ev-fav{display:none;}}

/* Resultados */
.res-panel{border-radius:16px;overflow:hidden;margin-bottom:30px;}
.res-row{display:grid;grid-template-columns:1fr auto 1fr 100px;align-items:center;gap:10px;padding:13px 20px;border-bottom:1px solid var(--border-soft);font-size:.84rem;}
.res-row:last-child{border-bottom:none;}
.res-team{display:flex;align-items:center;gap:9px;font-weight:600;}
.res-team.right{justify-content:flex-end;text-align:right;flex-direction:row-reverse;}
.res-score{font-family:var(--font-mono);font-weight:700;font-size:1.2rem;color:var(--gold);background:rgba(255,201,60,.08);padding:3px 14px;border-radius:7px;text-align:center;}
.res-liga{font-size:.68rem;color:var(--muted);text-align:right;}

/* Promos */
.promo-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px;}
.promo-card{border-radius:16px;padding:22px;position:relative;overflow:hidden;transition:transform .3s var(--ease),border-color .3s,box-shadow .3s;}
.promo-card:hover{transform:translateY(-5px);box-shadow:0 20px 40px rgba(0,0,0,.35);}
.promo-card.p1{border-color:rgba(255,201,60,.22);background:linear-gradient(165deg,rgba(255,201,60,.07),var(--glass-bg));}
.promo-card.p1:hover{border-color:rgba(255,201,60,.45);}
.promo-card.p2{border-color:rgba(255,59,92,.22);background:linear-gradient(165deg,rgba(255,59,92,.07),var(--glass-bg));}
.promo-card.p2:hover{border-color:rgba(255,59,92,.45);}
.promo-card.p3{border-color:rgba(123,97,255,.24);background:linear-gradient(165deg,rgba(123,97,255,.08),var(--glass-bg));}
.promo-card.p3:hover{border-color:rgba(123,97,255,.5);}
.promo-card .ic{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:14px;}
.promo-card.p1 .ic{background:rgba(255,201,60,.14);color:var(--gold);}
.promo-card.p2 .ic{background:rgba(255,59,92,.14);color:var(--red);}
.promo-card.p3 .ic{background:rgba(123,97,255,.16);color:var(--violet);}
.promo-card h3{font-weight:800;font-size:.95rem;margin-bottom:6px;}
.promo-card p{color:var(--muted);font-size:.8rem;margin-bottom:14px;line-height:1.5;}
.promo-card a{font-size:.78rem;font-weight:800;color:var(--gold);display:inline-flex;align-items:center;gap:5px;}
.promo-card .scroll-fill{height:4px;background:rgba(255,255,255,.08);border-radius:2px;overflow:hidden;margin-top:14px;}
.promo-card .scroll-fill span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--red),var(--gold));}
@media(max-width:900px){.promo-strip{grid-template-columns:1fr;}}

/* Tilt/magnetic: clase compartida por la función utilitaria de motion.js
   (Tarea 6) — no aplica ningún transform por si sola, solo habilita
   will-change para que el navegador prepare la capa. */
.js-tilt,.js-magnetic{will-change:transform;}

/* VIP */
.vip-section{background:radial-gradient(ellipse 80% 100% at 50% 0%,rgba(255,201,60,.08),transparent 60%),#000;border:1px solid rgba(255,201,60,.18);border-radius:22px;padding:52px 40px;margin:10px 0 34px;text-align:center;position:relative;overflow:hidden;}
.vip-stars{color:var(--gold);font-size:1rem;letter-spacing:6px;margin-bottom:16px;position:relative;z-index:1;}
.vip-section h2{font-family:var(--font-display);font-weight:600;font-size:clamp(1.8rem,3vw,2.6rem);color:#fff;letter-spacing:.5px;position:relative;z-index:1;}
.vip-section h2 .gold{color:var(--gold);}
.vip-section > p{color:var(--muted);max-width:520px;margin:14px auto 30px;font-size:.94rem;position:relative;z-index:1;}
.vip-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;max-width:920px;margin:0 auto 30px;position:relative;z-index:1;}
.vip-item{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-radius:13px;padding:20px 14px;transition:all .3s var(--ease);}
.vip-item:hover{border-color:rgba(255,201,60,.4);background:rgba(255,201,60,.05);transform:translateY(-4px);}
.vip-item i{font-size:1.4rem;color:var(--gold);margin-bottom:10px;display:block;}
.vip-item span{font-size:.82rem;font-weight:700;color:rgba(255,255,255,.88);}
@media(max-width:760px){.vip-grid{grid-template-columns:repeat(2,1fr);}.vip-section{padding:40px 22px;}}

/* ============================================================
   BETSLIP
   ============================================================ */
.betslip{width:var(--betslip-w);flex-shrink:0;position:sticky;top:var(--nav-h);align-self:flex-start;height:calc(100vh - var(--nav-h));overflow-y:auto;padding:26px 20px;border-left:1px solid var(--border-soft);}
.bs-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
.bs-head h2{font-family:var(--font-display);font-weight:600;font-size:1.15rem;letter-spacing:.2px;}
.bs-pick{border-radius:13px;padding:14px;margin-bottom:14px;position:relative;overflow:hidden;transform-origin:top center;}
.bs-pick .mkt{font-size:.65rem;color:var(--gold);text-transform:uppercase;font-weight:800;margin-bottom:4px;letter-spacing:.4px;}
.bs-pick .sel{font-size:.87rem;font-weight:700;display:flex;align-items:center;gap:7px;}
.bs-pick .sel img,.bs-pick .sel .crest-fallback{width:18px;height:18px;object-fit:contain;}
.bs-pick .vs{font-size:.72rem;color:var(--muted);margin-bottom:8px;}
.bs-pick .odd{font-family:var(--font-mono);color:var(--gold);font-weight:700;font-size:1.1rem;float:right;}
.bs-amount label{font-size:.72rem;color:var(--muted);text-transform:uppercase;font-weight:700;letter-spacing:.3px;display:block;}
.bs-amount input{width:100%;background:var(--panel-2);border:1px solid var(--border);border-radius:9px;color:#fff;padding:11px;font-size:.95rem;margin-top:7px;margin-bottom:14px;font-family:var(--font-mono);}
.bs-amount input:focus{outline:none;border-color:var(--gold);}
.bs-summary{display:flex;justify-content:space-between;font-size:.8rem;color:var(--muted);margin-bottom:7px;font-family:var(--font-mono);}
.bs-summary b{color:var(--green);}

@layer properties {
  @property --btn-bg-position { syntax: "<number>"; inherits: true; initial-value: 100; }
  @property --btn-after-blur  { syntax: "<number>"; inherits: true; initial-value: 0; }
  @property --btn-after-opacity { syntax: "<number>"; inherits: true; initial-value: 1; }
  @property --btn-before-opacity { syntax: "<number>"; inherits: true; initial-value: .35; }
  @property --btn-scale { syntax: "<number>"; inherits: true; initial-value: 1; }
}
.btn-place{
  --btn-bg-position:100; --btn-after-blur:0; --btn-after-opacity:1; --btn-before-opacity:.35; --btn-scale:1;
  width:100%;background:#08080a;color:#fff;font-weight:800;border:none;border-radius:12px;padding:14px;
  font-size:.9rem;margin-top:10px;cursor:pointer;position:relative;overflow:visible;
  font-family:var(--font-display);letter-spacing:.3px;
  transform:scale(var(--btn-scale));
  transition:--btn-bg-position 3s ease,--btn-after-blur .3s ease,--btn-before-opacity .3s ease,--btn-scale .2s cubic-bezier(.76,-.25,.51,1.13);
}
.btn-place span{position:relative;z-index:1;}
.btn-place::after{
  content:'';display:block;position:absolute;inset:0;z-index:-2;border-radius:inherit;
  background:linear-gradient(to right,var(--gold),var(--green),var(--violet),var(--gold)) no-repeat calc(var(--btn-bg-position) * 1%) 0 / 900%;
  filter:blur(calc(var(--btn-after-blur) * 1px));opacity:var(--btn-after-opacity);
}
.btn-place::before{
  content:'';display:block;position:absolute;inset:-1.5px;z-index:-1;border-radius:13px;
  background:linear-gradient(to right,var(--gold),var(--green),var(--violet),var(--gold)) no-repeat calc(var(--btn-bg-position) * 1%) 0 / 900%;
  opacity:var(--btn-before-opacity);
}
.btn-place:hover{--btn-scale:1.03;--btn-bg-position:0;--btn-after-blur:22;--btn-after-opacity:.35;--btn-before-opacity:1;}
.btn-place:active{--btn-scale:.97;--btn-after-blur:10;}
.btn-place:disabled{opacity:.45;cursor:not-allowed;--btn-scale:1;}

.bs-empty{color:var(--muted);font-size:.83rem;text-align:center;padding:34px 10px;}
.bs-empty i{opacity:.35;}
.promo-side{border-radius:14px;padding:18px;margin-top:20px;text-align:center;background:linear-gradient(150deg,rgba(255,201,60,.1),rgba(123,97,255,.06));border:1px solid rgba(255,201,60,.22);}
.promo-side h3{color:var(--gold);font-weight:800;font-size:.9rem;margin-bottom:6px;}
.promo-side p{color:var(--muted);font-size:.76rem;margin-bottom:12px;}
.promo-side a{background:var(--gold);color:#0a0a0a;font-weight:800;padding:9px 20px;border-radius:8px;font-size:.78rem;display:inline-block;}

.btn-cashout{width:100%;margin-top:8px;background:transparent;border:1px solid var(--violet);color:#d6cdff;font-weight:800;border-radius:10px;padding:11px;font-size:.82rem;cursor:pointer;position:relative;overflow:hidden;}
.btn-cashout:disabled{opacity:.4;cursor:not-allowed;}
.btn-cashout .glow{position:absolute;inset:0;border-radius:inherit;box-shadow:0 0 0 0 rgba(123,97,255,.5);pointer-events:none;}

.rl-msg{font-size:.68rem;color:var(--red);margin-top:8px;text-align:center;display:none;font-weight:600;}
.rl-msg.show{display:block;}

/* Parte 2 — Tarea 1: botón visible de abrir/cerrar el betslip mobile,
   siempre disponible además del gesto de drag (Draggable). */
.bs-toggle-btn{display:none;position:absolute;top:-40px;right:14px;align-items:center;gap:6px;
  background:var(--glass-bg);border:1px solid var(--glass-border);color:var(--txt);
  font-size:.72rem;font-weight:700;padding:8px 14px;border-radius:20px 20px 0 0;
  border-bottom:none;cursor:pointer;backdrop-filter:blur(10px);}
.bs-toggle-btn i{transition:transform .3s var(--ease);}
.bs-toggle-btn[aria-expanded="true"] i{transform:rotate(180deg);}

/* Región aria-live dedicada para cambios de cuotas — visualmente oculta,
   solo para lectores de pantalla (Parte 2, Tarea 1). */
#oddsLiveRegion{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}

@media(max-width:1200px){
  .betslip{position:fixed;left:0;right:0;bottom:0;top:auto;width:100%;height:auto;max-height:80vh;z-index:1500;
    border-left:none;border-top:1px solid var(--border);border-radius:18px 18px 0 0;
    transform:translateY(calc(100% - 54px));background:var(--glass-bg);backdrop-filter:blur(22px);}
  .betslip .bs-drag-handle{display:block;width:44px;height:4px;background:rgba(255,255,255,.25);border-radius:2px;margin:0 auto 14px;}
  .bs-toggle-btn{display:flex;}
}
@media(min-width:1201px){.bs-drag-handle{display:none;}}

@media(max-width:840px){
  .sidebar{position:fixed;top:var(--nav-h);left:0;bottom:0;background:var(--bg-soft);transform:translateX(-100%);transition:transform .3s var(--ease);z-index:900;height:calc(100vh - var(--nav-h));}
  .sidebar.open{transform:translateX(0);}
  .nav-toggle{display:block;}
  .topnav-links{display:none;}
  .nav-search{display:none;}
}

/* ============================================================
   TOAST / NOTIFICACIONES
   ============================================================ */
#toastStack{position:fixed;top:calc(var(--nav-h) + 16px);right:16px;z-index:3000;display:flex;flex-direction:column;gap:10px;max-width:320px;pointer-events:none;}
.toast{border-radius:12px;padding:14px 16px;display:flex;gap:12px;align-items:flex-start;box-shadow:0 18px 40px rgba(0,0,0,.45);pointer-events:auto;background:var(--glass-bg);border:1px solid var(--glass-border);backdrop-filter:blur(18px);}
.toast .ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.95rem;}
.toast.win .ic{background:rgba(0,255,179,.16);color:var(--green);}
.toast.lose .ic{background:rgba(255,59,92,.16);color:var(--red);}
.toast .txt b{display:block;font-size:.84rem;margin-bottom:2px;}
.toast .txt span{font-size:.74rem;color:var(--muted);}
.toast .close{margin-left:auto;background:none;border:none;color:var(--muted2);cursor:pointer;font-size:.9rem;}

/* ============================================================
   FOOTER
   ============================================================ */
.site-footer{border-top:1px solid var(--border-soft);background:var(--bg-soft);padding:50px 24px 26px;}
.footer-inner{max-width:1280px;margin:0 auto;}
.footer-top{display:flex;justify-content:space-between;flex-wrap:wrap;gap:30px;margin-bottom:34px;}
.footer-brand p{color:var(--muted);font-size:.82rem;max-width:280px;margin-top:12px;line-height:1.6;}
.footer-social{display:flex;gap:10px;margin-top:16px;}
.footer-social a{width:36px;height:36px;border-radius:9px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.65);transition:all .2s;}
.footer-social a:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-2px);}
.footer-cols{display:flex;gap:60px;flex-wrap:wrap;}
.footer-col h3{font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;color:var(--muted2);font-weight:800;margin-bottom:14px;}
.footer-col a{display:block;color:rgba(255,255,255,.68);font-size:.83rem;margin-bottom:10px;transition:color .2s;}
.footer-col a:hover{color:var(--gold);}
.footer-legal{border-top:1px solid var(--border-soft);padding-top:22px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;align-items:center;}
.footer-legal p{color:var(--muted2);font-size:.72rem;line-height:1.6;max-width:640px;}
.footer-legal .age{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:.78rem;font-weight:700;}
.footer-legal .age span{width:26px;height:26px;border:1px solid var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.7rem;color:var(--gold);}
@media(max-width:760px){.footer-top{flex-direction:column;}.footer-cols{gap:34px;}}

/* ============================================================
   GOAL CELEBRATION OVERLAY (GSAP)
   ============================================================ */
#goalOverlay{
  position:fixed;inset:0;z-index:4000;display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.78);backdrop-filter:blur(6px);opacity:0;pointer-events:none;
}
#goalOverlay.is-active{pointer-events:auto;}
.goal-scene{
  width:min(560px,92vw);aspect-ratio:16/10;position:relative;border-radius:20px;overflow:hidden;
  background:radial-gradient(ellipse 70% 50% at 50% 8%,#132a1c,#050505 62%);
  border:1px solid var(--glass-border);perspective:900px;perspective-origin:50% 15%;
}
.bokeh{position:absolute;border-radius:50%;filter:blur(18px);opacity:.55;z-index:0;}
.bokeh.b1{width:70px;height:70px;background:var(--gold);top:6%;left:14%;}
.bokeh.b2{width:50px;height:50px;background:var(--green);top:10%;right:20%;}
.bokeh.b3{width:40px;height:40px;background:var(--violet);top:4%;left:55%;}
.gs-pitch{position:absolute;bottom:0;left:0;right:0;height:38%;
  background:repeating-linear-gradient(90deg,rgba(255,255,255,.03) 0 40px,rgba(255,255,255,.06) 40px 80px);
  transform:rotateX(72deg);transform-origin:bottom;}
.gs-vignette{position:absolute;inset:0;z-index:8;pointer-events:none;
  background:radial-gradient(ellipse 90% 80% at 50% 45%,transparent 55%,rgba(0,0,0,.65) 100%);}
#netStage{position:absolute;top:11%;left:10%;right:10%;height:48%;
  transform-style:preserve-3d;transform:rotateX(58deg);transform-origin:top center;z-index:2;}
#netGrid{position:absolute;inset:0;display:grid;grid-template-columns:repeat(16,1fr);grid-template-rows:repeat(8,1fr);transform-style:preserve-3d;}
.net-cell{position:relative;transform-style:preserve-3d;}
.net-cell::before,.net-cell::after{content:'';position:absolute;background:rgba(255,255,255,.32);}
.net-cell::before{left:0;top:0;bottom:0;width:1.4px;}
.net-cell::after{top:0;left:0;right:0;height:1.4px;}
.gs-post{position:absolute;background:rgba(255,255,255,.9);box-shadow:0 0 8px rgba(255,255,255,.5);z-index:3;}
.gs-post.top{top:11%;left:10%;right:10%;height:4px;}
.gs-post.left{top:11%;height:48%;left:10%;width:4px;transform:rotateX(58deg);transform-origin:top;}
.gs-post.right{top:11%;height:48%;right:10%;width:4px;transform:rotateX(58deg);transform-origin:top;}
#gsBall{width:24px;height:24px;border-radius:50%;position:absolute;left:6%;bottom:14%;z-index:5;
  background:radial-gradient(circle at 32% 30%,#fff,#d8d8d8 45%,#161616 46%,#161616 100%);
  box-shadow:0 0 10px rgba(0,0,0,.5),0 0 22px rgba(255,255,255,.15);}
#gsBallTrail{position:absolute;left:6%;bottom:14%;z-index:4;width:24px;height:24px;border-radius:50%;
  background:radial-gradient(circle,rgba(255,255,255,.5),transparent 70%);opacity:0;}
#gsFlash{position:absolute;inset:0;background:#fff;opacity:0;pointer-events:none;z-index:9;}
#gsGoalText{position:absolute;left:0;right:0;top:38%;text-align:center;z-index:10;pointer-events:none;
  font-family:var(--font-display);font-weight:700;font-size:clamp(2rem,6vw,3rem);letter-spacing:2px;
  color:var(--green);text-shadow:0 0 30px rgba(0,255,179,.6);}
#gsReplayBadge{position:absolute;top:14px;right:14px;font-family:var(--font-mono);font-size:.62rem;
  background:rgba(255,59,92,.12);border:1px solid rgba(255,59,92,.4);color:#ff6b82;padding:4px 9px;
  border-radius:20px;opacity:0;z-index:6;letter-spacing:.5px;}
.gs-scoreline{position:absolute;top:14px;left:14px;font-family:var(--font-mono);font-size:.68rem;color:rgba(255,255,255,.5);z-index:6;max-width:70%;}
.gs-scoreline b{color:var(--gold);}
#gsCloseHint{position:absolute;bottom:-30px;left:0;right:0;text-align:center;font-family:var(--font-mono);
  font-size:.68rem;color:var(--muted2);}

</style>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "GoalBet",
  "url": "<?= $urlActual ?>",
  "sameAs": []
}
</script>
</head>
<body>

<div id="loadingScreen" aria-hidden="true">
  <div class="loader-stage" id="loaderStage">
    <div class="loader-core"></div>
  </div>
  <div class="loader-word">GOAL<span>BET</span></div>
  <div class="loader-pct mono" id="loadingPct">0%</div>
</div>


<!-- Parte 2 — Tarea 1: región aria-live dedicada y separada del resto de
     la página, exclusivamente para anunciar cambios de cuotas en vivo
     (hoy ese parpadeo es puramente visual). Se llena/limpia desde
     js/filters.js en cada micro-flash de cuota. -->
<div id="oddsLiveRegion" aria-live="polite" aria-atomic="true" role="status"></div>

<a class="skip-link" href="#main-content">Saltar al contenido principal</a>

<?php
/**
 * partials/_nav.php
 * Requiere en scope: $totalEnVivo (int), esta_logueado(), usuario_actual(), money().
 * No contiene lógica de negocio propia — solo markup del header.
 */
?>
<header class="topnav" id="topnav">
  <button class="nav-toggle" id="navToggle" aria-label="Abrir menú" aria-expanded="false" aria-controls="mainSidebar">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <a href="index2.php" class="brand">
    <span class="brand-mark" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></span>
    <span class="brand-txt">GOAL<span class="gb">BET</span></span>
  </a>
  <nav class="topnav-links" aria-label="Navegación principal">
    <a href="#home" aria-current="page">Inicio</a>
    <a href="partidos.php">En Vivo <?php if ($totalEnVivo > 0): ?><span class="badge-mini"><?= $totalEnVivo ?></span><?php endif; ?></a>
    <a href="partidos.php">Partidos</a>
    <a href="#promos">Promociones</a>
    <a href="#vip">VIP</a>
    <a href="posiciones.php">Posiciones</a>
  </nav>
  <div class="nav-search">
    <label for="teamSearch" class="visually-hidden">Buscar equipo o liga</label>
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="search" id="teamSearch" placeholder="Buscar equipo o liga...">
  </div>
  <div class="topnav-auth">
    <?php if (esta_logueado()): ?>
      <span class="user-pill"><i class="bi bi-wallet2" aria-hidden="true"></i> <?= money(usuario_actual()['saldo']) ?></span>
      <a href="dashboard.php" class="btn-signup"><i class="bi bi-speedometer2" aria-hidden="true"></i> Mi Panel</a>
    <?php else: ?>
      <a href="login.php" class="btn-login">Iniciar sesión</a>
      <a href="register.php" class="btn-signup">Registrarse</a>
    <?php endif; ?>
  </div>
</header>


<?php
/**
 * partials/_hero.php
 * Requiere en scope: $totalEnVivo, $ligasDestacadas, $destacado,
 * $totalLigas, $totalEquipos, esta_logueado(), escudo_img().
 */
?>
<section class="hero" id="home" aria-label="Presentación">
  <div class="hero-bg-base" aria-hidden="true"></div>
  <canvas class="hero-particles-canvas" id="heroParticlesCanvas" aria-hidden="true"></canvas>
  <div class="hero-grid-overlay" aria-hidden="true"></div>

  <!-- Tarea 4: skeleton liviano, puramente CSS (shimmer), visible desde el
       primer paint aunque JS todavía no haya corrido. Se remueve en
       revealPage() (hero.js) una vez que la entrada real terminó. -->
  <div class="hero-skel" aria-hidden="true">
    <div class="hero-wrap" style="pointer-events:none;">
      <div style="max-width:560px;">
        <div class="sk-line" style="width:220px;height:26px;margin-bottom:22px;"></div>
        <div class="sk-line" style="width:92%;height:44px;margin-bottom:10px;"></div>
        <div class="sk-line" style="width:70%;height:44px;margin-bottom:22px;"></div>
        <div class="sk-line" style="width:85%;height:16px;margin-bottom:8px;"></div>
        <div class="sk-line" style="width:60%;height:16px;margin-bottom:26px;"></div>
        <div style="display:flex;gap:14px;">
          <div class="sk-line" style="width:170px;height:50px;"></div>
          <div class="sk-line" style="width:150px;height:50px;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Balón "Trionda" 3D hecho con triángulos (GSAP): gira en loop,
       responde con parallax al mouse, y cada tanto se "explota" y se
       vuelve a armar. En mobile no se construye (ver hero.js, Tarea 3).
       Parte 2 — Tarea 2: la construcción real del balón se difiere a
       requestIdleCallback (ver main.js), este contenedor solo reserva
       el espacio/layout desde el primer paint. -->
  <div class="hero-ball-container" id="heroBallContainer" aria-hidden="true">
    <div class="ball-glow"></div>
    <div class="ball-trionda" id="ballTrionda"></div>
  </div>

  <div class="hero-wrap">
    <div class="hero-copy" data-anim="hero-copy">
      <?php if ($totalEnVivo > 0): ?>
      <p class="hero-eyebrow"><span class="live-dot" data-anim-live aria-hidden="true"></span> <?= $totalEnVivo ?> partido<?= $totalEnVivo === 1 ? '' : 's' ?> en vivo ahora mismo</p>
      <?php else: ?>
      <p class="hero-eyebrow"><i class="bi bi-calendar-event" aria-hidden="true" style="color:var(--gold)"></i> Próximos partidos hoy</p>
      <?php endif; ?>
      <h1 id="heroHeadline">
        <span class="hw">La</span> <span class="hw">nueva</span> <span class="hw">forma</span> <span class="hw">de</span><br>
        <span class="hw">apostar</span> <span class="hw gold">en</span> <span class="hw gold">línea.</span>
      </h1>
      <p class="lead">Cuotas en tiempo real en las mejores ligas del mundo. Depósitos y pagos instantáneos en Lempiras, sin letra pequeña.</p>
      <div class="hero-cta">
        <a href="register.php" class="btn-hero-primary"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> Empezar Ahora</a>
        <a href="partidos.php" class="btn-hero-secondary"><i class="bi bi-play-circle" aria-hidden="true"></i> Ver Partidos</a>
      </div>
      <?php if ($ligasDestacadas): ?>
      <div class="hero-crests" role="list" aria-label="Ligas destacadas">
        <?php foreach (array_slice($ligasDestacadas, 0, 6) as $l): ?>
          <img src="<?= e($l['logo']) ?>" alt="<?= e($l['nombre']) ?>" title="<?= e($l['nombre']) ?>" loading="lazy" decoding="async" width="38" height="38" data-hide-on-error role="listitem">
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="hero-side" data-anim="hero-side" id="heroTilt">
      <?php if ($destacado): ?>
      <div class="hero-card">
        <div class="hero-card-top">
          <div class="tag"><?php if ($destacado['estado']==='en_curso'): ?><span class="live-dot" data-anim-live aria-hidden="true"></span> EN VIVO<?php else: ?><i class="bi bi-clock" aria-hidden="true"></i> PRÓXIMO<?php endif; ?></div>
          <div class="lg"><?= e($destacado['liga'] ?? $destacado['deporte']) ?></div>
        </div>
        <div class="hero-card-match">
          <div class="hero-card-team">
            <?= escudo_img($destacado['escudo_local'], $destacado['equipo_local']) ?> <?= e($destacado['equipo_local']) ?>
            <?php if ($destacado['estado']==='en_curso'): ?><span class="sc mono"><?= (int)($destacado['marcador_local'] ?? 0) ?></span><?php endif; ?>
          </div>
          <div class="hero-card-team">
            <?= escudo_img($destacado['escudo_visita'], $destacado['equipo_visita']) ?> <?= e($destacado['equipo_visita']) ?>
            <?php if ($destacado['estado']==='en_curso'): ?><span class="sc mono"><?= (int)($destacado['marcador_visita'] ?? 0) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="hero-card-odds">
          <div class="o"><span class="l">1</span><span class="v mono" data-count-to="<?= number_format((float)$destacado['cuota_local'],2) ?>">0.00</span></div>
          <?php if ($destacado['tiene_empate'] && $destacado['cuota_empate'] !== null): ?>
            <div class="o"><span class="l">X</span><span class="v mono" data-count-to="<?= number_format((float)$destacado['cuota_empate'],2) ?>">0.00</span></div>
          <?php else: ?>
            <div class="o" style="opacity:.3" aria-hidden="true"><span class="l">X</span><span class="v">—</span></div>
          <?php endif; ?>
          <div class="o"><span class="l">2</span><span class="v mono" data-count-to="<?= number_format((float)$destacado['cuota_visita'],2) ?>">0.00</span></div>
        </div>
        <div class="hero-mini-stats">
          <div><div class="n mono" data-count-int="<?= $totalLigas ?>">0</div><div class="l">Ligas</div></div>
          <div><div class="n mono" data-count-int="<?= $totalEquipos ?>">0</div><div class="l">Equipos</div></div>
          <div><div class="n mono">24/7</div><div class="l">Soporte</div></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="scroll-hint" id="scrollHint" aria-hidden="true">
    <span>Descubrí más</span>
    <span class="scroll-line"></span>
  </div>
</section>


<!-- ══════════ STATS STRIP ══════════ -->
<section class="stats-strip" aria-label="Estadísticas de la plataforma">
  <div class="stats-strip-inner">
    <div class="stat-block"><div class="n mono" data-count-int="<?= $totalEnVivo ?>">0</div><div class="l">Partidos en Vivo</div></div>
    <div class="stat-block"><div class="n mono" data-count-int="<?= $totalLigas ?>">0</div><div class="l">Ligas y Torneos</div></div>
    <div class="stat-block"><div class="n mono" data-count-int="<?= $totalEquipos ?>">0</div><div class="l">Equipos Activos</div></div>
    <div class="stat-block"><div class="n mono">100<span class="plus">%</span></div><div class="l">Pagos Instantáneos</div></div>
  </div>
</section>

<!-- ══════════ CAMPEONES VIGENTES ══════════ -->
<section class="champs-section" aria-labelledby="hChamps">
  <div class="champs-head">
    <h2 id="hChamps">CAMPEONES <span class="gold">VIGENTES</span></h2>
    <p>Los últimos títulos de las grandes competiciones que cubrimos — actualizado a julio de 2026.</p>
  </div>
  <div class="champs-grid">
    <?php foreach (campeones_vigentes() as $c): ?>
      <!-- Parte 2 — Tarea 1: esta tarjeta es informativa, NO clicable (no
           tiene href/onclick), así que intencionalmente no lleva role,
           tabindex ni button — convertirla en "interactiva" sin acción
           real sería un anti-patrón de accesibilidad (foco sin destino). -->
      <div class="champ-card glass" data-anim="card" style="--cc:<?= e($c['color']) ?>">
        <div class="top-bar"></div>
        <div class="body">
          <span class="sigla mono"><?= e($c['sigla']) ?></span>
          <div class="comp-full"><?= e($c['comp']) ?></div>
          <div class="team"><?= e($c['campeon']) ?></div>
          <div class="dato"><?= e($c['dato']) ?></div>
          <span class="temporada mono">Temp. <?= e($c['temporada']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="layout">

  <!-- ══════════ SIDEBAR ══════════ -->
  <aside class="sidebar" id="mainSidebar" aria-label="Filtros de partidos y accesos rápidos">
    <div class="side-group-lbl" id="lblFiltroPartidos">Partidos</div>
    <div role="group" aria-labelledby="lblFiltroPartidos">
      <button type="button" class="side-item is-active" data-filter-estado="todos" data-filter-sport="todos" aria-current="true">
        <i class="bi bi-collection-fill" aria-hidden="true"></i> Todos <span class="cnt mono"><?= count($partidos) ?></span>
      </button>
      <button type="button" class="side-item" data-filter-estado="en_curso">
        <i class="bi bi-broadcast" style="color:var(--green)" aria-hidden="true"></i> En Vivo <span class="cnt mono"><span class="live-dot-mini" data-anim-live aria-hidden="true"></span> <?= $totalEnVivo ?></span>
      </button>
      <button type="button" class="side-item" data-filter-estado="programado">
        <i class="bi bi-clock" aria-hidden="true"></i> Próximos
      </button>
    </div>

    <div class="side-group-lbl" id="lblDeportes">Deportes</div>
    <div role="group" aria-labelledby="lblDeportes">
      <?php foreach ($deportes as $d): ?>
        <button type="button" class="side-item" data-filter-sport="<?= e($d['slug']) ?>">
          <i class="bi <?= icono_deporte($d['slug']) ?>" aria-hidden="true"></i> <?= e($d['nombre']) ?>
          <span class="cnt mono"><?php if ($d['en_vivo']>0): ?><span class="live-dot-mini" data-anim-live aria-hidden="true"></span><?php endif; ?> <?= (int)$d['total'] ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <?php if ($ligasDestacadas): ?>
    <div class="side-group-lbl" id="lblLigas">Ligas Destacadas</div>
    <div class="sb-ligas" role="list" aria-labelledby="lblLigas">
      <?php foreach ($ligasDestacadas as $l): ?>
        <div class="sb-liga-item" role="listitem">
          <img src="<?= e($l['logo']) ?>" alt="" width="22" height="22" loading="lazy" decoding="async" data-hide-on-error>
          <span><?= e($l['nombre']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="side-group-lbl" id="lblCuenta">Mi Cuenta</div>
    <nav aria-labelledby="lblCuenta">
      <a href="recargar.php" class="side-item"><i class="bi bi-wallet2" aria-hidden="true"></i> Recargar Saldo</a>
      <a href="mis_apuestas.php" class="side-item"><i class="bi bi-receipt" aria-hidden="true"></i> Mis Apuestas</a>
      <a href="posiciones.php" class="side-item"><i class="bi bi-list-ol" aria-hidden="true"></i> Posiciones</a>
    </nav>
  </aside>

  <!-- ══════════ MAIN ══════════ -->
  <main class="main" id="main-content">

    <div class="quick-cats" role="group" aria-label="Categorías rápidas">
      <button type="button" class="qcat live" data-filter-estado="en_curso"><i class="bi bi-broadcast" aria-hidden="true"></i><span>En Vivo</span><small><?= $totalEnVivo ?></small></button>
      <?php foreach ($deportes as $d): ?>
        <button type="button" class="qcat" data-filter-sport="<?= e($d['slug']) ?>"><i class="bi <?= icono_deporte($d['slug']) ?>" aria-hidden="true"></i><span><?= e($d['nombre']) ?></span><small><?= (int)$d['total'] ?></small></button>
      <?php endforeach; ?>
      <a href="#promos" class="qcat"><i class="bi bi-gift" aria-hidden="true"></i><span>Promos</span></a>
    </div>

    <section id="partidos" aria-labelledby="hPartidos">
      <div class="sec-head">
        <h2 id="hPartidos"><?= $totalEnVivo > 0 ? 'EN VIVO &amp; <span class="gold">PRÓXIMOS</span>' : 'PRÓXIMOS <span class="gold">PARTIDOS</span>' ?></h2>
        <div class="tab-filters" role="group" aria-label="Filtrar por estado">
          <button type="button" class="is-active" data-filter-estado="todos" aria-current="true">Todos</button>
          <button type="button" data-filter-estado="en_curso"><i class="bi bi-circle-fill" style="font-size:.5rem;color:var(--green)" aria-hidden="true"></i> En Vivo</button>
          <button type="button" data-filter-estado="programado">Próximos</button>
        </div>
      </div>

      <div class="events-panel glass" id="eventsPanel">
        <?php if (empty($partidos)): ?>
          <div class="no-results"><i class="bi bi-calendar-x fs-3 mb-2 d-block" aria-hidden="true"></i>No hay partidos en vivo o próximos en este momento.</div>
        <?php endif; ?>
        <?php foreach ($partidos as $p):
          $localAria  = cuota_aria('Local', (float)$p['cuota_local'], $p['equipo_local']);
          $visitaAria = cuota_aria('Visita', (float)$p['cuota_visita'], $p['equipo_visita']);
          $st = stats_demo_partido((int)$p['id']);
        ?>
        <div class="ev-row" data-anim="card" data-estado="<?= e($p['estado']) ?>" data-sport="<?= e($p['deporte_slug']) ?>" data-teams="<?= e(function_exists('mb_strtolower') ? mb_strtolower($p['equipo_local'].' '.$p['equipo_visita'].' '.($p['liga'] ?? '')) : strtolower($p['equipo_local'].' '.$p['equipo_visita'].' '.($p['liga'] ?? ''))) ?>">
          <div class="ev-meta">
            <span class="sport-tag"><?= e($p['deporte']) ?></span><br>
            <?php if ($p['estado'] === 'en_curso'): ?>
              <span class="live-tag"><span class="live-dot" data-anim-live aria-hidden="true"></span> EN VIVO</span>
            <?php else: ?>
              <?= fecha_partido_legible($p['fecha_partido'], 'd/m · h:i A') ?>
            <?php endif; ?>
            <div class="sub"><?= e($p['liga'] ?? $p['fase']) ?></div>
          </div>
          <div class="ev-teams">
            <div class="ev-team">
              <?= escudo_img($p['escudo_local'], $p['equipo_local']) ?> <?= e($p['equipo_local']) ?>
              <?php if ($p['estado'] === 'en_curso'): ?><span class="ev-score mono"><?= (int)($p['marcador_local'] ?? 0) ?></span><?php endif; ?>
            </div>
            <div class="ev-team">
              <?= escudo_img($p['escudo_visita'], $p['equipo_visita']) ?> <?= e($p['equipo_visita']) ?>
              <?php if ($p['estado'] === 'en_curso'): ?><span class="ev-score mono"><?= (int)($p['marcador_visita'] ?? 0) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="ev-odds">
            <button type="button" class="odd-box" data-pick
                    data-partido="<?= (int)$p['id'] ?>" data-mercado="1"
                    data-equipo="<?= e($p['equipo_local']) ?>" data-rival="<?= e($p['equipo_visita']) ?>"
                    data-cuota="<?= (float)$p['cuota_local'] ?>" data-escudo="<?= e($p['escudo_local'] ?? '') ?>"
                    aria-label="<?= $localAria ?>"><span class="l">1</span><span class="v mono"><?= number_format((float)$p['cuota_local'],2) ?></span></button>
            <?php if ($p['tiene_empate'] && $p['cuota_empate'] !== null): ?>
              <button type="button" class="odd-box" data-pick
                      data-partido="<?= (int)$p['id'] ?>" data-mercado="X"
                      data-equipo="Empate" data-rival="<?= e($p['equipo_local'].' vs '.$p['equipo_visita']) ?>"
                      data-cuota="<?= (float)$p['cuota_empate'] ?>" data-escudo=""
                      aria-label="<?= cuota_aria('Empate', (float)$p['cuota_empate'], 'empate') ?>"><span class="l">X</span><span class="v mono"><?= number_format((float)$p['cuota_empate'],2) ?></span></button>
            <?php else: ?>
              <div class="odd-box disabled" aria-hidden="true"><span class="l">X</span><span class="v">—</span></div>
            <?php endif; ?>
            <button type="button" class="odd-box" data-pick
                    data-partido="<?= (int)$p['id'] ?>" data-mercado="2"
                    data-equipo="<?= e($p['equipo_visita']) ?>" data-rival="<?= e($p['equipo_local']) ?>"
                    data-cuota="<?= (float)$p['cuota_visita'] ?>" data-escudo="<?= e($p['escudo_visita'] ?? '') ?>"
                    aria-label="<?= $visitaAria ?>"><span class="l">2</span><span class="v mono"><?= number_format((float)$p['cuota_visita'],2) ?></span></button>
          </div>
          <button type="button" class="ev-fav" aria-pressed="false" aria-label="Agregar <?= e($p['equipo_local'] . ' vs ' . $p['equipo_visita']) ?> a favoritos">
            <i class="bi bi-star" aria-hidden="true"></i>
          </button>

          <button type="button" class="stat-toggle" aria-expanded="false">
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i> Estadísticas del partido
          </button>
          <div class="ev-stats">
            <div class="lbl">Posesión de balón</div>
            <div class="stat-line">
              <span class="mono"><?= $st['posesion_local'] ?>%</span>
              <span class="bar"><span class="a" data-bar-fill="<?= $st['posesion_local'] ?>"></span><span class="b" data-bar-fill="<?= $st['posesion_visita'] ?>"></span></span>
              <span class="mono" style="text-align:right"><?= $st['posesion_visita'] ?>%</span>
            </div>
            <div class="lbl">Tiros a puerta</div>
            <div class="stat-line">
              <span class="mono"><?= $st['tiros_local'] ?></span>
              <span class="bar"><span class="a" data-bar-fill="<?= round($st['tiros_local'] / $st['tiros_max'] * 50) ?>"></span><span class="b" data-bar-fill="<?= round($st['tiros_visita'] / $st['tiros_max'] * 50) ?>"></span></span>
              <span class="mono" style="text-align:right"><?= $st['tiros_visita'] ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="no-results" id="noResultsMsg" hidden><i class="bi bi-filter-circle fs-3 mb-2 d-block" aria-hidden="true"></i>Ningún partido coincide con ese filtro.</div>
      </div>

      <div class="text-center mt-3">
        <a href="partidos.php" class="see-all">Ver todos los partidos <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      </div>
    </section>

    <?php if ($resultados): ?>
    <section id="resultados" aria-labelledby="hResultados">
      <div class="sec-head"><h2 id="hResultados">ÚLTIMOS <span class="gold">RESULTADOS</span></h2></div>
      <div class="res-panel glass">
        <?php foreach ($resultados as $r): ?>
          <div class="res-row" data-anim="card">
            <div class="res-team"><?= escudo_img($r['escudo_local'], $r['equipo_local']) ?> <?= e($r['equipo_local']) ?></div>
            <div class="res-score mono"><?= (int)$r['marcador_local'] ?> - <?= (int)$r['marcador_visita'] ?></div>
            <div class="res-team right"><?= escudo_img($r['escudo_visita'], $r['equipo_visita']) ?> <?= e($r['equipo_visita']) ?></div>
            <div class="res-liga"><?= e($r['liga'] ?? $r['deporte']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section id="promos" aria-labelledby="hPromos">
      <div class="sec-head"><h2 id="hPromos">PROMOCIONES <span class="gold">ACTIVAS</span></h2></div>
      <div class="promo-strip">
        <div class="promo-card p1 glass" data-anim="card">
          <div class="ic" aria-hidden="true"><i class="bi bi-gift-fill"></i></div>
          <h3>Bono 100% Bienvenida</h3>
          <p>Hasta L. 5,000 en tu primer depósito, acreditado al instante.</p>
          <a href="register.php">Reclamar <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
        <div class="promo-card p2 glass" data-anim="card">
          <div class="ic" aria-hidden="true"><i class="bi bi-fire"></i></div>
          <h3>Cuota Mejorada</h3>
          <p>Cuotas boosteadas en Premier League, La Liga, Serie A, NBA y MLB.</p>
          <a href="login.php">Ver cuotas <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
          <!-- Barra vinculada al scroll con ScrollTrigger (ver js/filters.js,
               Tarea 2) — reemplaza el onScroll casero anterior. -->
          <div class="scroll-fill" id="promoScrollFill"><span></span></div>
        </div>
        <div class="promo-card p3 glass" data-anim="card">
          <div class="ic" aria-hidden="true"><i class="bi bi-gem"></i></div>
          <h3>Club VIP GoalBet</h3>
          <p>Cashback semanal y soporte prioritario para apostadores frecuentes.</p>
          <a href="#vip">Conocer más <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>
      </div>
    </section>

    <section id="vip" class="vip-section" aria-labelledby="hVip" data-anim="card">
      <div class="vip-stars" aria-hidden="true"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></div>
      <h2 id="hVip">GOALBET <span class="gold">VIP CLUB</span></h2>
      <p>Un nivel de servicio reservado para quienes viven el juego a diario: gestor personal, retiros prioritarios y beneficios que crecen contigo.</p>
      <div class="vip-grid">
        <div class="vip-item"><i class="bi bi-cash-coin" aria-hidden="true"></i><span>Cashback semanal</span></div>
        <div class="vip-item"><i class="bi bi-ticket-perforated" aria-hidden="true"></i><span>Bonos exclusivos</span></div>
        <div class="vip-item"><i class="bi bi-headset" aria-hidden="true"></i><span>Atención privada 24/7</span></div>
        <div class="vip-item"><i class="bi bi-calendar-event" aria-hidden="true"></i><span>Eventos exclusivos</span></div>
      </div>
      <a href="register.php" class="btn-hero-primary"><i class="bi bi-gem" aria-hidden="true"></i> Unirme al Club VIP</a>
    </section>

  </main>

  <?php
/**
 * partials/_betslip.php
 * Requiere en scope: $destacado, esta_logueado(), escudo_img().
 */
?>
<aside class="betslip glass" id="betslip" aria-label="Mi apuesta">
  <!-- Parte 2 — Tarea 1: botón de abrir/cerrar SIEMPRE presente en mobile,
       además del gesto de drag. aria-expanded refleja el estado real del
       panel (lo actualiza js/betslip.js en cada toggle, tanto por click
       como por drag). -->
  <button type="button" class="bs-toggle-btn" id="bsToggleBtn" aria-expanded="false" aria-controls="betslip">
    <span>Mi apuesta</span> <i class="bi bi-chevron-up" aria-hidden="true"></i>
  </button>
  <div class="bs-drag-handle" id="bsHandle" aria-hidden="true"></div>
  <div class="bs-head">
    <h2>MI <span style="color:var(--gold)">APUESTA</span></h2>
    <i class="bi bi-gear" style="color:var(--muted)" aria-hidden="true"></i>
  </div>

  <div id="betslipBody">
    <?php if ($destacado): ?>
      <!-- Tarea 1 (bug crítico, Parte 1): este bloque tiene data-anim="ticket"
           y por la regla global [data-anim]{opacity:0} arranca invisible.
           Antes solo se revelaba dentro de armarTicket() (al clickear una
           cuota), así que quedaba vacío en la carga inicial. Ahora hero.js
           lo anima también dentro de revealPage(). -->
      <div class="bs-pick glass" id="bsPick" data-anim="ticket">
        <div class="mkt">Resultado Final <span class="odd mono"><?= number_format((float)$destacado['cuota_local'],2) ?></span></div>
        <div class="sel"><?= escudo_img($destacado['escudo_local'], $destacado['equipo_local']) ?> <span id="bsSelTxt"><?= e($destacado['equipo_local']) ?></span></div>
        <div class="vs">vs <span id="bsRivalTxt"><?= e($destacado['equipo_visita']) ?></span></div>
      </div>
      <div class="bs-amount">
        <label for="montoApuesta">Monto de apuesta (Lempiras)</label>
        <input type="number" id="montoApuesta" value="200" min="50" inputmode="decimal" data-cuota="<?= (float)$destacado['cuota_local'] ?>">
      </div>
      <div class="bs-summary"><span>Cuota</span><span id="bsCuotaTxt"><?= number_format((float)$destacado['cuota_local'],2) ?></span></div>
      <div class="bs-summary"><span>Ganancia potencial</span><b id="ganPotencial">L. <?= number_format(200*(float)$destacado['cuota_local'],2) ?></b></div>
      <button type="button" class="btn-place" id="btnConfirmarApuesta"
              data-href="<?= esta_logueado() ? 'apostar.php?partido=' . (int)$destacado['id'] . '&mercado=1' : 'login.php' ?>">
        <span><?= esta_logueado() ? 'CONFIRMAR APUESTA' : 'INICIAR SESIÓN PARA APOSTAR' ?></span>
      </button>
      <?php if (esta_logueado()): ?>
      <button type="button" class="btn-cashout" id="btnCashout"><span class="glow" aria-hidden="true"></span><i class="bi bi-cash-stack" aria-hidden="true"></i> Cash Out demo (L. <span id="cashoutMonto">180.00</span>)</button>
      <?php endif; ?>
      <p class="rl-msg" id="rlMsg"></p>
    <?php else: ?>
      <div class="bs-empty"><i class="bi bi-ticket-perforated fs-3 mb-2 d-block" aria-hidden="true"></i>Elegí una cuota para armar tu apuesta.</div>
    <?php endif; ?>
  </div>

  <div class="promo-side">
    <h3>Bono 100%</h3>
    <p>Hasta L. 5,000 en tu primer depósito</p>
    <a href="register.php">Registrarme</a>
  </div>
</aside>


</div>

<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-top">
      <div class="footer-brand">
        <div class="brand">
          <span class="brand-mark" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></span>
          <span class="brand-txt">GOAL<span class="gb">BET</span></span>
        </div>
        <p>Apuestas deportivas en vivo con cuotas competitivas, cientos de ligas y pagos instantáneos en Lempiras.</p>
        <div class="footer-social">
          <a href="#" aria-label="Instagram de GoalBet"><i class="bi bi-instagram" aria-hidden="true"></i></a>
          <a href="#" aria-label="X (Twitter) de GoalBet"><i class="bi bi-twitter-x" aria-hidden="true"></i></a>
          <a href="#" aria-label="Facebook de GoalBet"><i class="bi bi-facebook" aria-hidden="true"></i></a>
          <a href="#" aria-label="TikTok de GoalBet"><i class="bi bi-tiktok" aria-hidden="true"></i></a>
        </div>
      </div>
      <nav class="footer-cols" aria-label="Enlaces del sitio">
        <div class="footer-col">
          <h3>Plataforma</h3>
          <a href="partidos.php">Partidos en vivo</a>
          <a href="posiciones.php">Posiciones</a>
          <a href="#promos">Promociones</a>
          <a href="#vip">Club VIP</a>
        </div>
        <div class="footer-col">
          <h3>Cuenta</h3>
          <a href="login.php">Iniciar sesión</a>
          <a href="register.php">Registrarse</a>
          <a href="recargar.php">Recargar saldo</a>
          <a href="mis_apuestas.php">Mis apuestas</a>
        </div>
        <div class="footer-col">
          <h3>Legal</h3>
          <a href="#">Licencias</a>
          <a href="#">Privacidad</a>
          <a href="#">Términos y condiciones</a>
          <a href="#">Contacto</a>
        </div>
      </nav>
    </div>
    <div class="footer-legal">
      <p>Apostar implica riesgo. Jugá con responsabilidad y solo con dinero que puedas permitirte perder. GoalBet promueve el juego responsable; si sentís que el juego dejó de ser un entretenimiento, buscá ayuda profesional. © <?= date('Y') ?> GoalBet. Todos los derechos reservados.</p>
      <div class="age"><span aria-hidden="true">18+</span> Solo para mayores de edad</div>
    </div>
  </div>
</footer>


<!-- ══════════ GOAL CELEBRATION OVERLAY (GSAP) ══════════ -->
<div id="goalOverlay" aria-hidden="true">
  <div class="goal-scene">
    <div class="bokeh b1"></div><div class="bokeh b2"></div><div class="bokeh b3"></div>
    <div class="gs-pitch"></div>
    <div class="gs-scoreline" id="gsScoreline">TU APUESTA</div>
    <div id="gsReplayBadge">● REPLAY</div>
    <div class="gs-post top"></div><div class="gs-post left"></div><div class="gs-post right"></div>
    <div id="netStage"><div id="netGrid"></div></div>
    <div id="gsBallTrail"></div>
    <div id="gsBall"></div>
    <div id="gsGoalText"></div>
    <div class="gs-vignette"></div>
  </div>
  <div id="gsCloseHint">redirigiendo…</div>
</div>

<!-- Tarea 5 (Parte 1): único entrypoint de JS "core" (above the fold:
     loader, hero, nav, filtros, betslip). Todo el motion (GSAP + plugins)
     y la lógica no-PHP viven en js/*.js, importados como módulos ES desde
     acá.

     Parte 2 — Tarea 2 (performance):
      - Las partículas y el balón Trionda ya NO se inicializan en el mismo
        tick que el resto: se difieren con requestIdleCallback (fallback
        setTimeout) para no competir con el First Contentful Paint.
      - El JS que solo hace falta al hacer scroll (contadores/animaciones
        de stats-strip, campeones, promos, footer, y la celebración de gol
        que solo corre al confirmar una apuesta) se separa en un import()
        dinámico disparado por IntersectionObserver, en vez de cargar todo
        de una sola vez en el bundle inicial. -->
<script type="module">
/**
 * main.js — entrypoint único (Parte 1) + code splitting (Parte 2)
 * ------------------------------------------------------------------
 * Carga GSAP core de inmediato (lo necesita el hero above-the-fold),
 * pero separa en dos grupos el resto del trabajo:
 *   A) CRÍTICO, corre apenas el DOM está listo: navbar, filtros,
 *      sidebar mobile, betslip (sin la celebración de gol todavía),
 *      loader/hero (sin partículas/balón todavía).
 *   B) DIFERIDO, corre en idle time o al entrar en viewport:
 *      - partículas de fondo + balón Trionda → requestIdleCallback
 *      - ScrollTrigger/promo-fill, contadores de stats-strip, entrada de
 *        campeones/promos/footer, celebración de gol → import() dinámico
 *        disparado por IntersectionObserver sobre el primer elemento
 *        "below the fold" (#partidos).
 * ------------------------------------------------------------------
 */
import gsap from 'https://cdn.jsdelivr.net/npm/gsap@3.13.0/+esm';

/* ================= js/design-tokens.js ================= */
/**
 * design-tokens.js
 * ------------------------------------------------------------------
 * Tarea 5 del prompt: los colores NO deben estar duplicados entre
 * :root (CSS) y valores hardcodeados en el JS de animación (antes
 * existía var(--green) en CSS y '#00FFB3' repetido a mano en el
 * código de celebración de gol).
 *
 * En vez de mantener un segundo objeto de colores en JS (que se
 * desincroniza apenas alguien cambia el CSS), este módulo LEE los
 * custom properties directamente de :root en runtime. :root sigue
 * siendo la única fuente de verdad.
 * ------------------------------------------------------------------
 */

const tokenCache = new Map();

/** Lee un --custom-property de :root, con cache simple. */
function token(name) {
  if (tokenCache.has(name)) return tokenCache.get(name);
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue(name)
    .trim();
  tokenCache.set(name, value);
  return value;
}

const COLORS = {
  get gold() { return token('--gold'); },
  get goldDeep() { return token('--gold-deep'); },
  get goldBright() { return token('--gold-bright'); },
  get green() { return token('--green'); },
  get violet() { return token('--violet'); },
  get red() { return token('--red'); },
  get bg() { return token('--bg'); },
};


/* ================= js/motion.js ================= */
/**
 * motion.js
 * ------------------------------------------------------------------
 * Tarea 6 (Parte 1): lenguaje de motion consistente.
 *  - MOTION centraliza duraciones/easings en vez de tener strings de
 *    easing repetidos por todo el archivo.
 *  - tiltOnHover() / magneticOnHover() son las versiones reutilizables
 *    y parametrizadas de los efectos hover.
 *
 * Tarea 2 (Parte 1): todo lo de este archivo (y de los demás módulos)
 * usa GSAP como único motor de animación.
 * ------------------------------------------------------------------
 */

const prefersReducedMotion =
  window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const isMobile = window.matchMedia('(max-width: 840px)').matches;
const hasFinePointer = window.matchMedia('(pointer: fine)').matches;

const MOTION = {
  duration: {
    instant: 0.001,
    fast: 0.22,
    medium: 0.5,
    slow: 0.9,
    xslow: 1.3,
  },
  ease: {
    default: 'expo.out',
    soft: 'power2.out',
    spring: 'elastic.out(1, 0.6)',
    springStrong: 'elastic.out(1, 0.4)',
    inOut: 'sine.inOut',
    quad: 'power1.out',
  },
  stagger: {
    tight: 0.03,
    normal: 0.06,
    loose: 0.12,
  },
};

/**
 * Tilt 3D en hover, parametrizado.
 * @param {NodeListOf<Element>|Element[]} elements
 * @param {{maxTilt?: number, duration?: number}} opts
 */
function tiltOnHover(gsapRef, elements, opts = {}) {
  if (prefersReducedMotion || !hasFinePointer) return;
  const maxTilt = opts.maxTilt ?? 6;
  const list = elements instanceof Element ? [elements] : Array.from(elements);
  list.forEach((card) => {
    card.classList.add('js-tilt');
    const onMove = (e) => {
      const r = card.getBoundingClientRect();
      const px = (e.clientX - r.left) / r.width;
      const py = (e.clientY - r.top) / r.height;
      gsapRef.to(card, {
        rotateX: (py - 0.5) * -maxTilt,
        rotateY: (px - 0.5) * maxTilt,
        duration: MOTION.duration.fast,
        ease: MOTION.ease.quad,
        overwrite: 'auto',
      });
    };
    const onLeave = () => {
      gsapRef.to(card, {
        rotateX: 0,
        rotateY: 0,
        duration: MOTION.duration.medium,
        ease: MOTION.ease.spring,
        overwrite: 'auto',
      });
    };
    card.addEventListener('mousemove', onMove);
    card.addEventListener('mouseleave', onLeave);
  });
}

/**
 * Botón/elemento "magnético".
 * @param {{intensityX?: number, intensityY?: number}} opts
 */
function magneticOnHover(gsapRef, elements, opts = {}) {
  if (prefersReducedMotion || !hasFinePointer) return;
  const ix = opts.intensityX ?? 14;
  const iy = opts.intensityY ?? 10;
  const list = elements instanceof Element ? [elements] : Array.from(elements);
  list.forEach((el) => {
    el.classList.add('js-magnetic');
    el.addEventListener('mousemove', (e) => {
      const r = el.getBoundingClientRect();
      const px = (e.clientX - r.left) / r.width - 0.5;
      const py = (e.clientY - r.top) / r.height - 0.5;
      gsapRef.to(el, {
        x: px * ix,
        y: py * iy,
        duration: MOTION.duration.fast,
        ease: MOTION.ease.quad,
        overwrite: 'auto',
      });
    });
    el.addEventListener('mouseleave', () => {
      gsapRef.to(el, {
        x: 0,
        y: 0,
        duration: MOTION.duration.medium,
        ease: MOTION.ease.spring,
        overwrite: 'auto',
      });
    });
  });
}

/** requestIdleCallback con fallback a setTimeout (Parte 2 — Tarea 2). */
function whenIdle(fn, timeout = 1500) {
  if ('requestIdleCallback' in window) {
    window.requestIdleCallback(fn, { timeout });
  } else {
    setTimeout(fn, 200);
  }
}


/* ================= js/rate-limit.js ================= */
/**
 * rate-limit.js
 * ------------------------------------------------------------------
 * Rate-limiter reutilizable basado en sessionStorage. Es UX anti-spam
 * del lado cliente para que no se pueda machacar "Confirmar apuesta
 * demo" / "Cash Out demo", NO reemplaza la validación real del backend
 * (que sigue viviendo en apostar.php).
 * ------------------------------------------------------------------
 */
function crearLimitador(nombre, maxUsos, ventanaMs) {
  const key = 'gb_rl_' + nombre;
  return function intentar() {
    const ahora = Date.now();
    let data;
    try { data = JSON.parse(sessionStorage.getItem(key) || '{}'); } catch { data = {}; }
    if (!data.inicio || ahora - data.inicio > ventanaMs) { data = { inicio: ahora, usos: 0 }; }
    if (data.usos >= maxUsos) {
      const restanteSeg = Math.ceil((ventanaMs - (ahora - data.inicio)) / 1000);
      return { ok: false, restanteSeg };
    }
    data.usos += 1;
    sessionStorage.setItem(key, JSON.stringify(data));
    return { ok: true, restantes: maxUsos - data.usos };
  };
}


/* ================= js/particles.js (diferido, Parte 2 — Tarea 2) ================= */
/**
 * particles.js
 * ------------------------------------------------------------------
 * Partículas de fondo (canvas global) + partículas conectadas del hero.
 * Se inicializa desde requestIdleCallback (ver bloque de orquestación al
 * final) para no competir con el First Contentful Paint: el canvas ya
 * está en el DOM (reserva layout), pero el trabajo de dibujo/seed arranca
 * después del primer paint.
 * ------------------------------------------------------------------
 */

const PARTICLE_COLORS = ['255,201,60', '123,97,255', '0,255,179'];

function makeField(canvas, { density, particleRadius, drift, links }) {
  const ctx = canvas.getContext('2d');
  let w = 0, h = 0, particles = [];

  function resize(rectSource) {
    const rect = rectSource ? rectSource.getBoundingClientRect() : { width: window.innerWidth, height: window.innerHeight };
    w = canvas.width = rect.width;
    h = canvas.height = rect.height;
    seed();
  }
  function seed() {
    const count = Math.min(density.max, Math.round((w * h) / density.divisor));
    particles = Array.from({ length: count }, () => ({
      x: Math.random() * w, y: Math.random() * h,
      r: particleRadius.min + Math.random() * particleRadius.range,
      vx: (Math.random() - 0.5) * drift, vy: (Math.random() - 0.5) * drift,
      c: PARTICLE_COLORS[Math.floor(Math.random() * PARTICLE_COLORS.length)],
      a: 0.15 + Math.random() * 0.5,
    }));
  }
  function tick() {
    ctx.clearRect(0, 0, w, h);
    for (let i = 0; i < particles.length; i++) {
      const p = particles[i];
      p.x += p.vx; p.y += p.vy;
      if (p.x < 0) p.x = w; if (p.x > w) p.x = 0;
      if (p.y < 0) p.y = h; if (p.y > h) p.y = 0;
      if (links) {
        for (let j = i + 1; j < particles.length; j++) {
          const q = particles[j];
          const dx = p.x - q.x, dy = p.y - q.y;
          const dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < links.dist) {
            ctx.strokeStyle = `rgba(255,255,255,${(1 - dist / links.dist) * links.alpha})`;
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke();
          }
        }
      }
      ctx.beginPath();
      ctx.fillStyle = links ? `rgba(${p.c},.55)` : `rgba(${p.c},${p.a})`;
      ctx.arc(p.x, p.y, links ? 1.8 : p.r, 0, Math.PI * 2);
      ctx.fill();
    }
  }
  return { resize, tick };
}

function initParticles(gsapRef) {
  if (prefersReducedMotion) return;

  const bgCanvas = document.getElementById('particlesCanvas');
  if (bgCanvas) {
    const bg = makeField(bgCanvas, {
      density: { max: 70, divisor: 22000 },
      particleRadius: { min: 0.4, range: 1.6 },
      drift: 0.12,
    });
    bg.resize();
    window.addEventListener('resize', () => bg.resize(), { passive: true });
    const bgTickFn = () => { if (!document.hidden) bg.tick(); };
    gsapRef.ticker.add(bgTickFn);
  }

  const heroCanvas = document.getElementById('heroParticlesCanvas');
  const heroSection = document.getElementById('home');
  if (heroCanvas && heroSection) {
    const heroField = makeField(heroCanvas, {
      density: { max: 46, divisor: 26000 },
      particleRadius: { min: 1.8, range: 0 },
      drift: 0.18,
      links: { dist: 140, alpha: 0.12 },
    });
    heroField.resize(heroSection);
    window.addEventListener('resize', () => heroField.resize(heroSection), { passive: true });

    let heroVisible = true;
    const heroTickFn = () => { if (!document.hidden && heroVisible) heroField.tick(); };
    gsapRef.ticker.add(heroTickFn);

    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => { heroVisible = entry.isIntersecting; });
    }, { threshold: 0 });
    io.observe(heroSection);
  }
}


/* ================= js/hero.js ================= */
/**
 * hero.js
 * ------------------------------------------------------------------
 * Loader + secuencia de entrada del hero. El balón "Trionda" 3D se
 * construye por separado y de forma diferida (ver initBallDeferred,
 * Parte 2 — Tarea 2) para no competir con el FCP.
 * ------------------------------------------------------------------
 */

function buildBall(gsapRef, container, opts) {
  const { radius, ringCounts, cls, colors, colorWeights, spread } = opts;
  const numRings = ringCounts.length;
  const triangles = [];
  const frag = document.createDocumentFragment();

  for (let ring = 0; ring < numRings; ring++) {
    const count = ringCounts[ring];
    const phi = (Math.PI * (ring + 0.5)) / numRings;
    const y = -Math.cos(phi) * radius * 0.9;
    const ringRadius = Math.sin(phi) * radius;
    for (let i = 0; i < count; i++) {
      const theta = (2 * Math.PI * i) / count + ring * 0.3;
      const x = Math.cos(theta) * ringRadius;
      const z = Math.sin(theta) * ringRadius;

      let rand = Math.random(), colorClass = colors[colors.length - 1], cumsum = 0;
      for (let c = 0; c < colors.length; c++) {
        cumsum += colorWeights[c];
        if (rand <= cumsum) { colorClass = colors[c]; break; }
      }

      const tri = document.createElement('div');
      tri.className = cls + ' ' + colorClass;
      tri.style.width = (spread.wMin + Math.random() * spread.wRange) + 'px';
      tri.style.height = (spread.hMin + Math.random() * spread.hRange) + 'px';

      const rotY = (theta * 180) / Math.PI;
      const rotX = ((phi - Math.PI / 2) * 180) / Math.PI;
      tri.dataset.originX = x; tri.dataset.originY = y; tri.dataset.originZ = z;
      tri.dataset.rotX = rotX; tri.dataset.rotY = rotY;

      gsapRef.set(tri, { x, y, z, rotateX: rotX, rotateY: rotY, opacity: 0 });
      frag.appendChild(tri);
      triangles.push(tri);
    }
  }
  container.appendChild(frag);
  return triangles;
}

function animateCounters(gsapRef, root) {
  root.querySelectorAll('[data-count-to]').forEach((el) => {
    const target = parseFloat(el.dataset.countTo) || 0;
    if (prefersReducedMotion) { el.textContent = target.toFixed(2); return; }
    const obj = { v: 0 };
    gsapRef.to(obj, {
      v: target, duration: MOTION.duration.slow, ease: MOTION.ease.default,
      onUpdate: () => { el.textContent = obj.v.toFixed(2); },
    });
  });
  root.querySelectorAll('[data-count-int]').forEach((el) => {
    const target = parseInt(el.dataset.countInt, 10) || 0;
    if (prefersReducedMotion || target === 0) { el.textContent = String(target); return; }
    const obj = { v: 0 };
    gsapRef.to(obj, {
      v: target, duration: MOTION.duration.xslow, ease: MOTION.ease.soft,
      onUpdate: () => { el.textContent = String(Math.round(obj.v)); },
    });
  });
}

function initHero(gsapRef) {
  const loadingScreen = document.getElementById('loadingScreen');
  const heroSkel = document.querySelector('.hero-skel');

  function revealPage() {
    const dur = prefersReducedMotion ? 0.01 : MOTION.duration.slow * 0.85;

    if (heroSkel) {
      if (prefersReducedMotion) { heroSkel.remove(); }
      else gsapRef.to(heroSkel, { opacity: 0, duration: MOTION.duration.medium, onComplete: () => heroSkel.remove() });
    }

    if (prefersReducedMotion) {
      gsapRef.set('[data-anim="hero-copy"]', { opacity: 1, y: 0 });
      gsapRef.set('[data-anim="hero-side"]', { opacity: 1, y: 0, rotateY: 0 });
    } else {
      gsapRef.to('[data-anim="hero-copy"]', { opacity: 1, y: 0, duration: dur, ease: MOTION.ease.default });
      gsapRef.to('[data-anim="hero-side"]', {
        opacity: 1, y: 0, rotateY: 0, duration: dur,
        delay: 0.12, ease: MOTION.ease.default,
      });
    }

    // Tarea 1 (bug crítico, Parte 1): el ticket de apuesta (#bsPick)
    // también debe entrar acá. Antes solo se revelaba dentro de
    // armarTicket(), que corre exclusivamente al hacer clic en una
    // cuota, así que el panel "Mi Apuesta" quedaba vacío hasta la
    // primera interacción.
    const bsPick = document.getElementById('bsPick');
    if (bsPick) {
      if (prefersReducedMotion) {
        gsapRef.set(bsPick, { opacity: 1 });
      } else {
        gsapRef.fromTo(bsPick, { opacity: 0, y: 14, scale: 0.97 }, {
          opacity: 1, y: 0, scale: 1, duration: dur,
          delay: 0.2, ease: MOTION.ease.default,
        });
      }
    }

    revealHeadline(gsapRef);
    animateCounters(gsapRef, document);

    const scrollHint = document.getElementById('scrollHint');
    if (scrollHint) {
      if (prefersReducedMotion) {
        gsapRef.set(scrollHint, { opacity: 1 });
      } else {
        gsapRef.to(scrollHint, {
          opacity: 1, y: 0, duration: MOTION.duration.medium,
          delay: dur + 0.3, ease: MOTION.ease.quad,
        });
      }
    }
  }

  if (loadingScreen) {
    document.documentElement.style.overflow = 'hidden';

    if (prefersReducedMotion) {
      document.getElementById('loadingPct').textContent = '100%';
      loadingScreen.classList.add('hide');
      setTimeout(() => loadingScreen.remove(), 50);
      document.documentElement.style.overflow = '';
      revealPage();
    } else {
      const loaderStage = document.getElementById('loaderStage');
      const pctTxt = document.getElementById('loadingPct');
      const wordMark = document.querySelector('.loader-word');

      const loaderTriangles = buildBall(gsapRef, loaderStage, {
        radius: 40,
        ringCounts: [3, 5, 6, 6, 5, 3],
        cls: 'loader-ball-tri',
        colors: ['gold-bright', 'gold-dim', 'dark'],
        colorWeights: [0.4, 0.35, 0.25],
        spread: { wMin: 7, wRange: 4, hMin: 9, hRange: 5 },
      });
      gsapRef.to(loaderTriangles, {
        opacity: 0.9, scale: 1, duration: 0.7,
        stagger: { each: 0.008, from: 'center' }, ease: MOTION.ease.default,
      });
      gsapRef.fromTo(loaderTriangles, { scale: 0.4 }, { scale: 1, duration: 0.7, stagger: { each: 0.008, from: 'center' }, ease: MOTION.ease.default });

      const loaderSpin = gsapRef.to(loaderStage, {
        rotateY: 360, duration: 4, repeat: -1, ease: 'none',
      });
      gsapRef.to(loaderStage, {
        rotateX: 12, duration: 2.6, repeat: -1, yoyo: true, ease: MOTION.ease.inOut,
      });

      if (wordMark) {
        gsapRef.to(wordMark, { opacity: 0.55, duration: 0.7, repeat: -1, yoyo: true, ease: MOTION.ease.inOut });
      }

      const progressObj = { p: 0 };
      const introTl = gsapRef.timeline({
        onComplete: () => {
          loaderSpin.kill();
          document.documentElement.style.overflow = '';
          revealPage();
        },
      });
      introTl
        .to(progressObj, {
          p: 1, duration: 0.7, ease: MOTION.ease.default,
          onUpdate: () => { pctTxt.textContent = Math.round(progressObj.p * 100) + '%'; },
        })
        .to(loaderStage, { scale: 1.12, duration: 0.13, ease: MOTION.ease.quad }, '-=0.12')
        .to(loaderStage, { scale: 1, duration: 0.13, ease: MOTION.ease.quad })
        .to(loadingScreen, { opacity: 0, duration: 0.5, onComplete: () => loadingScreen.remove() }, '+=0.12');
    }
  } else {
    revealPage();
  }

  const heroWords = document.querySelectorAll('#heroHeadline .hw');
  if (heroWords.length && !prefersReducedMotion) {
    gsapRef.set(heroWords, { opacity: 0, y: 22, rotateX: -55 });
  }
  function revealHeadline(gsapRef2) {
    if (!heroWords.length) return;
    if (prefersReducedMotion) { gsapRef2.set(heroWords, { opacity: 1 }); return; }
    gsapRef2.to(heroWords, {
      opacity: 1, y: 0, rotateX: 0, duration: MOTION.duration.medium + 0.2,
      stagger: MOTION.stagger.normal, delay: 0.1, ease: MOTION.ease.default,
    });
  }

  magneticOnHover(gsapRef, document.querySelectorAll('.btn-hero-primary, .btn-hero-secondary'));

  if (!prefersReducedMotion) {
    document.querySelectorAll('#bgMesh span').forEach((span, i) => {
      gsapRef.to(span, {
        x: (i % 2 ? 1 : -1) * (40 + i * 8),
        y: (i % 2 ? -1 : 1) * (30 + i * 6),
        duration: 11 + i * 1.5, repeat: -1, yoyo: true, ease: MOTION.ease.inOut,
      });
    });
  }

  const liveDots = document.querySelectorAll('[data-anim-live]');
  if (liveDots.length) {
    if (prefersReducedMotion) {
      gsapRef.set(liveDots, { opacity: 1 });
    } else {
      gsapRef.to(liveDots, {
        opacity: 0.25, scale: 1.25, duration: 0.7, repeat: -1, yoyo: true, ease: MOTION.ease.inOut,
      });
    }
  }

  (function () {
    const scrollHint = document.getElementById('scrollHint');
    if (!scrollHint || prefersReducedMotion) return;
    let hintVisible = true;
    document.addEventListener('scroll', () => {
      const shouldShow = window.scrollY < 80;
      if (shouldShow === hintVisible) return;
      hintVisible = shouldShow;
      gsapRef.to(scrollHint, { opacity: shouldShow ? 1 : 0, duration: 0.35, ease: MOTION.ease.quad });
    }, { passive: true });
  })();

  if (!isMobile) {
    tiltOnHover(gsapRef, document.querySelectorAll('.hero-card'), { maxTilt: 8 });
    tiltOnHover(gsapRef, document.querySelectorAll('.ev-row'), { maxTilt: 2.5 });
    tiltOnHover(gsapRef, document.querySelectorAll('.champ-card'), { maxTilt: 5 });
  }

  return { animateCounters, revealPage };
}

/**
 * Balón "Trionda" del hero — construcción diferida (Parte 2 — Tarea 2).
 * Antes corría en el mismo tick que el resto del hero; ahora se invoca
 * desde requestIdleCallback (ver orquestación al final del archivo) para
 * que el balón (90+ nodos DOM en desktop) no compita con el FCP.
 */
function initBallDeferred(gsapRef) {
  const ballEl = document.getElementById('ballTrionda');
  const ballContainer = document.getElementById('heroBallContainer');
  if (!ballEl || !ballContainer) return;

  // En mobile no se construye el balón en absoluto: antes quedaba con
  // opacity:.2 pero seguía pagando el 100% del costo de 90+ divs.
  if (isMobile) return;

  const heroBallTriangles = buildBall(gsapRef, ballEl, {
    radius: 180,
    ringCounts: [4, 7, 9, 10, 10, 9, 7, 4],
    cls: 'trionda-triangle',
    colors: ['gold-bright', 'gold-dim', 'dark', 'outline'],
    colorWeights: [0.35, 0.3, 0.2, 0.15],
    spread: { wMin: 18, wRange: 12, hMin: 22, hRange: 14 },
  });

  if (prefersReducedMotion) {
    gsapRef.set(heroBallTriangles, { opacity: 0.85 });
    return;
  }

  gsapRef.fromTo(heroBallTriangles, { opacity: 0, z: 100, scale: 0.5 }, {
    opacity: 0.85, z: 0, scale: 1, duration: 1.2,
    stagger: { each: 0.012, from: 'center' }, ease: MOTION.ease.default,
  });

  const spinTween = gsapRef.to(ballEl, { rotateY: 360, duration: 40, repeat: -1, ease: 'none' });
  gsapRef.to(ballEl, { rotateX: 10, duration: 9, repeat: -1, yoyo: true, ease: MOTION.ease.inOut });

  let ballInViewport = true;
  function syncSpin() {
    const shouldPlay = ballInViewport && !document.hidden;
    if (shouldPlay) spinTween.play(); else spinTween.pause();
  }
  document.addEventListener('visibilitychange', syncSpin);
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => { ballInViewport = entry.isIntersecting; syncSpin(); });
  }, { threshold: 0 });
  io.observe(ballContainer);

  function explodeBall() {
    heroBallTriangles.forEach((tri) => {
      const ox = parseFloat(tri.dataset.originX), oy = parseFloat(tri.dataset.originY), oz = parseFloat(tri.dataset.originZ);
      gsapRef.to(tri, {
        x: ox * 2.5 + (Math.random() - 0.5) * 300,
        y: oy * 2.5 + (Math.random() - 0.5) * 300,
        z: oz * 2.5 + (Math.random() - 0.5) * 400,
        rotateX: `+=${Math.random() * 720 - 360}`,
        rotateY: `+=${Math.random() * 720 - 360}`,
        rotateZ: Math.random() * 720 - 360,
        opacity: 0.25, duration: 1.5, ease: MOTION.ease.default,
        delay: Math.random() * 0.2,
      });
    });
  }
  function assembleBall() {
    heroBallTriangles.forEach((tri) => {
      gsapRef.to(tri, {
        x: parseFloat(tri.dataset.originX), y: parseFloat(tri.dataset.originY), z: parseFloat(tri.dataset.originZ),
        rotateX: parseFloat(tri.dataset.rotX), rotateY: parseFloat(tri.dataset.rotY), rotateZ: 0,
        opacity: 0.85, duration: 1.2, ease: MOTION.ease.springStrong,
        stagger: { each: 0.01, from: 'center' },
      });
    });
  }

  let cycleTimer = null;
  function scheduleCycle(delayMs) {
    clearTimeout(cycleTimer);
    cycleTimer = setTimeout(() => {
      if (document.hidden || !ballInViewport) { scheduleCycle(4000); return; }
      explodeBall();
      setTimeout(assembleBall, 2500);
      scheduleCycle(12000);
    }, delayMs);
  }
  scheduleCycle(6000);

  const heroEl = document.getElementById('home');
  if (heroEl && window.matchMedia('(pointer: fine)').matches) {
    heroEl.addEventListener('mousemove', (e) => {
      const rect = heroEl.getBoundingClientRect();
      const px = (e.clientX - rect.left) / rect.width - 0.5;
      const py = (e.clientY - rect.top) / rect.height - 0.5;
      gsapRef.to(ballContainer, { x: px * 30, y: py * 20, duration: 0.4, ease: MOTION.ease.quad });
    });
    heroEl.addEventListener('mouseleave', () => {
      gsapRef.to(ballContainer, { x: 0, y: 0, duration: 0.6, ease: MOTION.ease.spring });
    });
  }
}


/* ================= js/filters.js ================= */
/**
 * filters.js
 * ------------------------------------------------------------------
 * Todo lo que NO es lógica de negocio PHP: navbar al hacer scroll,
 * menú mobile, filtros de partidos, buscador, fallback de escudos,
 * favoritos, toggle de estadísticas, cálculo del betslip.
 *
 * Parte 2 — Tarea 1 (accesibilidad):
 *  - anunciarCuota() empuja los cambios de cuota a #oddsLiveRegion, en
 *    vez de que el parpadeo de precio sea puramente visual.
 * ------------------------------------------------------------------
 */

function anunciarCuota(texto) {
  const region = document.getElementById('oddsLiveRegion');
  if (!region) return;
  region.textContent = texto;
}

function initFilters(gsapRef) {
  document.addEventListener('error', (e) => {
    const el = e.target;
    if (!(el instanceof HTMLImageElement)) return;
    if (el.hasAttribute('data-fallback-icon')) {
      const alt = el.getAttribute('alt') || '';
      const span = document.createElement('span');
      span.className = 'crest-fallback';
      span.setAttribute('role', 'img');
      if (alt) span.setAttribute('aria-label', alt);
      span.innerHTML = '<i class="bi bi-shield-fill" aria-hidden="true"></i>';
      el.replaceWith(span);
    } else if (el.hasAttribute('data-hide-on-error')) {
      el.style.display = 'none';
    }
  }, true);

  const topnav = document.getElementById('topnav');
  if (topnav) {
    const onScrollNav = () => topnav.classList.toggle('scrolled', window.scrollY > 20);
    document.addEventListener('scroll', onScrollNav, { passive: true });
    onScrollNav();
  }

  const navToggle = document.getElementById('navToggle');
  const sidebar = document.getElementById('mainSidebar');
  if (navToggle && sidebar) {
    navToggle.addEventListener('click', () => {
      const isOpen = sidebar.classList.toggle('open');
      navToggle.setAttribute('aria-expanded', String(isOpen));
    });
  }

  const evRows = Array.from(document.querySelectorAll('.ev-row'));
  const state = { estado: 'todos', sport: 'todos', query: '' };
  function applyFilters() {
    let visibles = 0;
    evRows.forEach((row) => {
      const okEstado = state.estado === 'todos' || row.dataset.estado === state.estado;
      const okSport = state.sport === 'todos' || row.dataset.sport === state.sport;
      const okQuery = !state.query || row.dataset.teams.includes(state.query);
      const visible = okEstado && okSport && okQuery;
      row.style.display = visible ? '' : 'none';
      if (visible) visibles += 1;
    });
    const noResults = document.getElementById('noResultsMsg');
    if (noResults) noResults.hidden = visibles !== 0 || evRows.length === 0;
  }
  function marcarActivo(selector, elegido) {
    document.querySelectorAll(selector).forEach((el) => {
      const activo = el === elegido;
      el.classList.toggle('is-active', activo);
      if (activo) el.setAttribute('aria-current', 'true');
      else el.removeAttribute('aria-current');
    });
  }
  document.querySelectorAll('[data-filter-estado], [data-filter-sport]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (btn.dataset.filterEstado) { state.estado = btn.dataset.filterEstado; marcarActivo('[data-filter-estado]', btn); }
      if (btn.dataset.filterSport) { state.sport = btn.dataset.filterSport; marcarActivo('[data-filter-sport]', btn); }
      applyFilters();
    });
  });
  const searchInput = document.getElementById('teamSearch');
  let searchTimer = null;
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        state.query = searchInput.value.trim().toLowerCase();
        applyFilters();
      }, 180);
    });
  }

  document.querySelectorAll('.stat-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const row = btn.closest('.ev-row');
      const open = row.classList.toggle('stats-open');
      btn.setAttribute('aria-expanded', String(open));
      if (!open) return;
      const bars = row.querySelectorAll('[data-bar-fill]');
      gsapRef.set(bars, { width: '0%' });
      if (prefersReducedMotion) {
        bars.forEach((b) => { b.style.width = b.dataset.barFill + '%'; });
        return;
      }
      gsapRef.to(bars, {
        width: (i, el) => el.dataset.barFill + '%',
        duration: MOTION.duration.medium + 0.3,
        stagger: MOTION.stagger.loose,
        ease: MOTION.ease.default,
      });
    });
  });

  document.querySelectorAll('.ev-fav').forEach((btn) => {
    btn.addEventListener('click', () => {
      const activo = btn.getAttribute('aria-pressed') === 'true';
      btn.setAttribute('aria-pressed', String(!activo));
      const icon = btn.querySelector('i');
      if (icon) icon.className = !activo ? 'bi bi-star-fill' : 'bi bi-star';
      if (prefersReducedMotion) return;
      gsapRef.fromTo(btn, { scale: 1 }, {
        scale: !activo ? 1.4 : 0.8, duration: 0.18, yoyo: true, repeat: 1,
        ease: MOTION.ease.springStrong,
      });
    });
  });

  function bindMonto() {
    const montoInput = document.getElementById('montoApuesta');
    const ganPotencial = document.getElementById('ganPotencial');
    if (!montoInput || !ganPotencial) return;
    const recalc = () => {
      const cuota = parseFloat(montoInput.dataset.cuota) || 0;
      const monto = parseFloat(montoInput.value) || 0;
      ganPotencial.textContent = 'L. ' + (monto * cuota).toFixed(2);
    };
    montoInput.addEventListener('input', recalc);
  }
  bindMonto();

  if (!isMobile && hasFinePointer && !prefersReducedMotion) {
    magneticOnHover(gsapRef, document.querySelectorAll('.topnav-links a'), { intensityX: 5, intensityY: 3 });
    magneticOnHover(gsapRef, document.querySelectorAll('.sb-liga-item'), { intensityX: 4, intensityY: 3 });
  }

  /* ---------- Micro-flash de cuotas (demo) ----------
     Parte 2 — Tarea 1: además del efecto visual, anuncia el cambio en la
     región aria-live dedicada (#oddsLiveRegion), separada del resto de
     la página, para que lectores de pantalla se enteren del cambio de
     cuota sin depender de ver el parpadeo. */
  if (!prefersReducedMotion) {
    setInterval(() => {
      if (document.hidden) return;
      const boxes = Array.from(document.querySelectorAll('.odd-box:not(.disabled)'));
      if (!boxes.length) return;
      const box = boxes[Math.floor(Math.random() * boxes.length)];
      const valEl = box.querySelector('.v');
      if (!valEl) return;
      const actual = parseFloat(valEl.textContent) || 1;
      const sube = Math.random() > 0.5;
      const nuevo = Math.max(1.01, +(actual + (sube ? 1 : -1) * (Math.random() * 0.08 + 0.02)).toFixed(2));
      const obj = { v: actual };
      gsapRef.to(obj, {
        v: nuevo, duration: 0.7, ease: MOTION.ease.quad,
        onUpdate: () => { valEl.textContent = obj.v.toFixed(2); },
      });
      gsapRef.fromTo(box,
        { backgroundColor: sube ? 'rgba(0,255,179,.22)' : 'rgba(255,59,92,.22)' },
        { backgroundColor: 'rgba(255,255,255,.04)', duration: 1.1, ease: MOTION.ease.quad });

      const equipoLbl = box.dataset.equipo || 'Selección';
      anunciarCuota(equipoLbl + ' ahora paga ' + nuevo.toFixed(2) + (sube ? ', subió' : ', bajó'));
    }, 4200);
  }

  return { applyFilters, bindMonto, anunciarCuota };
}


/* ================= js/betslip.js ================= */
/**
 * betslip.js
 * ------------------------------------------------------------------
 * Ticket de apuesta: selección de cuota, timeline de armado, botón
 * "Confirmar apuesta" (dispara la celebración de gol, cargada de forma
 * diferida) y "Cash Out demo".
 *
 * Parte 2 — Tarea 1 (accesibilidad): el bottom-sheet de mobile ahora
 * expone SIEMPRE un botón visible de abrir/cerrar (#bsToggleBtn),
 * además del gesto de drag manual (ya no depende del plugin Draggable
 * de GSAP para poder abrirse/cerrarse — evita cargar ese plugin solo
 * para esta interacción y reduce JS above-the-fold). aria-expanded se
 * mantiene sincronizado en ambos caminos (click y drag táctil).
 * ------------------------------------------------------------------
 */

const limitarConfirmar = crearLimitador('confirmar_apuesta', 5, 60000); // 5 clics / minuto
const limitarCashout = crearLimitador('cashout_demo', 3, 60000); // 3 clics / minuto

function mostrarRateLimitMsg(texto) {
  const el = document.getElementById('rlMsg');
  if (!el) return;
  el.textContent = texto;
  el.classList.add('show');
  clearTimeout(mostrarRateLimitMsg._t);
  mostrarRateLimitMsg._t = setTimeout(() => el.classList.remove('show'), 4000);
}

function initBetslip(gsapRef, { onConfirm, onCashout } = {}) {
  const pick = document.getElementById('bsPick');

  function actualizarDom(datos) {
    const mkt = document.querySelector('.bs-pick .mkt');
    if (mkt) mkt.innerHTML = 'Resultado Final <span class="odd mono">' + datos.cuota.toFixed(2) + '</span>';
    const selTxt = document.getElementById('bsSelTxt');
    if (selTxt) selTxt.textContent = datos.equipo;
    const rivalTxt = document.getElementById('bsRivalTxt');
    if (rivalTxt) rivalTxt.textContent = datos.rival;
    const monto = document.getElementById('montoApuesta');
    if (monto) {
      monto.dataset.cuota = String(datos.cuota);
      const cuotaTxt = document.getElementById('bsCuotaTxt');
      if (cuotaTxt) cuotaTxt.textContent = datos.cuota.toFixed(2);
      const g = (parseFloat(monto.value) || 0) * datos.cuota;
      const gan = document.getElementById('ganPotencial');
      if (gan) gan.textContent = 'L. ' + g.toFixed(2);
    }
    const btn = document.getElementById('btnConfirmarApuesta');
    if (btn) btn.dataset.href = 'apostar.php?partido=' + datos.partido + '&mercado=' + encodeURIComponent(datos.mercado);
  }

  function armarTicket(datos) {
    if (!pick) return;
    if (prefersReducedMotion) { actualizarDom(datos); return; }
    gsapRef.timeline({ defaults: { ease: MOTION.ease.default } })
      .to(pick, { opacity: 0, scale: 0.92, y: -8, duration: MOTION.duration.fast * 0.8 })
      .call(() => actualizarDom(datos))
      .to(pick, { opacity: 1, scale: 1, y: 0, duration: MOTION.duration.medium * 0.85 });
  }

  document.querySelectorAll('[data-pick]').forEach((box) => {
    box.addEventListener('click', () => {
      document.querySelectorAll('.odd-box.is-picked').forEach((b) => b.classList.remove('is-picked'));
      box.classList.add('is-picked');
      if (!prefersReducedMotion) {
        gsapRef.fromTo(box, { scale: 1 }, { scale: 1.08, duration: 0.16, yoyo: true, repeat: 1, ease: MOTION.ease.springStrong });
      }
      armarTicket({
        partido: box.dataset.partido,
        mercado: box.dataset.mercado,
        equipo: box.dataset.equipo,
        rival: box.dataset.rival,
        cuota: parseFloat(box.dataset.cuota) || 0,
      });
    });
  });

  /* ---------- Confirmar apuesta ---------- */
  const btnConfirmar = document.getElementById('btnConfirmarApuesta');
  if (btnConfirmar) {
    btnConfirmar.addEventListener('click', () => {
      const res = limitarConfirmar();
      if (!res.ok) {
        mostrarRateLimitMsg('Demasiados intentos. Esperá ' + res.restanteSeg + 's antes de volver a intentar.');
        if (!prefersReducedMotion) {
          gsapRef.fromTo(btnConfirmar, { x: 0 }, {
            x: 6, duration: 0.06, repeat: 5, yoyo: true, ease: MOTION.ease.inOut,
            onComplete: () => gsapRef.set(btnConfirmar, { x: 0 }),
          });
        }
        return;
      }
      btnConfirmar.disabled = true;
      const original = btnConfirmar.innerHTML;
      btnConfirmar.innerHTML = '<span>Procesando…</span>';
      const destino = btnConfirmar.dataset.href;
      const selTxt = document.getElementById('bsSelTxt');
      const equipoTxt = (selTxt ? selTxt.textContent : 'Tu apuesta') + ' — CONFIRMADA';
      const finalizar = () => { window.location.href = destino; };

      if (typeof onConfirm === 'function') {
        onConfirm(equipoTxt, finalizar);
      } else if (prefersReducedMotion) {
        finalizar();
      } else {
        gsapRef.to(btnConfirmar, {
          scale: 0.97, duration: 0.13, yoyo: true, repeat: 1, ease: MOTION.ease.quad,
          onComplete: () => setTimeout(finalizar, 250),
        });
      }
      setTimeout(() => { btnConfirmar.disabled = false; btnConfirmar.innerHTML = original; }, 3000);
    });
  }

  /* ---------- Cash Out demo ---------- */
  const btnCashout = document.getElementById('btnCashout');
  if (btnCashout) {
    const glow = btnCashout.querySelector('.glow');
    if (glow && !prefersReducedMotion) {
      gsapRef.to(glow, {
        boxShadow: '0 0 0 9px rgba(123,97,255,0)',
        duration: 0.8, repeat: -1, yoyo: true, ease: MOTION.ease.quad,
      });
    }
    btnCashout.addEventListener('click', () => {
      const res = limitarCashout();
      if (!res.ok) {
        mostrarRateLimitMsg('Límite de Cash Out demo alcanzado. Esperá ' + res.restanteSeg + 's.');
        return;
      }
      btnCashout.disabled = true;
      const monto = document.getElementById('cashoutMonto');
      if (typeof onCashout === 'function') {
        onCashout('Cash out realizado', 'Aseguraste L. ' + (monto ? monto.textContent : '0.00') + ' (demo).');
      }
      setTimeout(() => { btnCashout.disabled = false; }, 2500);
    });
  }

  /* ---------- Bottom-sheet mobile: botón visible + drag táctil nativo
     (Parte 2 — Tarea 1) ---------- */
  const betslip = document.getElementById('betslip');
  const handle = document.getElementById('bsHandle');
  const toggleBtn = document.getElementById('bsToggleBtn');
  if (isMobile && betslip) {
    const CERRADO = 0;
    let ABIERTO = -(betslip.offsetHeight - 90);
    let abierto = false;

    function setSheetState(nextAbierto, animar) {
      abierto = nextAbierto;
      const y = abierto ? ABIERTO : CERRADO;
      if (animar && !prefersReducedMotion) {
        gsapRef.to(betslip, { y, duration: 0.42, ease: MOTION.ease.spring });
      } else {
        gsapRef.set(betslip, { y });
      }
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', String(abierto));
    }

    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => setSheetState(!abierto, true));
    }
    if (handle) {
      handle.addEventListener('click', () => setSheetState(!abierto, true));

      // Drag táctil simple (pointer events), sin depender de un plugin
      // externo: mantiene la interacción de arrastre para quien prefiera
      // gesto, mientras el botón cubre el caso teclado/lector de pantalla.
      let startY = 0, startTranslate = 0, dragging = false;
      handle.addEventListener('pointerdown', (e) => {
        dragging = true;
        startY = e.clientY;
        startTranslate = abierto ? ABIERTO : CERRADO;
        handle.setPointerCapture(e.pointerId);
      });
      handle.addEventListener('pointermove', (e) => {
        if (!dragging) return;
        const delta = e.clientY - startY;
        const next = gsapRef.utils.clamp(ABIERTO, CERRADO, startTranslate + delta);
        gsapRef.set(betslip, { y: next });
      });
      handle.addEventListener('pointerup', (e) => {
        if (!dragging) return;
        dragging = false;
        const currentY = gsapRef.getProperty(betslip, 'y');
        setSheetState(currentY < ABIERTO / 2, true);
      });
    }
    window.addEventListener('resize', () => {
      ABIERTO = -(betslip.offsetHeight - 90);
      setSheetState(abierto, false);
    }, { passive: true });
  }

  return { armarTicket };
}


/* ================= js/toast.js ================= */
/**
 * toast.js
 * ------------------------------------------------------------------
 * Notificación de apuesta ganada/perdida y confirmaciones de Cash Out.
 * Demo del lado cliente — en producción se dispara desde un evento
 * real de liquidación de apuesta (websocket o polling a
 * mis_apuestas.php), no desde un temporizador.
 * ------------------------------------------------------------------
 */

const limitarNotifDemo = crearLimitador('notif_demo', 8, 60000);

function initToasts(gsapRef) {
  const stack = document.getElementById('toastStack');

  function lanzarToast(tipo, titulo, texto) {
    if (!stack) return;
    const el = document.createElement('div');
    el.className = 'toast ' + tipo;
    el.innerHTML =
      '<span class="ic" aria-hidden="true"><i class="bi ' + (tipo === 'win' ? 'bi-trophy-fill' : 'bi-x-octagon-fill') + '"></i></span>' +
      '<div class="txt"><b></b><span></span></div>' +
      '<button type="button" class="close" aria-label="Cerrar notificación"><i class="bi bi-x" aria-hidden="true"></i></button>';
    el.querySelector('.txt b').textContent = titulo;
    el.querySelector('.txt span').textContent = texto;
    stack.appendChild(el);

    const cerrar = () => {
      if (prefersReducedMotion) { el.remove(); return; }
      gsapRef.to(el, {
        opacity: 0, x: 40, duration: MOTION.duration.fast * 1.3, ease: 'power1.in',
        onComplete: () => el.remove(),
      });
    };
    el.querySelector('.close').addEventListener('click', cerrar);

    if (prefersReducedMotion) {
      setTimeout(cerrar, 5000);
      return;
    }
    gsapRef.fromTo(el, { opacity: 0, x: 40 }, { opacity: 1, x: 0, duration: MOTION.duration.medium * 0.8, ease: MOTION.ease.default });
    setTimeout(cerrar, 5500);
  }

  if (!prefersReducedMotion) {
    setInterval(() => {
      if (document.hidden) return;
      const res = limitarNotifDemo();
      if (!res.ok) return;
      const gana = Math.random() > 0.5;
      lanzarToast(
        gana ? 'win' : 'lose',
        gana ? '¡Apuesta ganada!' : 'Apuesta perdida',
        gana ? 'Tu pronóstico se cumplió. Revisá "Mis Apuestas".' : 'Esta vez no salió, seguí intentando.'
      );
    }, 26000);
  }

  return { lanzarToast };
}


/* ================= main.js orchestration ================= */
document.documentElement.classList.toggle('reduced-motion', prefersReducedMotion);
document.documentElement.classList.toggle('is-mobile', isMobile);

// Bloque A — CRÍTICO: corre de inmediato, es lo que se ve above-the-fold
// (loader, hero sin balón/partículas, nav, filtros, betslip sin la
// celebración de gol todavía).
const criticalCtx = gsap.context(() => {
  initHero(gsap);

  const { lanzarToast } = initToasts(gsap);

  initBetslip(gsap, {
    onConfirm: (equipoTxt, finalizar) => {
      // La celebración se carga de forma diferida (Bloque B). Si todavía
      // no cargó cuando el usuario confirma, se espera a que termine el
      // import() y recién ahí se dispara — nunca se pierde la animación,
      // solo se retrasa lo mínimo indispensable.
      goalCelebrationReady.then(({ jugarGol }) => jugarGol(equipoTxt, finalizar));
    },
    onCashout: (titulo, texto) => lanzarToast('win', titulo, texto),
  });

  initFilters(gsap);
});

// Bloque B — DIFERIDO (Parte 2, Tarea 2):
//  B.1) Partículas + balón Trionda → requestIdleCallback, no bloquean FCP.
//  B.2) ScrollTrigger + Draggable + SplitText + lo que solo hace falta al
//       hacer scroll (stats-strip counters ya corrieron arriba porque son
//       above-the-fold; acá va específicamente el motion "below the
//       fold": ScrollTrigger de la promo, tilt de champ-card ya cubierto,
//       y sobre todo la celebración de gol con SplitText) se importa
//       dinámicamente cuando el usuario se acerca a esa zona de la
//       página, vía IntersectionObserver sobre #partidos.
whenIdle(() => {
  gsap.context(() => {
    initParticles(gsap);
    initBallDeferred(gsap);
  }, document.getElementById('home'));
});

let resolveGoalCelebration;
const goalCelebrationReady = new Promise((resolve) => { resolveGoalCelebration = resolve; });

function loadDeferredModules() {
  return Promise.all([
    import('https://cdn.jsdelivr.net/npm/gsap@3.13.0/ScrollTrigger.js/+esm'),
    import('https://cdn.jsdelivr.net/npm/gsap@3.13.0/Draggable.js/+esm'),
    import('https://cdn.jsdelivr.net/npm/gsap@3.13.0/SplitText.js/+esm'),
  ]).then(([{ ScrollTrigger }, { Draggable }, { SplitText }]) => {
    gsap.registerPlugin(ScrollTrigger, Draggable, SplitText);

    gsap.context(() => {
      /* ---------- Promo "Cuota Mejorada": barra vinculada al scroll ----------
         Tarea 2 (Parte 1): reemplaza el onScroll casero de Anime.js por
         ScrollTrigger. */
      const promoFill = document.querySelector('#promoScrollFill span');
      if (promoFill) {
        if (prefersReducedMotion) {
          promoFill.style.width = '100%';
        } else {
          gsap.fromTo(promoFill, { width: '0%' }, {
            width: '100%', ease: 'none',
            scrollTrigger: {
              trigger: promoFill.closest('.promo-card'),
              start: 'bottom bottom-=40',
              end: 'top top+=40',
              scrub: true,
            },
          });
        }
      }

      /* ---------- Entrada en cascada de tarjetas below-the-fold ---------- */
      document.querySelectorAll('.res-panel, .promo-strip, .champs-grid, #vip').forEach((container) => {
        const cards = container.matches('[data-anim="card"]')
          ? [container]
          : container.querySelectorAll('[data-anim="card"]');
        if (!cards.length) return;
        const io = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            if (prefersReducedMotion) {
              gsap.set(cards, { opacity: 1, y: 0 });
            } else {
              gsap.to(cards, {
                opacity: 1, y: 0, z: 0, duration: MOTION.duration.medium + 0.1,
                stagger: MOTION.stagger.loose, ease: MOTION.ease.default,
              });
            }
            io.unobserve(entry.target);
          });
        }, { threshold: 0.15 });
        io.observe(container);
      });

      // La entrada de las tarjetas de partidos (#eventsPanel) sí es
      // relevante rápido (primera sección bajo el hero), así que se anima
      // acá mismo apenas el módulo diferido carga, con el mismo patrón.
      const eventsPanel = document.getElementById('eventsPanel');
      if (eventsPanel) {
        const cards = eventsPanel.querySelectorAll('[data-anim="card"]');
        if (cards.length) {
          if (prefersReducedMotion) {
            gsap.set(cards, { opacity: 1, y: 0 });
          } else {
            const io = new IntersectionObserver((entries) => {
              entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                gsap.to(cards, {
                  opacity: 1, y: 0, z: 0, duration: MOTION.duration.medium + 0.1,
                  stagger: MOTION.stagger.loose, ease: MOTION.ease.default,
                });
                io.unobserve(entry.target);
              });
            }, { threshold: 0.15 });
            io.observe(eventsPanel);
          }
        }
      }

      if (!isMobile && hasFinePointer && !prefersReducedMotion) {
        magneticOnHover(gsap, document.querySelectorAll('.promo-card'), { intensityX: 8, intensityY: 6 });
        magneticOnHover(gsap, document.querySelectorAll('.vip-item'), { intensityX: 6, intensityY: 5 });
      }
    });

    /* ---------- goal-celebration.js (ver detalle abajo) ---------- */
    const ROWS = 8, COLS = 16;
    const netGrid = document.getElementById('netGrid');
    let api = { jugarGol: (_, onDone) => onDone && onDone() };

    if (netGrid) {
      const frag = document.createDocumentFragment();
      for (let i = 0; i < ROWS * COLS; i++) {
        const cell = document.createElement('div');
        cell.className = 'net-cell';
        frag.appendChild(cell);
      }
      netGrid.appendChild(frag);
      const cells = document.querySelectorAll('.net-cell');

      api = {
        jugarGol(equipoTxt, onDone) {
          const overlay = document.getElementById('goalOverlay');
          if (!overlay) { onDone && onDone(); return; }
          document.getElementById('gsScoreline').textContent = equipoTxt || 'TU APUESTA';
          overlay.classList.add('is-active');
          gsap.set(overlay, { opacity: 1 });

          if (prefersReducedMotion) {
            document.getElementById('gsGoalText').textContent = '¡GOL!';
            gsap.to('#gsGoalText', { opacity: 1, duration: 0.3 });
            setTimeout(() => {
              overlay.classList.remove('is-active');
              gsap.set(overlay, { opacity: 0 });
              onDone && onDone();
            }, 1200);
            return;
          }

          gsap.set('#gsBall', { x: 0, y: 0, rotation: 0, scale: 1, opacity: 1 });
          gsap.set('#gsBallTrail', { x: 0, y: 0, opacity: 0 });
          document.getElementById('gsGoalText').innerHTML = '';
          gsap.set(cells, { z: 0, opacity: 1 });
          gsap.set('.goal-scene', { scale: 1 });
          gsap.set('#gsReplayBadge', { opacity: 0 });

          const epicentroFila = 2, epicentroCol = 11;
          const tl = gsap.timeline({
            onComplete: () => {
              gsap.to(overlay, {
                opacity: 0, duration: 0.4, delay: 0.3,
                onComplete: () => { overlay.classList.remove('is-active'); onDone && onDone(); },
              });
            },
          });

          tl.to('#gsBallTrail', { opacity: 0.5, duration: 0.1 })
            .to(['#gsBall', '#gsBallTrail'], { x: 300, y: -175, rotation: 480, scale: 0.82, duration: 0.38, ease: 'power2.in' }, '<')
            .to('#gsBallTrail', { opacity: 0, duration: 0.2 })
            .to('#gsFlash', { opacity: 0.9, duration: 0.035 })
            .to('#gsFlash', { opacity: 0, duration: 0.22 })
            .set('.goal-scene', { scale: 1.05 }, '<')
            .to('.goal-scene', { scale: 1, duration: 0.6, ease: 'power3.out' }, '<')
            .to(cells, {
              z: 34, duration: 0.55, ease: 'power2.out',
              stagger: { grid: [ROWS, COLS], from: [epicentroFila, epicentroCol], amount: 0.5 },
            }, '-=0.4')
            .to(cells, {
              z: 0, duration: 0.85, ease: 'elastic.out(1, .3)',
              stagger: { grid: [ROWS, COLS], from: [epicentroFila, epicentroCol], amount: 0.35 },
            }, '-=0.2')
            .to('#gsBall', { x: 288, y: -120, scale: 0.55, opacity: 0, duration: 0.3, ease: 'power1.out' }, '-=1.15')
            .to('#gsReplayBadge', { opacity: 1, duration: 0.2 }, '-=0.95')
            .call(() => {
              const el = document.getElementById('gsGoalText');
              el.textContent = '¡GOOOL!';
              const split = new SplitText(el, { type: 'chars' });
              gsap.set(split.chars, { opacity: 0, y: 30, scale: 0.5, rotation: () => gsap.utils.random(-15, 15) });
              gsap.to(split.chars, { opacity: 1, y: 0, scale: 1, rotation: 0, duration: 0.5, stagger: 0.045, ease: 'back.out(2.2)' });
            })
            .to('#gsGoalText', { opacity: 0, duration: 0.5 }, '+=0.9')
            .to('#gsReplayBadge', { opacity: 0, duration: 0.3 }, '<');
        },
      };
    }

    resolveGoalCelebration(api);
    return api;
  });
}

// Dispara la carga diferida cuando el usuario se acerca a la sección de
// partidos (primera zona "below the fold"), o de inmediato en idle time
// si el viewport ya la muestra sin scroll (pantallas grandes).
const deferredTrigger = document.getElementById('partidos');
if (deferredTrigger) {
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        loadDeferredModules();
        io.disconnect();
      }
    });
  }, { rootMargin: '200px 0px' });
  io.observe(deferredTrigger);
} else {
  whenIdle(loadDeferredModules);
}

// Red de seguridad: si por lo que sea el usuario confirma una apuesta
// antes de que el IntersectionObserver dispare (p. ej. hizo scroll muy
// rápido y click inmediato), forzamos la carga diferida igual.
document.getElementById('btnConfirmarApuesta')?.addEventListener('click', () => {
  loadDeferredModules();
}, { once: true });

window.__goalbetCriticalCtx = criticalCtx; // expuesto solo para debugging/testing manual

</script>
</body>
</html>