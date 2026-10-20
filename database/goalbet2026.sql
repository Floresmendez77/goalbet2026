-- ==============================================================================
-- GoalBet2026 - Esquema Completo y Limpio de Base de Datos para Producción
-- Compatible con MySQL 8.0+ / MariaDB 10.4+ / AlwaysData / phpMyAdmin
-- NOTA: Todos los Stored Procedures y Triggers están definidos SIN cláusula DEFINER
-- para garantizar máxima portabilidad e idempotencia en entornos de hosting compartido.
-- ==============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- --------------------------------------------------------
-- 1. USUARIOS & AUTENTICACIÓN
-- --------------------------------------------------------
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `saldo` decimal(12,2) NOT NULL DEFAULT '0.00',
  `rol` enum('admin','cliente') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cliente',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_username` (`username`),
  UNIQUE KEY `uq_usuarios_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `login_intentos`;
CREATE TABLE `login_intentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `identificador` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exitoso` tinyint(1) DEFAULT '0',
  `creado_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_busqueda` (`identificador`,`ip`,`creado_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expira_at` datetime NOT NULL,
  `utilizado` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_token` (`token`),
  KEY `idx_password_usuario` (`id_usuario`),
  CONSTRAINT `fk_password_resets_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. DOMINIO DEPORTIVO BASE (Deportes, Países, Ligas, Estadios)
-- --------------------------------------------------------
DROP TABLE IF EXISTS `deportes`;
CREATE TABLE `deportes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tiene_empate` tinyint(1) NOT NULL DEFAULT '1',
  `unidad_marcador` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'goles',
  `icono` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deporte_nombre` (`nombre`),
  UNIQUE KEY `uq_deporte_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `paises`;
CREATE TABLE `paises` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo_iso2` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confederacion` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bandera_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pais_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ligas_torneos`;
CREATE TABLE `ligas_torneos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_deporte` int(11) NOT NULL,
  `id_pais` int(11) DEFAULT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `temporada` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo` enum('liga','torneo','copa','amistoso') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'liga',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_liga` (`id_deporte`,`id_pais`,`nombre`,`temporada`),
  KEY `idx_liga_pais` (`id_pais`),
  CONSTRAINT `fk_ligas_deporte` FOREIGN KEY (`id_deporte`) REFERENCES `deportes` (`id`),
  CONSTRAINT `fk_ligas_pais` FOREIGN KEY (`id_pais`) REFERENCES `paises` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `estadios`;
CREATE TABLE `estadios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ciudad` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_pais` int(11) DEFAULT NULL,
  `capacidad` int(11) DEFAULT '0',
  `imagen` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitud` decimal(9,6) DEFAULT NULL,
  `longitud` decimal(9,6) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estadio_pais` (`id_pais`),
  CONSTRAINT `fk_estadios_pais` FOREIGN KEY (`id_pais`) REFERENCES `paises` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. EQUIPOS & JUGADORES
-- --------------------------------------------------------
DROP TABLE IF EXISTS `equipos`;
CREATE TABLE `equipos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_deporte` int(11) NOT NULL,
  `id_liga` int(11) DEFAULT NULL,
  `id_pais` int(11) DEFAULT NULL,
  `id_estadio` int(11) DEFAULT NULL COMMENT 'sede local',
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `siglas` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'grupo de torneo (Mundial, etc.)',
  `escudo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entrenador` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fundacion` year(4) DEFAULT NULL,
  `ranking` int(11) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_equipo_liga` (`id_liga`),
  KEY `idx_equipo_pais` (`id_pais`),
  KEY `idx_equipo_estadio` (`id_estadio`),
  KEY `idx_equipo_deporte_liga` (`id_deporte`,`id_liga`),
  KEY `idx_equipo_nombre` (`nombre`),
  CONSTRAINT `fk_equipos_deporte` FOREIGN KEY (`id_deporte`) REFERENCES `deportes` (`id`),
  CONSTRAINT `fk_equipos_liga` FOREIGN KEY (`id_liga`) REFERENCES `ligas_torneos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_equipos_pais` FOREIGN KEY (`id_pais`) REFERENCES `paises` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_equipos_estadio` FOREIGN KEY (`id_estadio`) REFERENCES `estadios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jugadores`;
CREATE TABLE `jugadores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_equipo` int(11) NOT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `posicion` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT 'N/D' COMMENT 'QB/RB/WR, PG/SG/C, Delantero, etc.',
  `dorsal` int(11) DEFAULT NULL,
  `edad` int(11) DEFAULT NULL,
  `nacionalidad` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `altura_cm` int(11) DEFAULT NULL,
  `peso_kg` int(11) DEFAULT NULL,
  `foto` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estadistica_principal` int(11) DEFAULT '0' COMMENT 'goles/puntos/hits/TDs segun deporte',
  `estadistica_label` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT 'Goles',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jugador_equipo` (`id_equipo`),
  KEY `idx_jugador_nombre` (`nombre`),
  CONSTRAINT `fk_jugadores_equipo` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 4. PARTIDOS & DETALLES
-- --------------------------------------------------------
DROP TABLE IF EXISTS `partidos`;
CREATE TABLE `partidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_deporte` int(11) NOT NULL,
  `id_liga` int(11) DEFAULT NULL,
  `id_equipo_local` int(11) NOT NULL,
  `id_equipo_visita` int(11) NOT NULL,
  `id_estadio` int(11) DEFAULT NULL,
  `fase` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT 'Fase de Grupos',
  `jornada` int(11) DEFAULT NULL,
  `grupo` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_partido` datetime NOT NULL,
  `marcador_local` int(11) DEFAULT NULL,
  `marcador_visita` int(11) DEFAULT NULL,
  `detalle_marcador` json DEFAULT NULL COMMENT 'cuartos/innings/periodos segun deporte',
  `estado` enum('programado','en_curso','finalizado','pospuesto','cancelado','suspendido') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'programado',
  `cuota_local` decimal(6,2) DEFAULT '2.00',
  `cuota_empate` decimal(6,2) DEFAULT NULL COMMENT 'NULL si el deporte no tiene empate',
  `cuota_visita` decimal(6,2) DEFAULT '2.50',
  `codigo` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_partido_codigo` (`codigo`),
  KEY `idx_partido_deporte` (`id_deporte`),
  KEY `idx_partido_visita` (`id_equipo_visita`),
  KEY `idx_partido_estadio` (`id_estadio`),
  KEY `idx_partido_fecha` (`fecha_partido`),
  KEY `idx_partido_estado` (`estado`),
  KEY `idx_partido_liga` (`id_liga`),
  KEY `idx_partido_equipos` (`id_equipo_local`,`id_equipo_visita`),
  CONSTRAINT `fk_partidos_deporte` FOREIGN KEY (`id_deporte`) REFERENCES `deportes` (`id`),
  CONSTRAINT `fk_partidos_liga` FOREIGN KEY (`id_liga`) REFERENCES `ligas_torneos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_partidos_local` FOREIGN KEY (`id_equipo_local`) REFERENCES `equipos` (`id`),
  CONSTRAINT `fk_partidos_visita` FOREIGN KEY (`id_equipo_visita`) REFERENCES `equipos` (`id`),
  CONSTRAINT `fk_partidos_estadio` FOREIGN KEY (`id_estadio`) REFERENCES `estadios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cuotas_historial`;
CREATE TABLE `cuotas_historial` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_partido` int(11) NOT NULL,
  `cuota_local` decimal(6,2) NOT NULL,
  `cuota_empate` decimal(6,2) DEFAULT NULL,
  `cuota_visita` decimal(6,2) NOT NULL,
  `registrado_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cuotas_hist_partido` (`id_partido`,`registrado_at`),
  CONSTRAINT `fk_cuotas_hist_partido` FOREIGN KEY (`id_partido`) REFERENCES `partidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `partido_detalle`;
CREATE TABLE `partido_detalle` (
  `id_partido` int(11) NOT NULL,
  `tiene_datos` tinyint(1) NOT NULL DEFAULT '0',
  `estadio_nombre` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estadio_ciudad` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asistencia` int(11) DEFAULT NULL,
  `formacion_local` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `formacion_visita` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stats_local` json DEFAULT NULL,
  `stats_visita` json DEFAULT NULL,
  `estado_importado` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intento_at` datetime NOT NULL,
  `importado_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id_partido`),
  CONSTRAINT `fk_partido_detalle_partido` FOREIGN KEY (`id_partido`) REFERENCES `partidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `partido_eventos`;
CREATE TABLE `partido_eventos` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_partido` int(11) NOT NULL,
  `espn_event_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `orden` smallint(6) NOT NULL,
  `periodo` tinyint(4) DEFAULT NULL,
  `minuto` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `segundos` int(11) DEFAULT NULL,
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_equipo` int(11) DEFAULT NULL,
  `jugador` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jugador_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jugador2` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `jugador2_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detalle` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marcador_local` tinyint(4) DEFAULT NULL,
  `marcador_visita` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_partido_evento` (`id_partido`,`espn_event_id`),
  KEY `idx_evt_partido` (`id_partido`,`orden`),
  CONSTRAINT `fk_partido_eventos_partido` FOREIGN KEY (`id_partido`) REFERENCES `partidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `partido_alineacion`;
CREATE TABLE `partido_alineacion` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_partido` int(11) NOT NULL,
  `id_equipo` int(11) NOT NULL,
  `espn_athlete_id` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dorsal` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posicion` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lugar` tinyint(4) DEFAULT NULL,
  `titular` tinyint(1) NOT NULL DEFAULT '0',
  `entro` tinyint(1) NOT NULL DEFAULT '0',
  `salio` tinyint(1) NOT NULL DEFAULT '0',
  `stats` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alineacion` (`id_partido`,`id_equipo`,`espn_athlete_id`),
  KEY `idx_alin_partido` (`id_partido`),
  CONSTRAINT `fk_partido_alineacion_partido` FOREIGN KEY (`id_partido`) REFERENCES `partidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 5. TABLA DE POSICIONES & ESTADÍSTICAS DE JUGADORES
-- --------------------------------------------------------
DROP TABLE IF EXISTS `tabla_posiciones`;
CREATE TABLE `tabla_posiciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_liga` int(11) NOT NULL,
  `id_equipo` int(11) NOT NULL,
  `temporada` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grupo` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pj` int(11) DEFAULT '0',
  `pg` int(11) DEFAULT '0',
  `pe` int(11) DEFAULT '0',
  `pp` int(11) DEFAULT '0',
  `favor` int(11) DEFAULT '0',
  `contra` int(11) DEFAULT '0',
  `diferencia` int(11) GENERATED ALWAYS AS ((`favor` - `contra`)) STORED,
  `pts` int(11) DEFAULT '0',
  `forma` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actualizado_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_posicion` (`id_liga`,`id_equipo`),
  KEY `idx_posicion_equipo` (`id_equipo`),
  CONSTRAINT `fk_tabla_posiciones_liga` FOREIGN KEY (`id_liga`) REFERENCES `ligas_torneos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tabla_posiciones_equipo` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `estadisticas_jugador_partido`;
CREATE TABLE `estadisticas_jugador_partido` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_jugador` int(11) NOT NULL,
  `id_partido` int(11) NOT NULL,
  `minutos` int(11) DEFAULT NULL,
  `stats` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stat_partido` (`id_jugador`,`id_partido`),
  KEY `idx_stat_partido` (`id_partido`),
  CONSTRAINT `fk_stat_jugador_p` FOREIGN KEY (`id_jugador`) REFERENCES `jugadores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stat_partido_p` FOREIGN KEY (`id_partido`) REFERENCES `partidos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `estadisticas_jugador_temporada`;
CREATE TABLE `estadisticas_jugador_temporada` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_jugador` int(11) NOT NULL,
  `id_liga` int(11) NOT NULL,
  `temporada` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `partidos_jugados` int(11) DEFAULT '0',
  `stats` json DEFAULT NULL,
  `actualizado_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stat_temporada` (`id_jugador`,`id_liga`,`temporada`),
  KEY `idx_stat_liga` (`id_liga`),
  CONSTRAINT `fk_stat_jugador_t` FOREIGN KEY (`id_jugador`) REFERENCES `jugadores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stat_liga_t` FOREIGN KEY (`id_liga`) REFERENCES `ligas_torneos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 6. APUESTAS, BOLETOS (COMBINADAS), RECARGAS Y TRANSACCIONES
-- --------------------------------------------------------
DROP TABLE IF EXISTS `boletos`;
CREATE TABLE `boletos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `cuota_total` decimal(10,2) NOT NULL COMMENT 'producto de las cuotas de cada pata',
  `ganancia_potencial` decimal(12,2) GENERATED ALWAYS AS ((`monto` * `cuota_total`)) STORED,
  `estado` enum('pendiente','ganado','perdido','cancelado','pagado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `referencia` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resuelto_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_boleto_ref` (`referencia`),
  KEY `idx_boleto_usuario` (`id_usuario`),
  CONSTRAINT `fk_boletos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `apuestas`;
CREATE TABLE `apuestas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `id_partido` int(11) NOT NULL,
  `tipo_apuesta` enum('local','empate','visita') COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `cuota` decimal(6,2) NOT NULL,
  `ganancia_potencial` decimal(12,2) GENERATED ALWAYS AS ((`monto` * `cuota`)) STORED,
  `estado` enum('pendiente','ganada','perdida','cancelada','pagada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `referencia` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resuelta_at` timestamp NULL DEFAULT NULL,
  `id_boleto` int(11) DEFAULT NULL COMMENT 'si no es NULL, esta apuesta es una pata de un boleto combinado',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apuesta_ref` (`referencia`),
  KEY `idx_apuesta_usuario_estado` (`id_usuario`,`estado`),
  KEY `idx_apuesta_partido_estado` (`id_partido`,`estado`),
  KEY `fk_apuesta_boleto` (`id_boleto`),
  CONSTRAINT `fk_apuestas_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_apuestas_partido` FOREIGN KEY (`id_partido`) REFERENCES `partidos` (`id`),
  CONSTRAINT `fk_apuesta_boleto` FOREIGN KEY (`id_boleto`) REFERENCES `boletos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `recargas`;
CREATE TABLE `recargas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `metodo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comprobante` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` enum('pendiente','aprobada','rechazada') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendiente',
  `referencia` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_admin` int(11) DEFAULT NULL,
  `motivo_rechazo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `procesada_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recarga_ref` (`referencia`),
  KEY `idx_recarga_admin` (`id_admin`),
  KEY `idx_recarga_usuario_estado` (`id_usuario`,`estado`),
  KEY `idx_recarga_estado` (`estado`),
  CONSTRAINT `fk_recargas_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_recargas_admin` FOREIGN KEY (`id_admin`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `transacciones`;
CREATE TABLE `transacciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `tipo` enum('deposito','retiro','apuesta','apuesta_combinada','pago_ganancia','ajuste_admin','reembolso','reembolso_cancelacion_cliente') COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(12,2) NOT NULL,
  `saldo_anterior` decimal(12,2) NOT NULL,
  `saldo_nuevo` decimal(12,2) NOT NULL,
  `referencia` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaccion_ref` (`referencia`),
  KEY `idx_transaccion_usuario` (`id_usuario`),
  CONSTRAINT `fk_transacciones_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 7. SINCRONIZACIÓN, LOGS Y PREFERENCIAS
-- --------------------------------------------------------
DROP TABLE IF EXISTS `external_ids`;
CREATE TABLE `external_ids` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `entidad` enum('pais','estadio','liga','equipo','jugador','partido') COLLATE utf8mb4_unicode_ci NOT NULL,
  `entidad_id` int(11) NOT NULL,
  `fuente` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_external` (`fuente`,`entidad`,`external_id`),
  KEY `idx_resolucion` (`entidad`,`entidad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `usuarios_equipos_favoritos`;
CREATE TABLE `usuarios_equipos_favoritos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `id_equipo` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fav` (`id_usuario`,`id_equipo`),
  KEY `idx_fav_equipo` (`id_equipo`),
  CONSTRAINT `fk_fav_equipo` FOREIGN KEY (`id_equipo`) REFERENCES `equipos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fav_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `usuarios_preferencias`;
CREATE TABLE `usuarios_preferencias` (
  `id_usuario` int(11) NOT NULL,
  `favoritos_omitido` tinyint(1) NOT NULL DEFAULT '0',
  `vista_partidos` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'calendario',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_usuario`),
  CONSTRAINT `fk_pref_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ligas_sync`;
CREATE TABLE `ligas_sync` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_liga` int(11) NOT NULL,
  `deporte` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug_espn` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activa` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_liga` (`id_liga`),
  CONSTRAINT `fk_ligas_sync_liga` FOREIGN KEY (`id_liga`) REFERENCES `ligas_torneos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sync_log`;
CREATE TABLE `sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime NOT NULL,
  `usuario_admin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Sistema/Cron',
  `liga` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resultados_actualizados` int(11) DEFAULT '0',
  `partidos_nuevos` int(11) DEFAULT '0',
  `errores` int(11) DEFAULT '0',
  `detalle` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- 8. TRIGGERS DE VALIDACIÓN E INVARIANZA
-- --------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_apuestas_validar_ins`;
DELIMITER $$
CREATE TRIGGER `trg_apuestas_validar_ins` BEFORE INSERT ON `apuestas` FOR EACH ROW BEGIN
  IF NEW.monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'apuestas.monto debe ser mayor a 0';
  END IF;
  IF NEW.cuota < 1.01 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'apuestas.cuota debe ser >= 1.01';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_apuestas_validar_upd`;
DELIMITER $$
CREATE TRIGGER `trg_apuestas_validar_upd` BEFORE UPDATE ON `apuestas` FOR EACH ROW BEGIN
  IF NEW.monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'apuestas.monto debe ser mayor a 0';
  END IF;
  IF NEW.cuota < 1.01 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'apuestas.cuota debe ser >= 1.01';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_boletos_validar_ins`;
DELIMITER $$
CREATE TRIGGER `trg_boletos_validar_ins` BEFORE INSERT ON `boletos` FOR EACH ROW BEGIN
  IF NEW.monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boletos.monto debe ser mayor a 0';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_boletos_validar_upd`;
DELIMITER $$
CREATE TRIGGER `trg_boletos_validar_upd` BEFORE UPDATE ON `boletos` FOR EACH ROW BEGIN
  IF NEW.monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boletos.monto debe ser mayor a 0';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_partidos_validar_ins`;
DELIMITER $$
CREATE TRIGGER `trg_partidos_validar_ins` BEFORE INSERT ON `partidos` FOR EACH ROW BEGIN
  IF NEW.id_equipo_local = NEW.id_equipo_visita THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'partidos: el equipo local y visitante no pueden ser el mismo';
  END IF;
  IF (NEW.marcador_local IS NOT NULL AND NEW.marcador_local < 0)
     OR (NEW.marcador_visita IS NOT NULL AND NEW.marcador_visita < 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'partidos: los marcadores no pueden ser negativos';
  END IF;
  IF NEW.cuota_local < 1.01 OR NEW.cuota_visita < 1.01
     OR (NEW.cuota_empate IS NOT NULL AND NEW.cuota_empate < 1.01) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'partidos: las cuotas deben ser >= 1.01';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_partidos_validar_upd`;
DELIMITER $$
CREATE TRIGGER `trg_partidos_validar_upd` BEFORE UPDATE ON `partidos` FOR EACH ROW BEGIN
  IF NEW.id_equipo_local = NEW.id_equipo_visita THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'partidos: el equipo local y visitante no pueden ser el mismo';
  END IF;
  IF (NEW.marcador_local IS NOT NULL AND NEW.marcador_local < 0)
     OR (NEW.marcador_visita IS NOT NULL AND NEW.marcador_visita < 0) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'partidos: los marcadores no pueden ser negativos';
  END IF;
  IF NEW.cuota_local < 1.01 OR NEW.cuota_visita < 1.01
     OR (NEW.cuota_empate IS NOT NULL AND NEW.cuota_empate < 1.01) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'partidos: las cuotas deben ser >= 1.01';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_partidos_cuotas_insert`;
DELIMITER $$
CREATE TRIGGER `trg_partidos_cuotas_insert` AFTER INSERT ON `partidos` FOR EACH ROW BEGIN
  INSERT INTO cuotas_historial (id_partido, cuota_local, cuota_empate, cuota_visita)
    VALUES (NEW.id, NEW.cuota_local, NEW.cuota_empate, NEW.cuota_visita);
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_partidos_cuotas_update`;
DELIMITER $$
CREATE TRIGGER `trg_partidos_cuotas_update` AFTER UPDATE ON `partidos` FOR EACH ROW BEGIN
  IF NOT (NEW.cuota_local <=> OLD.cuota_local
          AND NEW.cuota_empate <=> OLD.cuota_empate
          AND NEW.cuota_visita <=> OLD.cuota_visita) THEN
    INSERT INTO cuotas_historial (id_partido, cuota_local, cuota_empate, cuota_visita)
      VALUES (NEW.id, NEW.cuota_local, NEW.cuota_empate, NEW.cuota_visita);
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_recargas_validar_ins`;
DELIMITER $$
CREATE TRIGGER `trg_recargas_validar_ins` BEFORE INSERT ON `recargas` FOR EACH ROW BEGIN
  IF NEW.monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recargas.monto debe ser mayor a 0';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_recargas_validar_upd`;
DELIMITER $$
CREATE TRIGGER `trg_recargas_validar_upd` BEFORE UPDATE ON `recargas` FOR EACH ROW BEGIN
  IF NEW.monto <= 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'recargas.monto debe ser mayor a 0';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_usuarios_saldo_ins`;
DELIMITER $$
CREATE TRIGGER `trg_usuarios_saldo_ins` BEFORE INSERT ON `usuarios` FOR EACH ROW BEGIN
  IF NEW.saldo < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'usuarios.saldo no puede ser negativo';
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS `trg_usuarios_saldo_upd`;
DELIMITER $$
CREATE TRIGGER `trg_usuarios_saldo_upd` BEFORE UPDATE ON `usuarios` FOR EACH ROW BEGIN
  IF NEW.saldo < 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'usuarios.saldo no puede ser negativo';
  END IF;
END$$
DELIMITER ;

-- --------------------------------------------------------
-- 9. STORED PROCEDURES (IDEMPOTENTES Y SIN DEFINER)
-- --------------------------------------------------------
DROP PROCEDURE IF EXISTS `sp_resolver_partido`;
DELIMITER $$
CREATE PROCEDURE `sp_resolver_partido`(IN p_id_partido INT)
proc_body: BEGIN
  DECLARE v_ml INT DEFAULT NULL;
  DECLARE v_mv INT DEFAULT NULL;
  DECLARE v_tipo_ganador VARCHAR(10) DEFAULT NULL;
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_id_apuesta INT;
  DECLARE v_id_usuario INT;
  DECLARE v_ganancia DECIMAL(12,2);
  DECLARE v_saldo_anterior DECIMAL(12,2);
  DECLARE v_saldo_nuevo DECIMAL(12,2);
  DECLARE v_ref VARCHAR(40);
  DECLARE v_id_boleto INT;
  DECLARE v_boleto_usuario INT;
  DECLARE v_boleto_ganancia DECIMAL(12,2);
  DECLARE v_done2 INT DEFAULT 0;

  SELECT marcador_local, marcador_visita INTO v_ml, v_mv
    FROM partidos WHERE id = p_id_partido;

  IF v_ml IS NULL OR v_mv IS NULL THEN
    LEAVE proc_body;
  END IF;

  SET v_tipo_ganador = CASE
    WHEN v_ml > v_mv THEN 'local'
    WHEN v_ml < v_mv THEN 'visita'
    ELSE 'empate'
  END;

  -- Perdedoras: sin cambios, aplica igual a simples y a patas de boleto
  UPDATE apuestas
    SET estado = 'perdida', resuelta_at = NOW()
    WHERE id_partido = p_id_partido AND estado = 'pendiente' AND tipo_apuesta <> v_tipo_ganador;

  -- Ganadoras SIMPLES (id_boleto IS NULL): se pagan de inmediato
  block_ganadoras: BEGIN
    DECLARE cur_ganadoras CURSOR FOR
      SELECT id, id_usuario, ganancia_potencial
      FROM apuestas
      WHERE id_partido = p_id_partido AND estado = 'pendiente'
        AND tipo_apuesta = v_tipo_ganador AND id_boleto IS NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur_ganadoras;
    loop_ganadoras: LOOP
      FETCH cur_ganadoras INTO v_id_apuesta, v_id_usuario, v_ganancia;
      IF v_done THEN
        LEAVE loop_ganadoras;
      END IF;

      SELECT saldo INTO v_saldo_anterior FROM usuarios WHERE id = v_id_usuario FOR UPDATE;
      SET v_saldo_nuevo = v_saldo_anterior + v_ganancia;

      UPDATE usuarios SET saldo = v_saldo_nuevo WHERE id = v_id_usuario;

      SET v_ref = CONCAT('PAGO-', p_id_partido, '-', v_id_apuesta);
      INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
        VALUES (v_id_usuario, 'pago_ganancia', v_ganancia, v_saldo_anterior, v_saldo_nuevo, v_ref,
                CONCAT('Pago apuesta #', v_id_apuesta, ' - partido #', p_id_partido));

      UPDATE apuestas SET estado = 'ganada', resuelta_at = NOW() WHERE id = v_id_apuesta;
    END LOOP;
    CLOSE cur_ganadoras;
  END block_ganadoras;

  -- Patas de boleto ganadoras: marcar estado informativo de la pata
  UPDATE apuestas
    SET estado = 'ganada', resuelta_at = NOW()
    WHERE id_partido = p_id_partido AND estado = 'pendiente'
      AND tipo_apuesta = v_tipo_ganador AND id_boleto IS NOT NULL;

  -- Resolver boletos combinados que ya no tengan NINGUNA pata pendiente
  block_boletos: BEGIN
    DECLARE cur_boletos CURSOR FOR
      SELECT DISTINCT id_boleto FROM apuestas
      WHERE id_partido = p_id_partido AND id_boleto IS NOT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done2 = 1;

    OPEN cur_boletos;
    loop_boletos: LOOP
      FETCH cur_boletos INTO v_id_boleto;
      IF v_done2 THEN
        LEAVE loop_boletos;
      END IF;

      IF NOT EXISTS (SELECT 1 FROM apuestas WHERE id_boleto = v_id_boleto AND estado = 'pendiente') THEN
        IF NOT EXISTS (SELECT 1 FROM apuestas WHERE id_boleto = v_id_boleto AND estado <> 'ganada') THEN
          -- Todas las patas ganaron: pagar boleto completo
          SELECT b.id_usuario, b.ganancia_potencial INTO v_boleto_usuario, v_boleto_ganancia
            FROM boletos b WHERE b.id = v_id_boleto AND b.estado = 'pendiente' FOR UPDATE;

          IF v_boleto_usuario IS NOT NULL THEN
            SELECT saldo INTO v_saldo_anterior FROM usuarios WHERE id = v_boleto_usuario FOR UPDATE;
            SET v_saldo_nuevo = v_saldo_anterior + v_boleto_ganancia;
            UPDATE usuarios SET saldo = v_saldo_nuevo WHERE id = v_boleto_usuario;

            SET v_ref = CONCAT('PAGO-BOL-', v_id_boleto);
            INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
              VALUES (v_boleto_usuario, 'pago_ganancia', v_boleto_ganancia, v_saldo_anterior, v_saldo_nuevo, v_ref,
                      CONCAT('Pago boleto combinado #', v_id_boleto));

            UPDATE boletos SET estado = 'ganado', resuelto_at = NOW() WHERE id = v_id_boleto;
          END IF;
        ELSE
          -- Al menos una pata perdió: boleto perdido
          UPDATE boletos SET estado = 'perdido', resuelto_at = NOW()
            WHERE id = v_id_boleto AND estado = 'pendiente';
        END IF;
      END IF;
    END LOOP;
    CLOSE cur_boletos;
  END block_boletos;
END proc_body$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `sp_reembolsar_partido`;
DELIMITER $$
CREATE PROCEDURE `sp_reembolsar_partido`(IN p_id_partido INT)
BEGIN
  DECLARE v_done INT DEFAULT 0;
  DECLARE v_id_apuesta INT;
  DECLARE v_id_usuario INT;
  DECLARE v_monto DECIMAL(12,2);
  DECLARE v_id_boleto INT;
  DECLARE v_saldo_anterior DECIMAL(12,2);
  DECLARE v_saldo_nuevo DECIMAL(12,2);
  DECLARE v_ref VARCHAR(40);
  DECLARE v_boleto_usuario INT;
  DECLARE v_boleto_monto DECIMAL(12,2);

  DECLARE cur_pendientes CURSOR FOR
    SELECT id, id_usuario, monto, id_boleto
    FROM apuestas
    WHERE id_partido = p_id_partido AND estado = 'pendiente';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  OPEN cur_pendientes;
  loop_pendientes: LOOP
    FETCH cur_pendientes INTO v_id_apuesta, v_id_usuario, v_monto, v_id_boleto;
    IF v_done THEN
      LEAVE loop_pendientes;
    END IF;

    IF v_id_boleto IS NULL THEN
      -- Apuesta simple: reembolso directo
      SELECT saldo INTO v_saldo_anterior FROM usuarios WHERE id = v_id_usuario FOR UPDATE;

      UPDATE apuestas
      SET estado = 'cancelada', resuelta_at = NOW()
      WHERE id = v_id_apuesta AND estado = 'pendiente';

      IF ROW_COUNT() > 0 THEN
        SET v_saldo_nuevo = v_saldo_anterior + v_monto;
        UPDATE usuarios SET saldo = v_saldo_nuevo WHERE id = v_id_usuario;

        SET v_ref = CONCAT('REEMB-', p_id_partido, '-', v_id_apuesta);
        INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
          VALUES (v_id_usuario, 'reembolso', v_monto, v_saldo_anterior, v_saldo_nuevo, v_ref,
                  CONCAT('Reembolso por partido pospuesto/cancelado #', p_id_partido));
      END IF;
    ELSE
      -- Pata de boleto combinado: anular boleto completo y reembolsar stake
      SELECT b.id_usuario, b.monto INTO v_boleto_usuario, v_boleto_monto
        FROM boletos b
        WHERE b.id = v_id_boleto AND b.estado = 'pendiente'
        FOR UPDATE;

      IF v_boleto_usuario IS NOT NULL THEN
        SELECT saldo INTO v_saldo_anterior FROM usuarios WHERE id = v_boleto_usuario FOR UPDATE;

        UPDATE apuestas
        SET estado = 'cancelada', resuelta_at = NOW()
        WHERE id_boleto = v_id_boleto AND estado = 'pendiente';

        UPDATE boletos SET estado = 'cancelado', resuelto_at = NOW()
          WHERE id = v_id_boleto AND estado = 'pendiente';

        IF ROW_COUNT() > 0 THEN
          SET v_saldo_nuevo = v_saldo_anterior + v_boleto_monto;
          UPDATE usuarios SET saldo = v_saldo_nuevo WHERE id = v_id_usuario;

          SET v_ref = CONCAT('REEMB-BOL-', p_id_partido, '-', v_id_boleto);
          INSERT INTO transacciones (id_usuario, tipo, monto, saldo_anterior, saldo_nuevo, referencia, descripcion)
            VALUES (v_boleto_usuario, 'reembolso', v_boleto_monto, v_saldo_anterior, v_saldo_nuevo, v_ref,
                    CONCAT('Reembolso de boleto combinado #', v_id_boleto, ' por partido pospuesto/cancelado #', p_id_partido));
        END IF;
      END IF;
    END IF;
  END LOOP;
  CLOSE cur_pendientes;
END$$
DELIMITER ;

-- --------------------------------------------------------
-- 10. TRIGGER DISPARADOR DE RESOLUCIÓN AUTOMÁTICA
-- --------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_partidos_resolver_apuestas`;
DELIMITER $$
CREATE TRIGGER `trg_partidos_resolver_apuestas` AFTER UPDATE ON `partidos` FOR EACH ROW
BEGIN
  IF NEW.estado = 'finalizado' AND OLD.estado <> 'finalizado' THEN
    CALL sp_resolver_partido(NEW.id);
  ELSEIF NEW.estado IN ('pospuesto', 'cancelado', 'suspendido')
     AND OLD.estado NOT IN ('pospuesto', 'cancelado', 'suspendido') THEN
    CALL sp_reembolsar_partido(NEW.id);
  END IF;
END$$
DELIMITER ;