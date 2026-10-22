<?php

/**
 * Datos comunes de las páginas legales (terminos.php y privacidad.php).
 * Si cambiás el texto de los términos o de la privacidad, subí TERMINOS_VERSION y LEGAL_FECHA:
 * la versión aceptada queda guardada en usuarios.terminos_version al registrarse.
 */

declare(strict_types=1);

const TERMINOS_VERSION = '1.0';
const LEGAL_FECHA      = '21 de octubre de 2026';

/** Correo de contacto público (CONTACT_EMAIL en seeder/.env). Vacío si no está configurado. */
function legal_contacto(): string
{
    $c = trim((string) Env::get('CONTACT_EMAIL', ''));
    return filter_var($c, FILTER_VALIDATE_EMAIL) ? $c : '';
}

/** Destinatario para frases tipo «escribinos …»: «a correo@…» o «al administrador del sitio». */
function legal_escribir_html(): string
{
    $c = legal_contacto();
    return $c !== ''
        ? 'a <a href="mailto:' . e($c) . '">' . e($c) . '</a>'
        : 'al administrador del sitio';
}
