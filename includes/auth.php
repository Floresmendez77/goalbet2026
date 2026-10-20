<?php

declare(strict_types=1);

function usuario_actual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function esta_logueado(): bool
{
    return isset($_SESSION['usuario']);
}

function es_admin(): bool
{
    return esta_logueado() && $_SESSION['usuario']['rol'] === 'admin';
}

/** Exige sesión iniciada; si no, redirige a login. Llamar al tope de la página. */
function requerir_login(): void
{
    if (!esta_logueado()) {
        flash('warning', 'Iniciá sesión para continuar.');
        redirect('/login.php');
    }
}

/** Exige rol admin; si no, corta con 403. Llamar al tope de páginas de admin/. */
function requerir_admin(): void
{
    requerir_login();
    if (!es_admin()) {
        http_response_code(403);
        flash('danger', 'No tenés permisos para acceder a esa sección.');
        redirect('/dashboard.php');
    }
}

/**
 * Refresca los datos del usuario logueado en sesión (ej. después de que
 * cambie su saldo) para que el navbar y las páginas muestren el valor real
 * sin forzar al usuario a volver a loguearse.
 */
function refrescar_usuario_sesion(): void
{
    if (!esta_logueado()) {
        return;
    }
    $stmt = db()->prepare('SELECT id, nombre, apellido, username, email, saldo, rol, activo FROM usuarios WHERE id = ?');
    $stmt->execute([$_SESSION['usuario']['id']]);
    $usuario = $stmt->fetch();
    if ($usuario) {
        $_SESSION['usuario'] = $usuario;
    }
}

/**
 * Obtiene la dirección IP del cliente, considerando encabezados de proxy si aplican.
 */
function obtener_ip_cliente(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $partes = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        $primera = trim($partes[0]);
        if (filter_var($primera, FILTER_VALIDATE_IP)) {
            return substr($primera, 0, 45);
        }
    }
    return substr((string) $ip, 0, 45);
}

/**
 * Limpia registros de intentos de login con antigüedad mayor a $horas (por defecto 24h).
 */
function limpiar_intentos_login_antiguos(int $horas = 24): void
{
    try {
        $stmt = db()->prepare('DELETE FROM login_intentos WHERE creado_at < (NOW() - INTERVAL ? HOUR)');
        $stmt->execute([$horas]);
    } catch (Throwable) {
        // Silencioso si la tabla no existe o falla temporalmente
    }
}

/**
 * Retorna la cantidad de intentos fallidos en los últimos $minutos para un identificador e IP dados.
 */
function contar_fallos_login_recientes(string $identificador, string $ip, int $minutos = 15): int
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM login_intentos 
             WHERE identificador = ? AND ip = ? AND exitoso = 0 
               AND creado_at >= (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->execute([$identificador, $ip, $minutos]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Registra un intento de login en la tabla login_intentos.
 */
function registrar_intento_login(string $identificador, string $ip, bool $exitoso): void
{
    try {
        $stmt = db()->prepare('INSERT INTO login_intentos (identificador, ip, exitoso, creado_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$identificador, $ip, $exitoso ? 1 : 0]);
    } catch (Throwable) {
        // Silencioso
    }
}

/**
 * Limpia los intentos fallidos acumulados tras un login exitoso.
 */
function reiniciar_intentos_fallidos(string $identificador, string $ip): void
{
    try {
        $stmt = db()->prepare('DELETE FROM login_intentos WHERE identificador = ? AND ip = ? AND exitoso = 0');
        $stmt->execute([$identificador, $ip]);
    } catch (Throwable) {
        // Silencioso
    }
}

