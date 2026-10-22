</main>

<footer class="gb-footer py-4 mt-5 text-center text-muted small">
  <div class="container">
    <div class="mb-2">
      <span class="badge bg-danger me-2">+18</span>
      <span><strong>Juego Responsable</strong>: Prohibida la participación de menores de 18 años. El juego con apuestas puede ser adictivo. Jugá con moderación y responsabilidad.</span>
    </div>
    <div class="mb-1">
      <a href="<?= APP_URL ?>/terminos.php" class="text-decoration-none text-muted" data-cy="footer-terminos">Términos y Condiciones</a>
      &middot;
      <a href="<?= APP_URL ?>/privacidad.php" class="text-decoration-none text-muted" data-cy="footer-privacidad">Política de Privacidad</a>
      &middot;
      <a href="<?= APP_URL ?>/ayuda.php" class="text-decoration-none text-muted" data-cy="footer-ayuda">Ayuda y contacto</a>
    </div>
    <div>GoalBet 2026 &middot; proyecto educativo de apuestas deportivas &middot; <?= date('Y') ?></div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/public/js/app.js"></script>
<script src="<?= APP_URL ?>/public/js/live.js" data-api="<?= APP_URL ?>/api/live.php" data-login="<?= APP_URL ?>/login.php"></script>
</body>
</html>