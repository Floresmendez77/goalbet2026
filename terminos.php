<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/legal.php';

$titulo_pagina = 'Términos y Condiciones';
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-9 col-xl-8">
    <div class="card shadow-sm">
      <div class="card-body p-4 p-md-5 legal-doc" data-cy="pagina-terminos">
        <h1 class="h3 mb-1">Términos y Condiciones de Uso</h1>
        <p class="text-muted small mb-4">Versión <?= e(TERMINOS_VERSION) ?> · Última actualización: <?= e(LEGAL_FECHA) ?></p>

        <div class="alert alert-warning small">
          <strong>GoalBet 2026 es un proyecto educativo.</strong> Todo el dinero, saldos, apuestas y premios son <strong>simulados</strong> y no tienen valor real.
        </div>

        <h2 class="h5 mt-4">1. Qué es GoalBet 2026</h2>
        <p>GoalBet 2026 (el «Sitio») es una demostración de una plataforma de apuestas deportivas. Sirve para aprender y practicar con dinero ficticio. No es una casa de apuestas con licencia, no maneja dinero real y los saldos no se pueden retirar, transferir ni canjear por dinero, bienes o servicios. Cualquier medio de pago que se muestre funciona solo en modo de prueba.</p>

        <h2 class="h5 mt-4">2. Quién puede usar el Sitio</h2>
        <ul>
          <li>Tenés que ser <strong>mayor de 18 años</strong>. Al registrarte lo confirmás.</li>
          <li>Podés tener <strong>una sola cuenta</strong>, a tu nombre, con datos verdaderos.</li>
          <li>Tenés que usar un correo electrónico propio y confirmarlo con el enlace que te enviamos.</li>
        </ul>

        <h2 class="h5 mt-4">3. Tu cuenta</h2>
        <p>Sos responsable de mantener en secreto tu contraseña y de todo lo que se haga con tu cuenta. Si sospechás que alguien accedió sin permiso, cambiá tu contraseña desde «Mi perfil» y avisanos. Podemos <strong>desactivar o suspender</strong> cuentas que incumplan estos términos; en ese caso no vas a poder iniciar sesión.</p>

        <h2 class="h5 mt-4">4. Saldo y recargas</h2>
        <ul>
          <li>Podés pedir recargas de saldo simulado de <strong>L. 50.00 a L. 50,000.00</strong> por solicitud.</li>
          <li>Cada recarga queda <strong>pendiente</strong> hasta que un administrador la aprueba o la rechaza (con un motivo). Te avisamos por correo del resultado.</li>
          <li>El saldo es ficticio: no se devuelve, no se retira y no se transfiere entre cuentas.</li>
        </ul>

        <h2 class="h5 mt-4">5. Apuestas</h2>
        <ul>
          <li>Podés hacer <strong>apuestas simples</strong> (un partido) y <strong>combinadas</strong> (entre 2 y 8 partidos).</li>
          <li>El monto mínimo por apuesta es <strong>L. 20.00</strong>; además existe un monto máximo por apuesta definido por el Sitio.</li>
          <li>Solo se puede apostar en partidos <strong>programados</strong> que todavía no empezaron.</li>
          <li>La <strong>cuota queda fijada</strong> en el momento en que hacés la apuesta, aunque después cambie.</li>
          <li>En una combinada, el boleto solo gana si <strong>todas</strong> las selecciones aciertan.</li>
          <li>Al apostar, el monto se descuenta de tu saldo.</li>
        </ul>

        <h2 class="h5 mt-4">6. Cancelar una apuesta</h2>
        <p>Mientras una apuesta o boleto siga <strong>pendiente</strong> y los partidos no hayan comenzado, podés cancelarlo desde «Mis apuestas» y recuperás el monto apostado.</p>

        <h2 class="h5 mt-4">7. Resultados y pagos</h2>
        <ul>
          <li>Las apuestas se resuelven cuando el partido finaliza, con el marcador final registrado en el Sitio.</li>
          <li>Las apuestas simples ganadoras se pagan automáticamente: monto × cuota se acredita a tu saldo. Las combinadas ganadoras se pagan cuando se resuelve la última selección.</li>
          <li>Las apuestas perdidas no se devuelven.</li>
          <li>Los marcadores pueden venir de fuentes deportivas de terceros o ser cargados por un administrador. Si hay un <strong>error evidente</strong> en un marcador o en una cuota, podemos corregirlo y ajustar las apuestas afectadas.</li>
        </ul>

        <h2 class="h5 mt-4">8. Partidos cancelados, pospuestos o suspendidos</h2>
        <p>Si un partido se cancela, se pospone o se suspende, las apuestas simples pendientes se <strong>reembolsan</strong>. Si el partido es parte de una combinada, el boleto completo se anula y se reembolsa el monto apostado.</p>

        <h2 class="h5 mt-4">9. Juego responsable</h2>
        <p>El juego con apuestas puede ser adictivo, aun cuando aquí sea simulado. Jugá con moderación, no apuestes para recuperar pérdidas y tomate descansos. Si sentís que el juego se te está yendo de las manos, escribinos <?= legal_escribir_html() ?> y desactivamos tu cuenta. Si necesitás ayuda, buscá apoyo profesional o de personas de confianza.</p>

        <h2 class="h5 mt-4">10. Conductas no permitidas</h2>
        <ul>
          <li>Crear cuentas múltiples o usar cuentas de otras personas.</li>
          <li>Aprovechar errores del sistema, cuotas equivocadas o fallas técnicas para obtener ventaja.</li>
          <li>Usar programas automáticos (bots, scrapers) o intentar vulnerar la seguridad del Sitio.</li>
          <li>Subir información falsa, ofensiva o que pertenezca a otra persona (por ejemplo en el campo «comprobante»).</li>
        </ul>

        <h2 class="h5 mt-4">11. Correos electrónicos</h2>
        <p>Te enviamos correos necesarios para el funcionamiento de tu cuenta: confirmación de correo, recuperación de contraseña, avisos de seguridad y estado de tus recargas. Además, podés recibir <strong>avisos opcionales</strong> (recordatorios de partidos de tus equipos favoritos, alineaciones y resultados de tus apuestas) que podés desactivar en «Mi perfil» o con el enlace al pie de cada aviso.</p>

        <h2 class="h5 mt-4">12. Contenido deportivo y marcas</h2>
        <p>Los nombres, escudos y datos de equipos, ligas y jugadores pertenecen a sus respectivos dueños y se muestran con fines educativos. GoalBet 2026 no está afiliado ni respaldado por ellos.</p>

        <h2 class="h5 mt-4">13. Disponibilidad y responsabilidad</h2>
        <p>El Sitio se ofrece «tal cual», sin garantías de funcionamiento continuo ni de que los datos deportivos estén siempre completos o actualizados. Como el dinero es simulado, no hay pérdidas económicas reales; no nos hacemos responsables por interrupciones, errores de datos de terceros ni por decisiones tomadas a partir de la información del Sitio.</p>

        <h2 class="h5 mt-4">14. Cambios a estos términos</h2>
        <p>Podemos actualizar estos términos. La versión y la fecha vigentes aparecen al inicio de esta página. Si seguís usando el Sitio después de un cambio, se entiende que lo aceptás.</p>

        <h2 class="h5 mt-4">15. Ley aplicable y contacto</h2>
        <p>Estos términos se rigen por las leyes de la República de Honduras. Para consultas o reclamos, escribí <?= legal_escribir_html() ?>.</p>

        <hr class="my-4">
        <p class="small mb-0">Ver también la <a href="<?= APP_URL ?>/privacidad.php">Política de Privacidad</a>.</p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
