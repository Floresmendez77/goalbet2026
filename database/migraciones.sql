-- Migraciones Idempotentes para GoalBet2026
-- Compatible con cPanel / phpMyAdmin / MAMP (sin DEFINER)

CREATE TABLE IF NOT EXISTS `ligas_sync` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_liga` INT NOT NULL,
  `deporte` VARCHAR(50) NOT NULL,
  `slug_espn` VARCHAR(100) NOT NULL,
  `activa` TINYINT(1) DEFAULT 1,
  UNIQUE KEY `uk_liga` (`id_liga`),
  CONSTRAINT `fk_ligas_sync_liga` FOREIGN KEY (`id_liga`) REFERENCES `ligas_torneos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fecha` DATETIME NOT NULL,
  `usuario_admin` VARCHAR(100) DEFAULT 'Sistema/Cron',
  `liga` VARCHAR(100) NOT NULL,
  `resultados_actualizados` INT DEFAULT 0,
  `partidos_nuevos` INT DEFAULT 0,
  `errores` INT DEFAULT 0,
  `detalle` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_intentos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `identificador` VARCHAR(100) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `exitoso` TINYINT(1) DEFAULT 0,
  `creado_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_login_busqueda` (`identificador`, `ip`, `creado_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sembrado inicial de ligas activas sincronizables con ESPN
INSERT IGNORE INTO `ligas_sync` (`id_liga`, `deporte`, `slug_espn`, `activa`) VALUES
(1, 'futbol', 'eng.1', 1),            -- Premier League
(2, 'futbol', 'esp.1', 1),            -- La Liga
(3, 'futbol', 'ita.1', 1),            -- Serie A
(4, 'futbol', 'ger.1', 1),            -- Bundesliga
(5, 'futbol', 'fra.1', 1),            -- Ligue 1
(9, 'futbol', 'bra.1', 1),            -- Brasileirão
(26, 'futbol', 'uefa.champions', 1),  -- Champions League
(28, 'futbol', 'arg.1', 1),           -- Liga Profesional Argentina
(30, 'futbol', 'usa.1', 1),           -- MLS
(18, 'baloncesto', 'nba', 1),         -- NBA
(19, 'beisbol', 'mlb', 1),            -- MLB
(20, 'futbol-americano', 'nfl', 1);   -- NFL
