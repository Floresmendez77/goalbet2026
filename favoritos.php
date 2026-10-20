<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/calendario.php';
requerir_login();

/**
 * Acciones sobre equipos favoritos y preferencias del calendario (solo POST + CSRF).
 *   accion=agregar  id_equipo=N
 *   accion=quitar   id_equipo=N
 *   accion=omitir   (no volver a preguntar por el equipo favorito)
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/partidos.php');
}
verificar_csrf();

$pdo = db();
$uid = (int) usuario_actual()['id'];

if (!cal_tablas_listas($pdo)) {
    flash('warning', 'Falta crear las tablas de favoritos (database/favoritos.sql).');
    redirect('/partidos.php?vista=lista');
}

$accion = (string) ($_POST['accion'] ?? '');
$idEquipo = (int) ($_POST['id_equipo'] ?? 0);
$destino = '/partidos.php?vista=calendario';

$nombreEquipo = static function (PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT nombre FROM equipos WHERE id = ?');
    $st->execute([$id]);
    return (string) ($st->fetchColumn() ?: 'El equipo');
};

switch ($accion) {
    case 'agregar':
        if ($idEquipo > 0 && cal_agregar_favorito($pdo, $uid, $idEquipo)) {
            flash('success', $nombreEquipo($pdo, $idEquipo) . ' se agregó a tus equipos favoritos.');
            $destino .= '&equipo=' . $idEquipo;
        } else {
            flash('danger', 'No se pudo agregar ese equipo.');
        }
        break;

    case 'quitar':
        if ($idEquipo > 0) {
            $nombre = $nombreEquipo($pdo, $idEquipo);
            cal_quitar_favorito($pdo, $uid, $idEquipo);
            flash('info', $nombre . ' ya no está en tus favoritos.');
        }
        break;

    case 'omitir':
        cal_guardar_preferencia($pdo, $uid, 'favoritos_omitido', 1);
        flash('info', 'Listo, no te lo vuelvo a preguntar. Podés elegir tu equipo cuando quieras con «Agregar equipo».');
        break;

    default:
        flash('danger', 'Acción no válida.');
}

redirect($destino);