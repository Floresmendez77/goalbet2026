<?php

declare(strict_types=1);

/**
 * Colores de camiseta para la cancha de partido.php.
 *
 * - kit_equipo():   [c1 = color principal, c2 = detalles (mangas/cuello), tx = color del número]
 * - kits_partido(): local y visita; si los dos quedan parecidos, la visita cambia a un color alterno.
 * - kit_portero():  camiseta de arquero distinta a la de su equipo.
 *
 * Los colores son una tabla simple por nombre de equipo (tal como está en la tabla `equipos`).
 * Para agregar o corregir uno, basta una línea en kit_tabla(): 'nombre normalizado' => ['#principal', '#detalle'].
 * Los equipos que no estén ahí usan rojo (local) y blanco (visita).
 */

/** Minúsculas, sin tildes ni signos: "Bayern München" -> "bayern munchen". */
function kit_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
    ]);
    $s = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    return trim((string) preg_replace('/\s+/', ' ', $s));
}

/** @return array<string, array{0:string,1:string}> */
function kit_tabla(): array
{
    return [
        // Inglaterra
        'chelsea fc' => ['#034694', '#ffffff'], 'arsenal' => ['#ef0107', '#ffffff'], 'liverpool' => ['#c8102e', '#ffffff'],
        'manchester united fc' => ['#da291c', '#ffffff'], 'manchester city' => ['#6cabdd', '#ffffff'],
        'tottenham' => ['#ffffff', '#132257'], 'newcastle united fc' => ['#f2f2f2', '#111111'], 'everton' => ['#003399', '#ffffff'],
        'west ham' => ['#7a263a', '#1bb1e7'], 'aston villa' => ['#670e36', '#95bfe5'], 'leeds united fc' => ['#ffffff', '#1d428a'],
        'brighton' => ['#0057b8', '#ffffff'], 'crystal palace' => ['#1b458f', '#c4122e'], 'brentford' => ['#e30613', '#ffffff'],
        'fulham fc' => ['#ffffff', '#111111'], 'wolves' => ['#fdb913', '#231f20'], 'nottingham forest' => ['#dd0000', '#ffffff'],
        'afc bournemouth' => ['#da291c', '#111111'], 'burnley' => ['#6c1d45', '#99d6ea'], 'sunderland afc' => ['#eb172b', '#ffffff'],
        // España
        'barcelona' => ['#a50044', '#004d98'], 'real madrid' => ['#ffffff', '#febe10'], 'atletico madrid' => ['#cb3524', '#ffffff'],
        'athletic club' => ['#ee2523', '#ffffff'], 'valencia' => ['#ffffff', '#111111'], 'villarreal' => ['#fbe122', '#005187'],
        'sevilla' => ['#ffffff', '#d71920'], 'real betis' => ['#0bb363', '#ffffff'], 'real sociedad' => ['#0067b1', '#ffffff'],
        'celta vigo' => ['#8ac3ee', '#ffffff'], 'getafe' => ['#005999', '#ffffff'], 'girona' => ['#e41b23', '#ffffff'],
        'osasuna' => ['#d91a21', '#0a346f'], 'rayo vallecano' => ['#ffffff', '#e53026'], 'mallorca' => ['#e20613', '#111111'],
        'alaves' => ['#0761af', '#ffffff'], 'las palmas' => ['#ffe400', '#0b4ea2'], 'rcd espanyol de barcelona' => ['#0b6eb5', '#ffffff'],
        'levante ud' => ['#e4002b', '#0f3e84'], 'elche cf' => ['#ffffff', '#00a859'], 'real racing club de santander' => ['#00a859', '#ffffff'],
        'rc deportivo la coruna' => ['#0b4ea2', '#ffffff'],
        // Italia
        'juventus' => ['#ffffff', '#111111'], 'inter' => ['#0a4da2', '#111111'], 'ac milan' => ['#fb090b', '#111111'],
        'napoli' => ['#12a0d7', '#ffffff'], 'as roma' => ['#8e1f2f', '#f0bc42'], 'lazio' => ['#87d8f7', '#ffffff'],
        'atalanta' => ['#1e71b8', '#111111'], 'fiorentina' => ['#5a2d82', '#ffffff'], 'bologna' => ['#1a2f48', '#a21c26'],
        'torino' => ['#8b1e2d', '#ffffff'], 'genoa' => ['#a41e2b', '#0b2a5b'], 'udinese' => ['#ffffff', '#111111'],
        'cagliari' => ['#a2222d', '#0a2a5f'], 'lecce' => ['#f6c500', '#d6001c'], 'sassuolo' => ['#00a651', '#111111'],
        'hellas verona' => ['#ffd800', '#003c8f'], 'como 1907' => ['#1f5fa8', '#ffffff'], 'como' => ['#1f5fa8', '#ffffff'],
        'parma calcio 1913' => ['#ffffff', '#ffd200'], 'parma' => ['#ffffff', '#ffd200'], 'venezia fc' => ['#111111', '#00a651'],
        'pisa' => ['#111111', '#1e5fa8'], 'cremonese' => ['#c9c9c9', '#c2121d'],
        // Alemania
        'bayern munchen' => ['#dc052d', '#ffffff'], 'borussia dortmund' => ['#fde100', '#111111'], 'bayer leverkusen' => ['#e32221', '#111111'],
        'rb leipzig' => ['#ffffff', '#dd0741'], 'eintracht frankfurt' => ['#111111', '#e1000f'], 'vfb stuttgart' => ['#ffffff', '#e32219'],
        'werder bremen' => ['#1d9053', '#ffffff'], 'union berlin' => ['#eb1923', '#ffffff'], 'vfl wolfsburg' => ['#65b32e', '#ffffff'],
        'borussia monchengladbach' => ['#ffffff', '#00a651'], 'fsv mainz 05' => ['#c3141e', '#ffffff'], '1899 hoffenheim' => ['#1961b5', '#ffffff'],
        'fc augsburg' => ['#ba3733', '#ffffff'], 'hamburger sv' => ['#ffffff', '#0a3a82'], 'fc schalke 04' => ['#004d9d', '#ffffff'],
        // Francia
        'paris saint germain' => ['#004170', '#da291c'], 'marseille' => ['#ffffff', '#2faee0'], 'lyon' => ['#ffffff', '#0a2d6e'],
        'lens' => ['#e30613', '#fcc300'], 'lille' => ['#d3182a', '#ffffff'], 'monaco' => ['#e51a22', '#ffffff'],
        'nice' => ['#111111', '#d4002a'], 'rennes' => ['#e2001a', '#111111'], 'nantes' => ['#fcd800', '#00a651'],
        'strasbourg' => ['#0098d8', '#ffffff'], 'toulouse' => ['#5a2d82', '#ffffff'], 'reims' => ['#e30613', '#ffffff'],
        'montpellier' => ['#f47920', '#0b3a82'], 'stade brestois 29' => ['#e30613', '#ffffff'], 'lorient' => ['#f47920', '#111111'],
        'metz' => ['#7a1f2b', '#ffffff'], 'le havre' => ['#0b4ea2', '#7ec8e3'],
        // Resto de Europa
        'benfica' => ['#e30613', '#ffffff'], 'fc porto' => ['#1d4ea3', '#ffffff'], 'sporting cp' => ['#00a651', '#ffffff'],
        'sporting clube de portugal' => ['#00a651', '#ffffff'], 'sc braga' => ['#e30613', '#ffffff'], 'afc ajax' => ['#ffffff', '#d2122e'],
        'psv eindhoven' => ['#e30613', '#ffffff'], 'feyenoord' => ['#e30613', '#111111'], 'celtic' => ['#00a651', '#ffffff'],
        'rangers' => ['#1b458f', '#ffffff'], 'galatasaray' => ['#c8102e', '#fdb913'], 'fenerbahce' => ['#ffe000', '#0a2a66'],
        'club brugge kv' => ['#1d5fa8', '#111111'], 'shakhtar donetsk' => ['#f47920', '#111111'], 'fc copenhagen' => ['#ffffff', '#1d4f9c'],
        'fc kobenhavn' => ['#ffffff', '#1d4f9c'], 'red bull salzburg' => ['#ffffff', '#e2001a'], 'fk crvena zvezda' => ['#e30613', '#ffffff'],
        'panathinaikos' => ['#00a651', '#ffffff'], 'pae olympiakos sfp' => ['#e30613', '#ffffff'], 'dinamo zagreb' => ['#1d5fa8', '#ffffff'],
        'sparta praha' => ['#a6192e', '#1d4f91'], 'slavia prague' => ['#ffffff', '#e30613'], 'sk slavia praha' => ['#ffffff', '#e30613'],
        // Brasil
        'flamengo' => ['#d3202f', '#111111'], 'palmeiras' => ['#006437', '#ffffff'], 'corinthians' => ['#ffffff', '#111111'],
        'sao paulo' => ['#ffffff', '#d3202f'], 'santos' => ['#ffffff', '#111111'], 'fluminense' => ['#8a1538', '#0a7a3d'],
        'botafogo' => ['#111111', '#ffffff'], 'vasco da gama' => ['#ffffff', '#111111'], 'gremio' => ['#0d80bf', '#111111'],
        'internacional' => ['#e30613', '#ffffff'], 'cruzeiro' => ['#1d5fc5', '#ffffff'], 'atletico mg' => ['#111111', '#ffffff'],
        'bahia' => ['#1d5fc5', '#e30613'],
        // Argentina
        'river plate' => ['#ffffff', '#e30613'], 'boca juniors' => ['#0b3d91', '#f7c600'], 'racing club' => ['#8ccce8', '#ffffff'],
        'independiente' => ['#e30613', '#ffffff'], 'san lorenzo' => ['#0b2d6b', '#c8102e'], 'estudiantes l p' => ['#ffffff', '#e30613'],
        'velez sarsfield' => ['#ffffff', '#0b3d91'], 'lanus' => ['#8b1a2b', '#ffffff'],
        // México
        'club america' => ['#fdd116', '#0b2d6b'], 'guadalajara chivas' => ['#ffffff', '#d3202f'], 'cruz azul' => ['#0b3d91', '#ffffff'],
        'u n a m pumas' => ['#0b2d6b', '#d4af37'], 'tigres uanl' => ['#f5a800', '#0b2d6b'], 'monterrey' => ['#0b2d6b', '#ffffff'],
        'toluca' => ['#d3202f', '#ffffff'], 'leon' => ['#0a8f3c', '#ffffff'], 'cf pachuca' => ['#ffffff', '#0b2d6b'],
        'santos laguna' => ['#ffffff', '#0a8f3c'], 'atlas' => ['#d3202f', '#111111'], 'necaxa' => ['#d3202f', '#ffffff'],
        // MLS
        'inter miami' => ['#f7b5cd', '#111111'], 'los angeles galaxy' => ['#ffffff', '#0b2d6b'], 'los angeles fc' => ['#111111', '#c39e6d'],
        'seattle sounders' => ['#5d9741', '#0b2d6b'], 'atlanta united fc' => ['#80000a', '#111111'],
    ];
}

/** @return array{0:int,1:int,2:int} */
function kit_rgb(string $hex): array
{
    return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
}

/** Distancia entre dos colores #rrggbb (0 = iguales, ~441 = blanco vs negro). */
function kit_dist(string $a, string $b): float
{
    [$r1, $g1, $b1] = kit_rgb($a);
    [$r2, $g2, $b2] = kit_rgb($b);
    return sqrt(($r1 - $r2) ** 2 + ($g1 - $g2) ** 2 + ($b1 - $b2) ** 2);
}

/** Color legible para el número sobre ese fondo. */
function kit_texto(string $hex): string
{
    [$r, $g, $b] = kit_rgb($hex);
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#111827' : '#ffffff';
}

/** @return array{c1:string,c2:string,tx:string} */
function kit_armar(string $c1, string $c2): array
{
    return ['c1' => $c1, 'c2' => $c2, 'tx' => kit_texto($c1)];
}

/** @return array{c1:string,c2:string,tx:string} */
function kit_equipo(string $nombre, bool $local): array
{
    $c = kit_tabla()[kit_norm($nombre)] ?? ($local ? ['#dc2626', '#ffffff'] : ['#f8fafc', '#0f172a']);
    return kit_armar($c[0], $c[1]);
}

/** Si la camiseta es verde como el pasto, usa el color secundario (o blanco) para que no se pierda en la cancha. */
function kit_sobre_pasto(array $k): array
{
    $pasto = '#16a34a';
    if (kit_dist($k['c1'], $pasto) >= 90.0) {
        return $k;
    }
    foreach ([[$k['c2'], $k['c1']], ['#ffffff', '#111111']] as [$a1, $a2]) {
        if (kit_dist($a1, $pasto) >= 90.0) {
            return kit_armar($a1, $a2 === $pasto ? '#111111' : $a2);
        }
    }
    return $k;
}

/**
 * @return array{home:array{c1:string,c2:string,tx:string},away:array{c1:string,c2:string,tx:string}}
 */
function kits_partido(string $local, string $visita): array
{
    $l = kit_sobre_pasto(kit_equipo($local, true));
    $v = kit_sobre_pasto(kit_equipo($visita, false));
    $minimo = 110.0; // por debajo de esto, a simple vista se confunden
    if (kit_dist($l['c1'], $v['c1']) < $minimo) {
        $alterno = [[$v['c2'], $v['c1']], ['#f8fafc', '#0f172a'], ['#111827', '#ffffff']];
        foreach ($alterno as [$a1, $a2]) {
            if (kit_dist($l['c1'], $a1) >= $minimo) {
                $v = kit_armar($a1, $a2);
                break;
            }
        }
    }
    return ['home' => $l, 'away' => $v];
}

/**
 * Camiseta de arquero: amarillo/naranja/verde/violeta, el primero que no se parezca a la del equipo.
 *
 * @param array{c1:string,c2:string,tx:string} $kitEquipo
 * @return array{c1:string,c2:string,tx:string}
 */
function kit_portero(array $kitEquipo, bool $local): array
{
    $opciones = $local ? ['#facc15', '#f97316', '#a855f7'] : ['#f97316', '#facc15', '#a855f7'];
    foreach ($opciones as $c) {
        if (kit_dist($c, $kitEquipo['c1']) >= 110.0 && kit_dist($c, '#16a34a') >= 90.0) {
            return kit_armar($c, '#111827');
        }
    }
    return kit_armar('#a855f7', '#111827');
}

/** SVG de la camiseta; los colores salen de --k1 (cuerpo) y --k2 (mangas y cuello). Ver .pl-shirt en partido.css. */
function kit_svg(): string
{
    return '<svg viewBox="0 0 64 60" aria-hidden="true" focusable="false">'
        . '<path class="k1" d="M20 2C23 9 41 9 44 2L56 8L63 24L53 29L49 22L49 58L15 58L15 22L11 29L1 24L8 8Z"/>'
        . '<path class="k2" d="M8 8L20 2L15 22L11 29L1 24Z"/>'
        . '<path class="k2" d="M56 8L44 2L49 22L53 29L63 24Z"/>'
        . '<path class="kc" d="M20 2C23 9 41 9 44 2"/>'
        . '<path class="ko" d="M20 2C23 9 41 9 44 2L56 8L63 24L53 29L49 22L49 58L15 58L15 22L11 29L1 24L8 8Z"/>'
        . '</svg>';
}
