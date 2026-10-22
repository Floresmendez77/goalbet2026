<?php
/** @var string|null $titulo_pagina definido opcionalmente por la página antes de incluir este header */
$titulo_pagina = $titulo_pagina ?? 'GoalBet 2026';
$gb_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$gb_activo = static fn(string ...$f): string => in_array($gb_script, $f, true) ? ' active' : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($titulo_pagina) ?> · GoalBet 2026</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>⚽</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= APP_URL ?>/public/css/style.css" rel="stylesheet">
<link href="<?= APP_URL ?>/public/css/custom-theme.css" rel="stylesheet">
<link href="<?= APP_URL ?>/public/css/partidos-ui.css" rel="stylesheet">
<link href="<?= APP_URL ?>/public/css/alineacion-lista.css" rel="stylesheet">
<link href="<?= APP_URL ?>/public/css/goalbet-ecotienda.css" rel="stylesheet">
</head>
<body>

<div class="demo-strip" role="note" data-cy="demo-strip"><i class="bi bi-info-circle"></i>Demo educativa: dinero simulado, no se procesan pagos reales.</div>

<div class="navbar-capsule-wrapper">
<nav class="navbar navbar-expand-lg navbar-dark navbar-custom navbar-capsule">
  <div class="container">
    <a class="navbar-brand navbar-brand-custom fw-bold" href="<?= APP_URL ?>/dashboard.php">
      <i class="bi bi-dice-5-fill text-warning"></i> GoalBet <span class="text-warning">2026</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <?php if (esta_logueado()): ?>
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link<?= $gb_activo('dashboard.php') ?>" href="<?= APP_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
          <li class="nav-item"><a class="nav-link<?= $gb_activo('partidos.php') ?>" href="<?= APP_URL ?>/partidos.php"><i class="bi bi-calendar-event"></i> Partidos</a></li>
          <li class="nav-item"><a class="nav-link<?= $gb_activo('apostar.php') ?>" href="<?= APP_URL ?>/apostar.php" data-cy="nav-apostar"><i class="bi bi-cash-coin"></i> Apostar</a></li>
          <li class="nav-item"><a class="nav-link<?= $gb_activo('apostar_combinada.php') ?>" href="<?= APP_URL ?>/apostar_combinada.php" data-cy="nav-apostar-combinada"><i class="bi bi-layers-fill"></i> Combinada</a></li>
          <li class="nav-item"><a class="nav-link<?= $gb_activo('mis_apuestas.php') ?>" href="<?= APP_URL ?>/mis_apuestas.php" data-cy="nav-mis-apuestas"><i class="bi bi-receipt"></i> Mis apuestas</a></li>
          <li class="nav-item"><a class="nav-link<?= $gb_activo('posiciones.php') ?>" href="<?= APP_URL ?>/posiciones.php"><i class="bi bi-list-ol"></i> Posiciones</a></li>
          <li class="nav-item"><a class="nav-link<?= $gb_activo('recargar.php') ?>" href="<?= APP_URL ?>/recargar.php" data-cy="nav-recargar"><i class="bi bi-wallet2"></i> Recargar</a></li>
          <?php if (es_admin()): ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle<?= str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                <i class="bi bi-shield-lock"></i> Admin
              </a>
              <ul class="dropdown-menu dropdown-menu-dark">
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/index.php">Panel</a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/partidos.php">Partidos / resultados</a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/usuarios.php">Usuarios</a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/recargas.php">Recargas pendientes</a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/admin/reportes.php">Reportes</a></li>
              </ul>
            </li>
          <?php endif; ?>
        </ul>
        <ul class="navbar-nav ms-auto align-items-lg-center">
          <li class="nav-item me-lg-3">
            <span class="navbar-text balance-badge fw-semibold" data-cy="user-balance">
              <i class="bi bi-wallet-fill me-1"></i> <?= money(usuario_actual()['saldo']) ?>
            </span>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
              <i class="bi bi-person-circle"></i> <?= e(usuario_actual()['nombre']) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= APP_URL ?>/perfil.php" data-cy="nav-perfil"><i class="bi bi-person-gear"></i> Mi perfil</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a></li>
            </ul>
          </li>
        </ul>
      <?php else: ?>
        <ul class="navbar-nav ms-auto">
          <li class="nav-item"><a class="nav-link" href="<?= APP_URL ?>/login.php">Ingresar</a></li>
          <li class="nav-item"><a class="btn btn-warning ms-lg-2 px-3" href="<?= APP_URL ?>/register.php">Crear cuenta</a></li>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</nav>
</div>

<main class="container py-4">
  <?php foreach (get_flashes() as $f): ?>
    <div class="alert alert-<?= e($f['tipo']) ?> alert-dismissible fade show" role="alert">
      <?= e($f['mensaje']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>