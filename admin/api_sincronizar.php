<?php

declare(strict_types=1);

// Captura cualquier salida accidental (el Logger del seeder hace echo,
// avisos de PHP, BOM, etc.) para que no ensucie el JSON de respuesta.
ob_start();
set_time_limit(300);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Sincronizador.php';
requerir_admin();

/**
 * Descarta todo lo que se haya impreso y envía solo el JSON.
 */
function responder_json(array $datos, int $codigo = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$accion = $_GET['action'] ?? $_POST['action'] ?? '';
$usuario = usuario_actual();
$adminNombre = $usuario ? ($usuario['username'] ?? 'Admin') : 'Admin';

try {
    $sincronizador = new Sincronizador();

    if ($accion === 'listar_ligas') {
        responder_json(['exito' => true, 'ligas' => $sincronizador->obtenerTodasLigas()]);
    }

    if ($accion === 'sincronizar_liga') {
        verificar_csrf();
        $idLiga = (int) ($_POST['id_liga'] ?? 0);
        $desde  = !empty($_POST['desde']) ? (string) $_POST['desde'] : null;
        $hasta  = !empty($_POST['hasta']) ? (string) $_POST['hasta'] : null;

        if ($idLiga <= 0) {
            responder_json(['exito' => false, 'error' => 'ID de liga inválido'], 400);
        }

        $res = $sincronizador->sincronizarLiga($idLiga, $desde, $hasta, $adminNombre, true);
        responder_json($res);
    }

    if ($accion === 'toggle_activa') {
        verificar_csrf();
        $idLiga = (int) ($_POST['id_liga'] ?? 0);
        $activa = (int) ($_POST['activa'] ?? 0) === 1 ? 1 : 0;

        $stmt = db()->prepare('UPDATE ligas_sync SET activa = ? WHERE id_liga = ?');
        $stmt->execute([$activa, $idLiga]);

        responder_json(['exito' => true, 'id_liga' => $idLiga, 'activa' => $activa]);
    }

    responder_json(['exito' => false, 'error' => 'Acción desconocida'], 400);
} catch (Throwable $e) {
    responder_json(['exito' => false, 'error' => $e->getMessage()], 500);
}