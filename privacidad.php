<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/legal.php';

$titulo_pagina = 'Política de Privacidad';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-9 col-xl-8">
    <div class="card shadow-sm">
      <div class="card-body p-4 p-md-5 legal-doc" data-cy="pagina-privacidad">
        <h1 class="h3 mb-1">Política de Privacidad</h1>
        <p class="text-muted small mb-4">Versión <?= e(TERMINOS_VERSION) ?> · Última actualización: <?= e(LEGAL_FECHA) ?></p>

        <p>Esta política explica qué datos personales recopila GoalBet 2026 (el «Sitio»), para qué los usa y qué podés hacer con ellos. Es parte de los <a href="<?= APP_URL ?>/terminos.php">Términos y Condiciones</a>.</p>

        <h2 class="h5 mt-4">1. Responsable y contacto</h2>
        <p>El responsable del tratamiento de los datos es el administrador de GoalBet 2026. Para cualquier consulta sobre tus datos escribí <?= legal_escribir_html() ?>.</p>

        <h2 class="h5 mt-4">2. Qué datos recopilamos</h2>
        <ul>
          <li><strong>Datos de tu cuenta:</strong> nombre, apellido, nombre de usuario, correo electrónico y teléfono (opcional). Tu contraseña se guarda <strong>cifrada</strong> (hash); nadie, ni el administrador, puede leerla.</li>
          <li><strong>Tu actividad:</strong> apuestas y boletos, solicitudes de recarga (monto, método, referencia y el texto que escribas en «comprobante»), movimientos de saldo, equipos favoritos y tus preferencias (vista de partidos, avisos por correo).</li>
          <li><strong>Datos técnicos de seguridad:</strong> al iniciar sesión se registra tu usuario o correo y tu dirección IP para bloquear intentos de adivinar contraseñas. Estos registros se borran automáticamente a las 24 horas.</li>
          <li><strong>Aceptación de términos:</strong> la versión de los términos que aceptaste y la fecha en que lo hiciste.</li>
        </ul>
        <p class="small text-muted">Por favor no escribas datos sensibles (números de tarjeta, claves) en el campo «comprobante».</p>

        <h2 class="h5 mt-4">3. Para qué usamos tus datos</h2>
        <ul>
          <li>Crear y mantener tu cuenta, y verificar que el correo es tuyo.</li>
          <li>Procesar tus recargas, apuestas, pagos y reembolsos.</li>
          <li>Proteger la plataforma y prevenir accesos no autorizados o fraude.</li>
          <li>Enviarte correos de cuenta (confirmación, recuperación de contraseña, avisos de seguridad, estado de recargas).</li>
          <li>Enviarte avisos opcionales sobre tus equipos favoritos y tus apuestas, si no los desactivaste.</li>
        </ul>
        <p>No vendemos tus datos ni los usamos para publicidad.</p>

        <h2 class="h5 mt-4">4. Con quién los compartimos</h2>
        <ul>
          <li><strong>Proveedor de correo:</strong> para enviarte los correos usamos un servicio de correo electrónico (SMTP) de un tercero, que recibe tu dirección y el contenido del mensaje.</li>
          <li><strong>Proveedor de alojamiento:</strong> el Sitio y su base de datos están alojados en un servicio de hosting.</li>
          <li><strong>Recursos externos:</strong> las páginas cargan estilos, íconos y tipografías desde servicios externos (por ejemplo jsDelivr y Google Fonts), que pueden ver tu dirección IP al descargarlos.</li>
          <li><strong>Datos deportivos:</strong> las fuentes de resultados y alineaciones que consulta el Sitio <u>no</u> reciben datos tuyos.</li>
          <li><strong>Administradores:</strong> los administradores del Sitio pueden ver los datos de cuenta y actividad necesarios para operar (por ejemplo, aprobar recargas).</li>
        </ul>

        <h2 class="h5 mt-4">5. Cookies</h2>
        <p>Usamos únicamente cookies necesarias: la <strong>cookie de sesión</strong> (te mantiene conectado) y un <strong>token de seguridad</strong> contra solicitudes falsas. No usamos cookies de publicidad ni de analítica.</p>

        <h2 class="h5 mt-4">6. Cuánto tiempo guardamos los datos</h2>
        <ul>
          <li>Los datos de tu cuenta y tu historial, mientras la cuenta exista.</li>
          <li>Los registros de intentos de inicio de sesión, 24 horas.</li>
          <li>Los enlaces de confirmación y de recuperación de contraseña vencen a las 24 horas y a la hora, respectivamente, y quedan inutilizables una vez usados. Los de confirmación de correo se guardan solo como huella (hash).</li>
        </ul>

        <h2 class="h5 mt-4">7. Tus derechos</h2>
        <ul>
          <li><strong>Ver y corregir</strong> tus datos: desde «Mi perfil» podés cambiar nombre, apellido, teléfono, contraseña y correo.</li>
          <li><strong>Dejar de recibir avisos</strong>: desde «Mi perfil» o con el enlace al pie de cada aviso.</li>
          <li><strong>Pedir la eliminación</strong> de tu cuenta y tus datos escribiendo <?= legal_escribir_html() ?>. Podemos conservar lo estrictamente necesario para llevar el registro de operaciones del Sitio.</li>
        </ul>

        <h2 class="h5 mt-4">8. Seguridad</h2>
        <p>Protegemos tus datos con contraseñas cifradas, protección contra solicitudes falsas (CSRF), límite de intentos de inicio de sesión y enlaces de un solo uso con vencimiento. Ningún sistema es 100 % seguro, por eso te pedimos usar una contraseña única y no compartirla.</p>

        <h2 class="h5 mt-4">9. Menores de edad</h2>
        <p>El Sitio es solo para <strong>mayores de 18 años</strong>. Si nos enteramos de que una cuenta pertenece a un menor, la eliminaremos.</p>

        <h2 class="h5 mt-4">10. Cambios a esta política</h2>
        <p>Podemos actualizar esta política. La versión y la fecha vigentes aparecen al inicio de la página.</p>

        <hr class="my-4">
        <p class="small mb-0">Ver también los <a href="<?= APP_URL ?>/terminos.php">Términos y Condiciones</a>.</p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
