<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Redirigir a dashboard si el usuario ya cuenta con sesión activa
if (esta_logueado()) {
    redirect('/dashboard.php');
}

// Presentar la landing page deportiva (index2.php)
require __DIR__ . '/index2.php';
