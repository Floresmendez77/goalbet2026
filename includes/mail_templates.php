<?php

/**
 * Plantillas de correo de GoalBet2026. Cada función arma el HTML y llama a enviar_correo().
 * Todas devuelven bool (true = enviado) y nunca lanzan excepciones.
 */

declare(strict_types=1);

/** Marco común de todos los correos (HTML con estilos en línea, compatible con Gmail/Outlook). */
function correo_layout(string $titulo, string $cuerpoHtml, ?string $btnTexto = null, ?string $btnUrl = null, string $pie = ''): string
{
    $boton = '';
    if ($btnTexto !== null && $btnUrl !== null) {
        $boton = '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0"><tr><td style="background:#ffc107;border-radius:6px">'
            . '<a href="' . e($btnUrl) . '" style="display:inline-block;padding:12px 28px;font-weight:bold;font-size:15px;color:#111;text-decoration:none">'
            . e($btnTexto) . '</a></td></tr></table>'
            . '<p style="font-size:12px;color:#888;margin:0 0 8px">Si el botón no funciona, copiá y pegá este enlace en tu navegador:<br>'
            . '<a href="' . e($btnUrl) . '" style="color:#b08900;word-break:break-all">' . e($btnUrl) . '</a></p>';
    }

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f2f3f5;font-family:Arial,Helvetica,sans-serif;color:#222">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f3f5;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden">'
        . '<tr><td style="background:#0f1115;padding:20px 28px"><span style="font-size:22px;font-weight:bold;color:#ffc107">⚽ GoalBet</span><span style="font-size:22px;color:#ffffff"> 2026</span></td></tr>'
        . '<tr><td style="padding:28px">'
        . '<h1 style="font-size:20px;margin:0 0 16px;color:#111">' . e($titulo) . '</h1>'
        . '<div style="font-size:15px;line-height:1.55">' . $cuerpoHtml . '</div>'
        . $boton
        . ($pie !== '' ? '<p style="font-size:12px;color:#888;margin:16px 0 0">' . $pie . '</p>' : '')
        . '</td></tr>'
        . '<tr><td style="background:#fafafa;padding:16px 28px;font-size:11px;color:#888;border-top:1px solid #eee">'
        . '<strong style="color:#c0392b">+18</strong> · Juego responsable: el juego con apuestas puede ser adictivo. '
        . 'GoalBet 2026 es un proyecto educativo; todos los saldos son simulados.'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function _correo_nombre(array $u): string
{
    return trim((string) ($u['nombre'] ?? '')) ?: (string) ($u['username'] ?? 'jugador');
}

/** Enlace de confirmación de email (registro). */
function correo_verificacion(array $u, string $urlVerificar): bool
{
    $html = correo_layout(
        'Confirmá tu correo',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, gracias por registrarte en GoalBet 2026.</p>'
        . '<p>Para activar tu cuenta necesitamos confirmar que este correo es tuyo. El enlace es válido por <strong>24 horas</strong>.</p>',
        'Confirmar mi correo',
        $urlVerificar,
        'Si vos no creaste esta cuenta, ignorá este mensaje: nadie podrá usarla sin confirmar el correo.'
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), 'Confirmá tu correo - GoalBet 2026', $html);
}

/** Bienvenida (se envía cuando el correo queda verificado). */
function correo_bienvenida(array $u): bool
{
    $html = correo_layout(
        '¡Bienvenido a GoalBet 2026!',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, tu cuenta <strong>@' . e((string) ($u['username'] ?? '')) . '</strong> ya está activa.</p>'
        . '<p>Para empezar:</p>'
        . '<ol style="padding-left:18px;margin:0 0 8px"><li>Pedí una recarga de saldo.</li><li>Cuando un administrador la apruebe, elegí tus partidos.</li><li>Armá tus apuestas simples o combinadas.</li></ol>'
        . '<p style="margin-top:12px">Recordá jugar con moderación: es un entorno simulado, pero el hábito cuenta.</p>',
        'Ir a GoalBet',
        url_publica('/dashboard.php')
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), '¡Bienvenido a GoalBet 2026!', $html);
}

/** Recuperación de contraseña. */
function correo_reset_password(array $u, string $urlReset, int $horasValidez = 1): bool
{
    $html = correo_layout(
        'Restablecer tu contraseña',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, recibimos una solicitud para restablecer la contraseña de tu cuenta.</p>'
        . '<p>El enlace es de un solo uso y vence en <strong>' . $horasValidez . ' hora' . ($horasValidez === 1 ? '' : 's') . '</strong>.</p>',
        'Crear nueva contraseña',
        $urlReset,
        'Si vos no pediste el cambio, ignorá este correo: tu contraseña actual sigue siendo la misma.'
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), 'Restablecer contraseña - GoalBet 2026', $html);
}

/** Aviso de contraseña cambiada. */
function correo_password_cambiada(array $u): bool
{
    $html = correo_layout(
        'Tu contraseña fue cambiada',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, te avisamos que la contraseña de tu cuenta se cambió el '
        . e(fecha_legible(date('Y-m-d H:i:s'))) . '.</p>'
        . '<p>Si fuiste vos, no tenés que hacer nada. Si no fuiste vos, pedí un nuevo restablecimiento de inmediato y contactá a un administrador.</p>',
        'Recuperar mi cuenta',
        url_publica('/recuperar.php')
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), 'Tu contraseña fue cambiada - GoalBet 2026', $html);
}

/** Recarga aprobada. $recarga = fila de `recargas`. */
function correo_recarga_aprobada(array $u, array $recarga, float $saldoNuevo): bool
{
    $html = correo_layout(
        'Recarga aprobada ✅',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, tu recarga fue <strong style="color:#198754">aprobada</strong> y el saldo ya está disponible.</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="6" style="font-size:14px;border:1px solid #eee;border-radius:6px;width:100%">'
        . '<tr><td style="color:#888">Monto acreditado</td><td align="right"><strong>' . e(money($recarga['monto'])) . '</strong></td></tr>'
        . '<tr><td style="color:#888">Método</td><td align="right">' . e((string) ($recarga['metodo'] ?? '—')) . '</td></tr>'
        . '<tr><td style="color:#888">Referencia</td><td align="right" style="font-family:monospace">' . e((string) $recarga['referencia']) . '</td></tr>'
        . '<tr><td style="color:#888">Saldo actual</td><td align="right"><strong>' . e(money($saldoNuevo)) . '</strong></td></tr>'
        . '</table>',
        'Ver partidos',
        url_publica('/partidos.php')
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), 'Recarga aprobada ' . $recarga['referencia'] . ' - GoalBet 2026', $html);
}

/** Recarga rechazada. */
function correo_recarga_rechazada(array $u, array $recarga, string $motivo): bool
{
    $html = correo_layout(
        'Recarga rechazada',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, lamentablemente tu solicitud de recarga fue <strong style="color:#dc3545">rechazada</strong>. No se acreditó saldo.</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="6" style="font-size:14px;border:1px solid #eee;border-radius:6px;width:100%">'
        . '<tr><td style="color:#888">Monto solicitado</td><td align="right"><strong>' . e(money($recarga['monto'])) . '</strong></td></tr>'
        . '<tr><td style="color:#888">Referencia</td><td align="right" style="font-family:monospace">' . e((string) $recarga['referencia']) . '</td></tr>'
        . '<tr><td style="color:#888;vertical-align:top">Motivo</td><td align="right">' . e($motivo) . '</td></tr>'
        . '</table>'
        . '<p style="margin-top:14px">Podés corregir el dato y enviar una nueva solicitud.</p>',
        'Nueva recarga',
        url_publica('/recargar.php')
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), 'Recarga rechazada ' . $recarga['referencia'] . ' - GoalBet 2026', $html);
}


/** Oculta parte del correo: juan.perez@gmail.com -> j*********@gmail.com */
function enmascarar_email(string $email): string
{
    [$local, $dominio] = array_pad(explode('@', $email, 2), 2, '');
    return $local === '' ? $email : mb_substr($local, 0, 1) . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $dominio;
}

/** Enlace para confirmar un correo NUEVO (se envía a la dirección nueva). $u['email'] = correo actual. */
function correo_cambio_email(array $u, string $nuevoEmail, string $urlConfirmar): bool
{
    $html = correo_layout(
        'Confirmá tu nuevo correo',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, pediste usar esta dirección como correo de tu cuenta <strong>@' . e((string) ($u['username'] ?? '')) . '</strong> en GoalBet 2026.</p>'
        . '<p>Tu correo actual no cambia hasta que confirmes. El enlace es válido por <strong>24 horas</strong>.</p>',
        'Confirmar nuevo correo',
        $urlConfirmar,
        'Si vos no pediste este cambio, ignorá este mensaje: no se va a modificar nada.'
    );
    return enviar_correo($nuevoEmail, _correo_nombre($u), 'Confirmá tu nuevo correo - GoalBet 2026', $html);
}

/** Aviso al correo ANTERIOR cuando el cambio ya se confirmó. */
function correo_email_cambiado(array $u, string $nuevoEmail): bool
{
    $html = correo_layout(
        'El correo de tu cuenta cambió',
        '<p>Hola <strong>' . e(_correo_nombre($u)) . '</strong>, el correo de tu cuenta de GoalBet 2026 se cambió a <strong>' . e(enmascarar_email($nuevoEmail)) . '</strong> el '
        . e(fecha_legible(date('Y-m-d H:i:s'))) . '.</p>'
        . '<p>Si fuiste vos, no tenés que hacer nada. Si no fuiste vos, contactá de inmediato a un administrador.</p>'
    );
    return enviar_correo((string) $u['email'], _correo_nombre($u), 'El correo de tu cuenta cambió - GoalBet 2026', $html);
}
