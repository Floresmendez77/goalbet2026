<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cuotas.php';

$pdo = db();

echo "Probando cuotas Fútbol (con empate):\n";
$cuotasFutbol = calcular_cuotas_equipos($pdo, 1, 1, 2, true);
print_r($cuotasFutbol);

echo "\nProbando cuotas Baloncesto (sin empate):\n";
$cuotasBasket = calcular_cuotas_equipos($pdo, 18, 50, 51, false);
print_r($cuotasBasket);

echo "\nAsignando cuotas a partidos pendientes:\n";
$act = asignar_cuotas_partidos_pendientes($pdo);
echo "Total partidos actualizados con cuotas realistas: $act\n";
