<?php
require_once __DIR__ . '/includes/bootstrap.php';

$_SESSION = [];
session_destroy();

session_start();
flash('success', 'Cerraste sesión correctamente.');
redirect('/login.php');
