<?php

declare(strict_types=1);

/**
 * Ayuda y contacto: preguntas frecuentes + formulario que llega por correo al equipo de soporte
 * (CONTACT_EMAIL; si no está definido, al MAIL_FROM del sitio). Es pública: no exige sesión.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/legal.php';

const AYUDA_TEMAS = ['Cuenta y acceso', 'Recargas', 'Apuestas y resultados', 'Error técnico', 'Juego responsable', 'Otro'];
const AYUDA_MAX_POR_HORA = 5;

$destino = legal_contacto() ?: trim((string) Env::get('MAIL_FROM', '')) ?: trim((string) Env::get('MAIL_USER', ''));
$usuario = usuario_actual();
$ip      = obtener_ip_cliente();

$errores = [];
$enviado = false;
$valores = [
    'nombre'  => $usuario ? trim($usuario['nombre'] . ' ' . ($usuario['apellido'] ?? '')) : '',
    'email'   => $usuario['email'] ?? '',
    'tema'    => '',
    'mensaje' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    $valores['nombre']  = trim((string) ($_POST['nombre'] ?? ''));
    $valores['email']   = trim((string) ($_POST['email'] ?? ''));
    $valores['tema']    = (string) ($_POST['tema'] ?? '');
    $valores['mensaje'] = trim((string) ($_POST['mensaje'] ?? ''));

    if (trim((string) ($_POST['sitio_web'] ?? '')) !== '') {
        // Campo trampa para bots (invisible para personas): se simula éxito sin enviar nada.
        $enviado = true;
    } else {
        if ($valores['nombre'] === '' || mb_strlen($valores['nombre']) > 100) {
            $errores[] = 'Ingresá tu nombre (máximo 100 caracteres).';
        }
        if (!filter_var($valores['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($valores['email']) > 150) {
            $errores[] = 'El correo no es válido.';
        }
        if (!in_array($valores['tema'], AYUDA_TEMAS, true)) {
            $errores[] = 'Elegí un tema.';
        }
        if (mb_strlen($valores['mensaje']) < 20 || mb_strlen($valores['mensaje']) > 2000) {
            $errores[] = 'El mensaje debe tener entre 20 y 2000 caracteres.';
        }
        if ($destino === '') {
            $errores[] = 'El formulario no está disponible por ahora.';
        }

        // Anti-abuso: máximo 5 mensajes por hora por IP y uno cada 60 segundos por sesión.
        if (!$errores) {
            if (contar_fallos_login_recientes('contacto', $ip, 60) >= AYUDA_MAX_POR_HORA
                || time() - (int) ($_SESSION['ayuda_ultimo_envio'] ?? 0) < 60) {
                $errores[] = 'Enviaste varios mensajes seguidos. Esperá un momento antes de escribir de nuevo.';
            }
        }

        if (!$errores) {
            registrar_intento_login('contacto', $ip, false);
            $_SESSION['ayuda_ultimo_envio'] = time();
            $ok = correo_contacto($destino, $valores + [
                'username' => $usuario['username'] ?? null,
                'id'       => $usuario['id'] ?? 0,
            ]);
            if ($ok) {
                $enviado = true;
                $valores['mensaje'] = '';
                $valores['tema'] = '';
            } else {
                $errores[] = 'No pudimos enviar tu mensaje en este momento. Probá de nuevo en unos minutos.';
            }
        }
    }
}

$faq = [
    ['¿No me llega el correo de confirmación?',
     'Revisá la carpeta de spam. El enlace vence a las 24 horas; si no lo encontrás, pedí otro desde <a href="' . APP_URL . '/reenviar_verificacion.php">«Reenviar correo de confirmación»</a> (se puede pedir uno por minuto).'],
    ['Olvidé mi contraseña',
     'Usá <a href="' . APP_URL . '/recuperar.php">«Olvidé mi contraseña»</a> y te enviamos un enlace que vence en 1 hora. Si ya estás dentro, podés cambiarla desde «Mi perfil».'],
    ['¿Cómo cambio mi correo o mis datos?',
     'En «Mi perfil» (menú con tu nombre). Para cambiar el correo te enviamos un enlace de confirmación al correo nuevo; el actual no cambia hasta que lo confirmes.'],
    ['¿Cómo recargo saldo?',
     'Desde «Recargar»: elegí el monto (de L. 50 a L. 50,000) y el método. La solicitud queda pendiente hasta que un administrador la apruebe; te avisamos por correo si fue aprobada o rechazada.'],
    ['¿Cómo hago una apuesta?',
     'En «Apostar» elegí un partido, el resultado y el monto (mínimo L. 20). En «Combinada» podés juntar entre 2 y 8 partidos en un solo boleto: gana solo si acertás todos.'],
    ['¿Puedo cancelar una apuesta?',
     'Sí, desde «Mis apuestas», mientras siga pendiente y el partido no haya empezado. Recuperás el monto apostado.'],
    ['¿Cuándo se paga una apuesta?',
     'Cuando el partido finaliza. Las apuestas simples ganadoras se acreditan automáticamente a tu saldo; las combinadas, cuando se resuelve la última selección.'],
    ['¿Qué pasa si suspenden o posponen un partido?',
     'Se reembolsa lo apostado. Si el partido era parte de una combinada, se anula el boleto completo y se devuelve el monto.'],
    ['¿Cómo recibo avisos de mi equipo favorito?',
     'Marcá tu equipo como favorito desde «Partidos». Te avisamos 3 días, 1 día y unas horas antes de que juegue, con el 11 titular cuando se publique y el resultado al terminar. Podés desactivarlos en «Mi perfil».'],
    ['¿El dinero es real?',
     'No. GoalBet 2026 es un proyecto educativo: todo el saldo, las apuestas y los premios son simulados y no se pueden retirar.'],
    ['Siento que juego demasiado',
     'Tomá un descanso. Si querés, escribinos con el tema «Juego responsable» y desactivamos tu cuenta. Recordá que apostar puede generar adicción.'],
];

$titulo_pagina = 'Ayuda y contacto';
require __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
  <h1 class="h3 mb-1"><i class="bi bi-question-circle text-warning"></i> Ayuda y contacto</h1>
  <p class="text-muted mb-0">Resolvé las dudas más comunes o escribinos.</p>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Preguntas frecuentes</h2>
        <div class="accordion accordion-flush" id="faq" data-cy="faq">
          <?php foreach ($faq as $i => [$pregunta, $respuesta]): ?>
            <div class="accordion-item">
              <h3 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>">
                  <?= e($pregunta) ?>
                </button>
              </h3>
              <div id="faq<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faq">
                <div class="accordion-body small"><?= $respuesta ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h2 class="h5 mb-3">Escribinos</h2>

        <?php if ($enviado): ?>
          <div class="alert alert-success" data-cy="contacto-enviado">¡Gracias! Recibimos tu mensaje y te vamos a responder por correo.</div>
        <?php endif; ?>
        <?php if ($errores): ?>
          <div class="alert alert-danger py-2" data-cy="contacto-error">
            <?php foreach ($errores as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($destino === ''): ?>
          <div class="alert alert-info mb-0">El formulario de contacto no está disponible por ahora.</div>
        <?php else: ?>
          <form method="post" novalidate data-cy="form-contacto">
            <?= csrf_field() ?>
            <div style="position:absolute;left:-9999px" aria-hidden="true">
              <label>No completar este campo <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label>
            </div>
            <div class="mb-3">
              <label class="form-label">Nombre</label>
              <input type="text" name="nombre" class="form-control" maxlength="100" value="<?= e($valores['nombre']) ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Correo para responderte</label>
              <input type="email" name="email" class="form-control" maxlength="150" value="<?= e($valores['email']) ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Tema</label>
              <select name="tema" class="form-select" required>
                <option value="">Elegí uno...</option>
                <?php foreach (AYUDA_TEMAS as $t): ?>
                  <option value="<?= e($t) ?>" <?= $valores['tema'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Mensaje</label>
              <textarea name="mensaje" class="form-control" rows="5" minlength="20" maxlength="2000" required><?= e($valores['mensaje']) ?></textarea>
              <div class="form-text">Entre 20 y 2000 caracteres. No incluyas contraseñas ni datos de tarjetas.</div>
            </div>
            <button type="submit" class="btn btn-warning fw-semibold w-100">Enviar mensaje</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
