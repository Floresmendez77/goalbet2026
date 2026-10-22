<?php

/**
 * Plantillas de los avisos (favoritos, apuestas, alineaciones). Usan correo_layout() de mail_templates.php.
 * Todas devuelven bool y no lanzan excepciones (enviar_correo las atrapa).
 */

declare(strict_types=1);

/** Pie común: motivo del aviso + enlace para darse de baja. */
function _aviso_pie(string $motivo, string $urlBaja): string
{
    return e($motivo) . ' <a href="' . e($urlBaja) . '" style="color:#888">Dejar de recibir avisos</a>';
}

/** Tabla de dos columnas etiqueta / valor. */
function _aviso_tabla(array $filas): string
{
    $h = '<table role="presentation" cellspacing="0" cellpadding="6" style="font-size:14px;border:1px solid #eee;border-radius:6px;width:100%;margin:10px 0">';
    foreach ($filas as [$k, $v]) {
        $h .= '<tr><td style="color:#888;vertical-align:top">' . e($k) . '</td><td align="right">' . $v . '</td></tr>';
    }
    return $h . '</table>';
}

function _aviso_pick(string $tipo, string $local, string $visita): string
{
    return match ($tipo) {
        'local'  => 'Gana ' . $local,
        'visita' => 'Gana ' . $visita,
        default  => 'Empate',
    };
}

function _aviso_cuando(array $p): string
{
    return fecha_partido_legible($p['fecha_partido'], 'd/m/Y h:i A') . ' (' . app_tz_etiqueta() . ')';
}

function _aviso_url_partido(array $p): string
{
    return url_publica('/partido.php?id=' . (int) $p['id']);
}

/** Recordatorio 3 días / 1 día / 3 horas antes. */
function correo_recordatorio_partido(array $u, array $p, string $tipo, string $cuando, array $pendientes, string $urlBaja): bool
{
    $favoritos = implode(' y ', array_unique($u['favoritos']));
    $filas = [
        ['Partido', '<strong>' . e($p['local']) . ' vs ' . e($p['visita']) . '</strong>'],
        ['Cuándo', e(_aviso_cuando($p))],
    ];
    if (!empty($p['liga'])) {
        $filas[] = ['Competición', e($p['liga'])];
    }
    if (!empty($p['estadio'])) {
        $filas[] = ['Estadio', e($p['estadio'])];
    }
    $cuotas = 'Local ' . number_format((float) $p['cuota_local'], 2)
        . ($p['cuota_empate'] !== null ? ' · Empate ' . number_format((float) $p['cuota_empate'], 2) : '')
        . ' · Visita ' . number_format((float) $p['cuota_visita'], 2);
    $filas[] = ['Cuotas', e($cuotas)];

    $extra = '';
    if ($pendientes) {
        $extra .= '<p style="margin:14px 0 4px"><strong>Tus apuestas pendientes en este partido:</strong></p><ul style="margin:0;padding-left:18px">';
        foreach ($pendientes as $a) {
            $extra .= '<li>' . e(_aviso_pick($a['tipo_apuesta'], $p['local'], $p['visita'])) . ' · ' . e(money($a['monto'])) . ' a cuota ' . e(number_format((float) $a['cuota'], 2)) . '</li>';
        }
        $extra .= '</ul>';
    } else {
        $extra .= '<p style="margin-top:14px">Todavía podés apostar hasta que empiece el partido.</p>';
    }
    if ($tipo !== 'rec_3h') {
        $extra .= '<p style="color:#888;font-size:13px">Cuando se publique el 11 titular te lo mandamos por correo.</p>';
    }

    $titulos = ['rec_3d' => 'Tu equipo juega en 3 días', 'rec_1d' => 'Tu equipo juega mañana', 'rec_3h' => 'Tu equipo juega en unas horas'];
    $asuntos = ['rec_3d' => '📅 En 3 días: ', 'rec_1d' => '⏰ Mañana: ', 'rec_3h' => '🔔 Hoy: '];

    $html = correo_layout(
        $titulos[$tipo] ?? 'Tu equipo juega pronto',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, <strong>' . e($favoritos) . '</strong> juega ' . e($cuando) . '.</p>' . _aviso_tabla($filas) . $extra,
        'Ver partido y apostar',
        _aviso_url_partido($p),
        _aviso_pie('Recibís este aviso porque ' . $favoritos . ' está en tus equipos favoritos.', $urlBaja)
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), ($asuntos[$tipo] ?? '') . $p['local'] . ' vs ' . $p['visita'], $html);
}

/** Alineación titular. $secciones = [['equipo','favorito','jugadores'=>[nombre,dorsal,posicion]]]. */
function correo_alineacion(array $u, array $p, array $secciones, string $urlBaja): bool
{
    $cuerpo = '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, ya se publicó el 11 titular de <strong>' . e($p['local']) . ' vs ' . e($p['visita']) . '</strong> ('
        . e(_aviso_cuando($p)) . ').</p>';
    foreach ($secciones as $s) {
        $cuerpo .= '<p style="margin:16px 0 4px;font-size:16px"><strong>' . ($s['favorito'] ? '⭐ ' : '') . e($s['equipo']) . '</strong></p>'
            . '<table role="presentation" cellspacing="0" cellpadding="4" style="font-size:14px;width:100%;border-top:1px solid #eee">';
        foreach ($s['jugadores'] as $j) {
            $cuerpo .= '<tr><td style="width:36px;color:#b08900;font-weight:bold">' . e((string) ($j['dorsal'] ?? '')) . '</td>'
                . '<td>' . e($j['nombre']) . '</td><td align="right" style="color:#888">' . e((string) ($j['posicion'] ?? '')) . '</td></tr>';
        }
        $cuerpo .= '</table>';
    }
    $html = correo_layout(
        '11 titular confirmado',
        $cuerpo,
        'Ver partido y apostar',
        _aviso_url_partido($p),
        _aviso_pie('Recibís este aviso porque seguís a este equipo.', $urlBaja)
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), '📋 11 titular: ' . $p['local'] . ' vs ' . $p['visita'], $html);
}

/** Resultado final + lo que pasó con las apuestas simples del usuario en ese partido. */
function correo_resultado_partido(array $u, array $p, array $apuestas, float $neto, float $saldo, string $urlBaja): bool
{
    $ml = (int) $p['marcador_local'];
    $mv = (int) $p['marcador_visita'];
    $marcador = $p['local'] . ' ' . $ml . ' - ' . $mv . ' ' . $p['visita'];
    $ganador = $ml > $mv ? 'local' : ($ml < $mv ? 'visita' : 'empate');

    $cuerpo = '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, terminó el partido:</p>'
        . '<p style="font-size:20px;font-weight:bold;margin:8px 0;text-align:center">' . e($marcador) . '</p>';

    $favoritos = array_unique($u['favoritos'] ?? []);
    foreach ($favoritos as $fav) {
        $esLocal = $fav === $p['local'];
        $res = $ganador === 'empate' ? 'empató' : (($ganador === 'local') === $esLocal ? 'ganó' : 'perdió');
        $cuerpo .= '<p style="margin:4px 0">⭐ Tu equipo <strong>' . e($fav) . '</strong> ' . $res . '.</p>';
    }

    $asunto = 'Final: ' . $marcador;
    if ($apuestas) {
        $cuerpo .= '<p style="margin:16px 0 4px"><strong>Tus apuestas en este partido</strong></p>'
            . '<table role="presentation" cellspacing="0" cellpadding="6" style="font-size:14px;width:100%;border:1px solid #eee">';
        foreach ($apuestas as $a) {
            $gano = $a['estado'] !== 'perdida';
            $linea = $gano
                ? '<span style="color:#198754;font-weight:bold">Ganaste ' . e(money((float) $a['ganancia_potencial'] - (float) $a['monto'])) . '</span>'
                : '<span style="color:#dc3545;font-weight:bold">Perdiste ' . e(money($a['monto'])) . '</span>';
            $cuerpo .= '<tr><td>' . e(_aviso_pick($a['tipo_apuesta'], $p['local'], $p['visita'])) . '<br><span style="color:#888;font-size:12px">'
                . e(money($a['monto'])) . ' a cuota ' . e(number_format((float) $a['cuota'], 2)) . '</span></td><td align="right">' . $linea . '</td></tr>';
        }
        $cuerpo .= '</table>';
        $cuerpo .= '<p style="margin:12px 0 0">Balance de este partido: <strong style="color:' . ($neto >= 0 ? '#198754' : '#dc3545') . '">'
            . ($neto >= 0 ? '+' : '-') . e(money(abs($neto))) . '</strong> · Saldo actual: <strong>' . e(money($saldo)) . '</strong></p>';
        $asunto = $neto > 0 ? '🎉 Ganaste ' . money($neto) . ' · ' . $marcador
            : ($neto < 0 ? 'Tu apuesta perdió · ' . $marcador : 'Apuesta resuelta · ' . $marcador);
    }

    $motivo = $apuestas && $favoritos ? 'Recibís este aviso por tus apuestas y tus equipos favoritos.'
        : ($apuestas ? 'Recibís este aviso porque apostaste en este partido.' : 'Recibís este aviso porque seguís a este equipo.');
    $html = correo_layout(
        $apuestas ? 'Resultado de tu apuesta' : 'Resultado final',
        $cuerpo,
        $apuestas ? 'Ver mis apuestas' : 'Ver partido',
        $apuestas ? url_publica('/mis_apuestas.php') : _aviso_url_partido($p),
        _aviso_pie($motivo, $urlBaja)
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), $asunto, $html);
}

/** Reembolso por partido cancelado / pospuesto / suspendido. */
function correo_reembolso(array $u, array $p, float $monto, int $n, string $urlBaja): bool
{
    $estado = ['cancelado' => 'cancelado', 'pospuesto' => 'pospuesto', 'suspendido' => 'suspendido'][$p['estado']] ?? 'cancelado';
    $html = correo_layout(
        'Te devolvimos tu apuesta',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, el partido <strong>' . e($p['local']) . ' vs ' . e($p['visita']) . '</strong> fue <strong>' . e($estado) . '</strong>.</p>'
        . '<p>Reembolsamos ' . ($n === 1 ? 'tu apuesta' : 'tus ' . $n . ' apuestas') . ' por un total de <strong>' . e(money($monto)) . '</strong>. El saldo ya está disponible en tu cuenta.</p>',
        'Ver mis apuestas',
        url_publica('/mis_apuestas.php'),
        _aviso_pie('Recibís este aviso porque apostaste en este partido.', $urlBaja)
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), 'Reembolso: ' . $p['local'] . ' vs ' . $p['visita'] . ' (' . $estado . ')', $html);
}

/** Boleto combinado resuelto. */
function correo_boleto(array $u, array $b, array $patas, float $saldo, string $urlBaja): bool
{
    $estado = (string) $b['estado'];
    $filas = '';
    foreach ($patas as $pt) {
        $marca = $pt['estado'] === 'ganada' ? '✅' : ($pt['estado'] === 'perdida' ? '❌' : '↩️');
        $res = $pt['marcador_local'] !== null ? ' (' . (int) $pt['marcador_local'] . '-' . (int) $pt['marcador_visita'] . ')' : '';
        $filas .= '<tr><td>' . $marca . ' ' . e($pt['local']) . ' vs ' . e($pt['visita']) . e($res) . '<br><span style="color:#888;font-size:12px">'
            . e(_aviso_pick($pt['tipo_apuesta'], $pt['local'], $pt['visita'])) . ' · cuota ' . e(number_format((float) $pt['cuota'], 2)) . '</span></td></tr>';
    }
    $tabla = '<table role="presentation" cellspacing="0" cellpadding="6" style="font-size:14px;width:100%;border:1px solid #eee">' . $filas . '</table>';

    if ($estado === 'ganado') {
        $titulo = '¡Ganaste tu combinada! 🎉';
        $resumen = '<p>Acertaste todas las selecciones. Ganancia: <strong style="color:#198754">' . e(money($b['ganancia_potencial'])) . '</strong>'
            . ' (apostaste ' . e(money($b['monto'])) . ' a cuota total ' . e(number_format((float) $b['cuota_total'], 2)) . ').</p>';
        $asunto = '🎉 Ganaste ' . money($b['ganancia_potencial']) . ' con tu combinada';
    } elseif ($estado === 'perdido') {
        $titulo = 'Tu combinada no se dio';
        $resumen = '<p>Al menos una selección falló, así que el boleto de <strong>' . e(money($b['monto'])) . '</strong> se perdió. ¡La próxima será!</p>';
        $asunto = 'Tu combinada no se dio · ' . $b['referencia'];
    } else {
        $titulo = 'Te devolvimos tu combinada';
        $resumen = '<p>Uno de los partidos fue cancelado o pospuesto, por eso anulamos el boleto y te devolvimos <strong>' . e(money($b['monto'])) . '</strong>.</p>';
        $asunto = 'Reembolso de tu combinada · ' . $b['referencia'];
    }

    $html = correo_layout(
        $titulo,
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, se resolvió tu boleto <span style="font-family:monospace">' . e((string) $b['referencia']) . '</span>.</p>'
        . $tabla . $resumen . '<p style="margin:0">Saldo actual: <strong>' . e(money($saldo)) . '</strong></p>',
        'Ver mis apuestas',
        url_publica('/mis_apuestas.php'),
        _aviso_pie('Recibís este aviso porque apostaste con este boleto.', $urlBaja)
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), $asunto, $html);
}
