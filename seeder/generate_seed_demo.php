<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Acceso denegado: este script solo puede ejecutarse por CLI.\n");
}

/**
 * Script generador de database/seed_demo.sql.
 * Genera el hash de contraseña seguro con password_hash() por CLI.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$adminPassword = $argv[1] ?? 'AdminGoalBet2026!';
$adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);

$sql = <<<SQL
-- ==============================================================================
-- GoalBet2026 - Seed de Datos Iniciales / Demo
-- Deportes, países, ligas principales, ligas_sync y usuario administrador inicial
-- (Contraseñas con hash seguro BCRYPT generado por CLI - SIN contraseñas en texto claro)
-- ==============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. DEPORTES BASE
INSERT INTO `deportes` (`id`, `nombre`, `slug`, `tiene_empate`, `unidad_marcador`, `icono`) VALUES
(1, 'Fútbol', 'futbol', 1, 'goles', 'bi-trophy'),
(2, 'Baloncesto', 'baloncesto', 0, 'puntos', 'bi-dribbble'),
(3, 'Béisbol', 'beisbol', 0, 'carreras', 'bi-record-circle'),
(4, 'Fútbol Americano', 'futbol-americano', 0, 'puntos', 'bi-shield-shaded')
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`), `tiene_empate` = VALUES(`tiene_empate`), `icono` = VALUES(`icono`);

-- 2. PAÍSES BASE
INSERT INTO `paises` (`id`, `nombre`, `codigo_iso2`, `confederacion`, `bandera_url`) VALUES
(1, 'Inglaterra', 'GB', 'UEFA', 'https://upload.wikimedia.org/wikipedia/en/a/ae/Flag_of_the_United_Kingdom.svg'),
(2, 'España', 'ES', 'UEFA', 'https://upload.wikimedia.org/wikipedia/en/9/9a/Flag_of_Spain.svg'),
(3, 'Italia', 'IT', 'UEFA', 'https://upload.wikimedia.org/wikipedia/en/0/03/Flag_of_Italy.svg'),
(4, 'Alemania', 'DE', 'UEFA', 'https://upload.wikimedia.org/wikipedia/en/b/ba/Flag_of_Germany.svg'),
(5, 'Francia', 'FR', 'UEFA', 'https://upload.wikimedia.org/wikipedia/en/c/c3/Flag_of_France.svg'),
(6, 'Estados Unidos', 'US', 'CONCACAF', 'https://upload.wikimedia.org/wikipedia/en/a/a4/Flag_of_the_United_States.svg'),
(7, 'Argentina', 'AR', 'CONMEBOL', 'https://upload.wikimedia.org/wikipedia/commons/1/1a/Flag_of_Argentina.svg'),
(8, 'Brasil', 'BR', 'CONMEBOL', 'https://upload.wikimedia.org/wikipedia/en/0/05/Flag_of_Brazil.svg'),
(9, 'Internacional', 'XX', 'FIFA', 'https://upload.wikimedia.org/wikipedia/commons/2/2f/Flag_of_the_United_Nations.svg')
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`), `bandera_url` = VALUES(`bandera_url`);

-- 3. LIGAS BASE
INSERT INTO `ligas_torneos` (`id`, `id_deporte`, `id_pais`, `nombre`, `temporada`, `tipo`, `logo`) VALUES
(1, 1, 1, 'Premier League', '2024-25', 'liga', 'https://media.api-sports.io/football/leagues/39.png'),
(2, 1, 2, 'La Liga', '2024-25', 'liga', 'https://media.api-sports.io/football/leagues/140.png'),
(3, 1, 3, 'Serie A', '2024-25', 'liga', 'https://media.api-sports.io/football/leagues/135.png'),
(4, 1, 4, 'Bundesliga', '2024-25', 'liga', 'https://media.api-sports.io/football/leagues/78.png'),
(5, 1, 5, 'Ligue 1', '2024-25', 'liga', 'https://media.api-sports.io/football/leagues/61.png'),
(9, 1, 8, 'Brasileirão Serie A', '2024', 'liga', 'https://media.api-sports.io/football/leagues/71.png'),
(18, 2, 6, 'NBA', '2024-25', 'liga', 'https://media.api-sports.io/basketball/leagues/12.png'),
(19, 3, 6, 'MLB', '2024', 'liga', 'https://media.api-sports.io/baseball/leagues/1.png'),
(20, 4, 6, 'NFL', '2024', 'liga', 'https://media.api-sports.io/american-football/leagues/1.png'),
(26, 1, 9, 'UEFA Champions League', '2024-25', 'torneo', 'https://media.api-sports.io/football/leagues/2.png'),
(28, 1, 7, 'Liga Profesional Argentina', '2024', 'liga', 'https://media.api-sports.io/football/leagues/128.png'),
(30, 1, 6, 'Major League Soccer (MLS)', '2024', 'liga', 'https://media.api-sports.io/football/leagues/253.png')
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`), `logo` = VALUES(`logo`);

-- 4. MAPEO SINCRONIZADOR ESPN (ligas_sync)
INSERT INTO `ligas_sync` (`id_liga`, `deporte`, `slug_espn`, `activa`) VALUES
(1, 'futbol', 'eng.1', 1),            -- Premier League
(2, 'futbol', 'esp.1', 1),            -- La Liga
(3, 'futbol', 'ita.1', 1),            -- Serie A
(4, 'futbol', 'ger.1', 1),            -- Bundesliga
(5, 'futbol', 'fra.1', 1),            -- Ligue 1
(9, 'futbol', 'bra.1', 1),            -- Brasileirão
(18, 'baloncesto', 'nba', 1),         -- NBA
(19, 'beisbol', 'mlb', 1),            -- MLB
(20, 'futbol-americano', 'nfl', 1),   -- NFL
(26, 'futbol', 'uefa.champions', 1),  -- Champions League
(28, 'futbol', 'arg.1', 1),           -- Liga Profesional Argentina
(30, 'futbol', 'usa.1', 1)            -- MLS
ON DUPLICATE KEY UPDATE `slug_espn` = VALUES(`slug_espn`), `activa` = VALUES(`activa`);

-- 5. USUARIO ADMINISTRADOR INICIAL (Hash generado por CLI - Password predeterminado de configuración: {$adminPassword})
INSERT INTO `usuarios` (`nombre`, `apellido`, `username`, `email`, `password`, `saldo`, `rol`, `activo`) VALUES
('Administrador', 'GoalBet', 'admin', 'admin@goalbet2026.com', '{$adminHash}', 10000.00, 'admin', 1)
ON DUPLICATE KEY UPDATE `rol` = 'admin', `activo` = 1;

SET FOREIGN_KEY_CHECKS = 1;
SQL;

$target = __DIR__ . '/../database/seed_demo.sql';
file_put_contents($target, $sql);

echo "Archivo database/seed_demo.sql generado con éxito.\n";
echo "Usuario administrador sembrado: admin / admin@goalbet2026.com\n";
echo "Hash BCRYPT generado: " . substr($adminHash, 0, 15) . "...\n";
