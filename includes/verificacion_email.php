<?php

/**
 * Verificación de correo electrónico.
 * Los tokens se guardan HASHEADOS (sha256) en `email_verificaciones`: si alguien ve la
 * base de datos, no puede armar enlaces de confirmación.
 */

declare(strict_types=1);

const VERIFICACION_HORAS_VALIDEZ = 24;
const REENVIO_SEGUNDOS_ESPERA    = 60;

/** ¿Se exige correo verificado para iniciar sesión? (EMAIL_VERIFY_REQUIRED=0 lo desactiva). */
function email_verificacion_requerida(): bool
{
    $v = strtolower(trim((string) Env::get('EMAIL_VERIFY_REQUIRED', '1')));
    return !in_array($v, ['0', 'false', 'no', 'off'], true);
}

/** Genera un token nuevo, invalida los anteriores del usuario y devuelve el token en claro. */
function crear_token_verificacion(int $idUsuario, string $email, string $tipo = 'registro'): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('UPDATE email_verificaciones SET utilizado = 1 WHERE id_usuario = ? AND tipo = ? AND utilizado = 0')
        ->execute([$idUsuario, $tipo]);
    db()->prepare(
        'INSERT INTO email_verificaciones (id_usuario, email, tipo, token_hash, expira_at, utilizado)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . VERIFICACION_HORAS_VALIDEZ . ' HOUR, 0)'
    )->execute([$idUsuario, $email, $tipo, hash('sha256', $token)]);
    return $token;
}

/** ¿Pasó el tiempo mínimo desde el último envío (anti-spam)? */
function puede_enviar_verificacion(int $idUsuario): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM email_verificaciones
         WHERE id_usuario = ? AND created_at > (NOW() - INTERVAL ' . REENVIO_SEGUNDOS_ESPERA . ' SECOND)'
    );
    $stmt->execute([$idUsuario]);
    return (int) $stmt->fetchColumn() === 0;
}

/**
 * Crea el token y manda el correo de confirmación.
 * @return array{enviado:bool, enlace:string}  `enlace` solo sirve para mostrarlo en modo local.
 */
function enviar_verificacion_a_usuario(array $usuario): array
{
    $token  = crear_token_verificacion((int) $usuario['id'], (string) $usuario['email']);
    $enlace = url_publica('/verificar_email.php?token=' . $token);
    return ['enviado' => correo_verificacion($usuario, $enlace), 'enlace' => $enlace];
}

/**
 * Confirma un token. Idempotente: si el correo ya estaba verificado devuelve ok (algunos
 * antivirus/clientes de correo abren el enlace antes que la persona).
 *
 * Si el token es de tipo «cambio» (el usuario pidió cambiar su correo desde el perfil), al
 * confirmarlo el correo de la cuenta pasa a ser el nuevo y se avisa al correo anterior.
 *
 * @return array{ok:bool, ya_verificado:bool, error:?string, cambio:bool}
 */
function confirmar_verificacion_email(string $token): array
{
    $fallo = static fn (string $m): array => ['ok' => false, 'ya_verificado' => false, 'error' => $m, 'cambio' => false];

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return $fallo('El enlace de confirmación no es válido.');
    }

    $pdo  = db();
    $stmt = $pdo->prepare(
        'SELECT v.id, v.id_usuario, v.tipo, v.email AS email_token, v.utilizado, (v.expira_at < NOW()) AS vencido,
                u.id AS uid, u.nombre, u.username, u.email, u.email_verificado
         FROM email_verificaciones v JOIN usuarios u ON u.id = v.id_usuario
         WHERE v.token_hash = ? LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return $fallo('El enlace de confirmación no es válido.');
    }
    if ($fila['tipo'] === 'cambio') {
        return confirmar_cambio_email($pdo, $fila);
    }
    if ((int) $fila['email_verificado'] === 1 && strcasecmp((string) $fila['email'], (string) $fila['email_token']) === 0) {
        return ['ok' => true, 'ya_verificado' => true, 'error' => null, 'cambio' => false];
    }
    if ((int) $fila['utilizado'] === 1) {
        return $fallo('Este enlace ya fue reemplazado por uno más reciente. Revisá tu correo o pedí uno nuevo.');
    }
    if ((int) $fila['vencido'] === 1) {
        return $fallo('El enlace venció. Pedí uno nuevo para confirmar tu correo.');
    }
    if (strcasecmp((string) $fila['email'], (string) $fila['email_token']) !== 0) {
        return $fallo('Este enlace corresponde a un correo que ya no está asociado a la cuenta.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE usuarios SET email_verificado = 1, email_verificado_at = NOW() WHERE id = ?')
            ->execute([(int) $fila['uid']]);
        $pdo->prepare('UPDATE email_verificaciones SET utilizado = 1 WHERE id_usuario = ?')
            ->execute([(int) $fila['uid']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[verificacion] ' . $e->getMessage());
        return $fallo('No pudimos confirmar tu correo. Intentá de nuevo en unos minutos.');
    }

    correo_bienvenida(['nombre' => $fila['nombre'], 'username' => $fila['username'], 'email' => $fila['email']]);
    return ['ok' => true, 'ya_verificado' => false, 'error' => null, 'cambio' => false];
}


/** Crea el token de cambio de correo y manda el enlace al correo NUEVO. @return array{enviado:bool, enlace:string} */
function enviar_cambio_email(array $usuario, string $nuevoEmail): array
{
    $token  = crear_token_verificacion((int) $usuario['id'], $nuevoEmail, 'cambio');
    $enlace = url_publica('/verificar_email.php?token=' . $token);
    return ['enviado' => correo_cambio_email($usuario, $nuevoEmail, $enlace), 'enlace' => $enlace];
}

/** ¿Hay un cambio de correo pendiente (sin usar y sin vencer)? Devuelve el correo nuevo o null. */
function cambio_email_pendiente(int $idUsuario): ?string
{
    $st = db()->prepare(
        "SELECT email FROM email_verificaciones
         WHERE id_usuario = ? AND tipo = 'cambio' AND utilizado = 0 AND expira_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$idUsuario]);
    $e = $st->fetchColumn();
    return $e === false ? null : (string) $e;
}

function cancelar_cambio_email(int $idUsuario): void
{
    db()->prepare("UPDATE email_verificaciones SET utilizado = 1 WHERE id_usuario = ? AND tipo = 'cambio' AND utilizado = 0")
        ->execute([$idUsuario]);
}

/** Parte «cambio» de confirmar_verificacion_email(). */
function confirmar_cambio_email(PDO $pdo, array $fila): array
{
    $fallo = static fn (string $m): array => ['ok' => false, 'ya_verificado' => false, 'error' => $m, 'cambio' => true];
    $nuevo = (string) $fila['email_token'];
    $viejo = (string) $fila['email'];

    if ((int) $fila['utilizado'] === 1) {
        if (strcasecmp($viejo, $nuevo) === 0) { // el enlace ya se usó y el cambio quedó hecho
            return ['ok' => true, 'ya_verificado' => true, 'error' => null, 'cambio' => true];
        }
        return $fallo('Este enlace ya fue usado o reemplazado por uno más reciente.');
    }
    if ((int) $fila['vencido'] === 1) {
        return $fallo('El enlace venció. Pedí el cambio de correo de nuevo desde tu perfil.');
    }

    $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1');
    $st->execute([$nuevo, (int) $fila['uid']]);
    if ($st->fetch()) {
        return $fallo('Ese correo ya fue registrado por otra cuenta.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE usuarios SET email = ?, email_verificado = 1, email_verificado_at = NOW() WHERE id = ?')
            ->execute([$nuevo, (int) $fila['uid']]);
        $pdo->prepare("UPDATE email_verificaciones SET utilizado = 1 WHERE id_usuario = ? AND tipo = 'cambio'")
            ->execute([(int) $fila['uid']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[verificacion-cambio] ' . $e->getMessage());
        return $fallo('No pudimos cambiar tu correo. Intentá de nuevo en unos minutos.');
    }

    // Aviso de seguridad al correo anterior.
    correo_email_cambiado(['nombre' => $fila['nombre'], 'username' => $fila['username'], 'email' => $viejo], $nuevo);
    return ['ok' => true, 'ya_verificado' => false, 'error' => null, 'cambio' => true];
}
