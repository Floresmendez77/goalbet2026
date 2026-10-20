document.addEventListener('DOMContentLoaded', function () {
  // --- Selector de cuota (local/empate/visita) dentro de cada tarjeta de partido ---
  document.querySelectorAll('.form-apuesta').forEach(function (form) {
    const botones = form.querySelectorAll('.cuota-btn');
    const inputTipo = form.querySelector('input[name="tipo_apuesta"]');
    const inputCuota = form.querySelector('input[name="cuota"]');
    const inputMonto = form.querySelector('input[name="monto"]');
    const previewGanancia = form.querySelector('.preview-ganancia');
    const btnSubmit = form.querySelector('.btn-confirmar-apuesta');

    function actualizarPreview() {
      const cuota = parseFloat(inputCuota.value || '0');
      const monto = parseFloat(inputMonto.value || '0');
      if (cuota > 0 && monto > 0 && previewGanancia) {
        previewGanancia.textContent = 'Ganancia potencial: L. ' + (cuota * monto).toFixed(2);
      } else if (previewGanancia) {
        previewGanancia.textContent = '';
      }
    }

    botones.forEach(function (btn) {
      btn.addEventListener('click', function () {
        botones.forEach(function (b) { b.classList.remove('selected'); });
        btn.classList.add('selected');
        inputTipo.value = btn.dataset.tipo;
        inputCuota.value = btn.dataset.cuota;
        if (btnSubmit) btnSubmit.disabled = false;
        actualizarPreview();
      });
    });

    if (inputMonto) {
      inputMonto.addEventListener('input', actualizarPreview);
    }
  });

  // --- Confirmación antes de acciones destructivas/irreversibles ---
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('submit', function (ev) {
      if (!window.confirm(el.dataset.confirm)) {
        ev.preventDefault();
      }
    });
  });
});
