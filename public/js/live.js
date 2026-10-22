/*!
 * GoalBet2026 — tiempo real, etapa 4.
 *
 * Consulta api/live.php (que lee SOLO de nuestra base de datos; nunca de ESPN) y actualiza
 * en pantalla marcador, minuto, estado, goles (con asistente), tarjetas y cambios,
 * sin recargar la página ni pulsar nada.
 *
 * Se engancha a los elementos marcados desde PHP:
 *   [data-live-partido="12"]  tarjeta/cabecera de un partido (+ data-live-estado, data-live-inicio,
 *                             y data-live-eventos si queremos también la línea de tiempo)
 *   [data-live="badge"]       insignia de estado / "EN VIVO 67'"
 *   [data-live="score"]       marcador
 *   [data-live="goleadores"]  lista de goleadores de un lado (data-lado="local|visita")
 *   [data-live="cronologia"]  panel con la línea de tiempo
 *   [data-live="aviso"]       banner de "¡Gol!"
 *   [data-live="conexion"]    texto de estado de la conexión
 *
 * Si la página no tiene ningún [data-live-partido], este archivo no hace nada.
 * JavaScript vanilla, sin dependencias. Todo el texto se inserta con textContent (nada de innerHTML).
 */
(function () {
  'use strict';

  var script = document.currentScript || document.querySelector('script[data-api]');
  if (!script || !script.dataset.api) { return; }
  var API = script.dataset.api;
  var LOGIN = script.dataset.login || '';

  var cards = [].slice.call(document.querySelectorAll('[data-live-partido]'));
  if (!cards.length) { return; }

  var HORA = 3600 * 1000;
  var MAX_IDS = 50;          // mismo tope que LIVE_MAX_IDS del servidor
  var TIMEOUT_MS = 10000;
  var SIN_POLL = 120;        // si el servidor dice "no hace falta" pero aún hay algo que vigilar

  var porId = {};
  cards.forEach(function (el) {
    var id = el.dataset.livePartido;
    (porId[id] = porId[id] || []).push(el);
  });

  var timer = null, enVuelo = false, detenido = false, fallos = 0;
  var ultimaUrl = null, ultimaV = null, ultimoPoll = null;

  // ------------------------------------------------------------------ utilidades

  function crear(tag, clase, texto) {
    var n = document.createElement(tag);
    if (clase) { n.className = clase; }
    if (texto !== undefined && texto !== null) { n.textContent = texto; }
    return n;
  }

  function destello(n) {
    n.classList.remove('live-flash');
    void n.offsetWidth; // reinicia la animación si ya la tenía
    n.classList.add('live-flash');
    setTimeout(function () { n.classList.remove('live-flash'); }, 1800);
  }

  function limpio(s) { return String(s).replace(/\s+/g, ''); }

  // ------------------------------------------------------------------ qué partidos vigilar

  /** Ids que conviene preguntar ahora: en curso, o programados dentro de la misma ventana que usa el servidor (-4 h .. +2 h). */
  function candidatos() {
    var ahora = Date.now(), vistos = {}, lista = [];
    cards.forEach(function (el) {
      var id = el.dataset.livePartido, estado = el.dataset.liveEstado, d = null;
      if (estado === 'en_curso') {
        d = 0;
      } else if (estado === 'programado') {
        var falta = Date.parse(el.dataset.liveInicio) - ahora;
        if (falta >= -4 * HORA && falta <= 2 * HORA) { d = Math.abs(falta); }
      }
      if (d !== null && !vistos[id]) { vistos[id] = true; lista.push({ id: id, d: d }); }
    });
    lista.sort(function (a, b) { return a.d - b.d; });
    return lista.slice(0, MAX_IDS).map(function (x) { return x.id; });
  }

  /** ¿Queda algún partido que todavía pueda ponerse en juego? (revisión local, sin red) */
  function hayPendientes() {
    return cards.some(function (el) {
      return el.dataset.liveEstado === 'en_curso' || el.dataset.liveEstado === 'programado';
    });
  }

  function conEventos(ids) {
    return ids.length === 1 && porId[ids[0]].some(function (el) { return el.hasAttribute('data-live-eventos'); });
  }

  // ------------------------------------------------------------------ pintar: insignia, marcador, apuestas

  var BADGES = {
    programado: 'info text-dark', en_curso: 'primary', finalizado: 'success',
    pospuesto: 'warning text-dark', suspendido: 'secondary', cancelado: 'secondary'
  };

  function etiqueta(estado) {
    var t = estado.replace(/_/g, ' ');
    return t.charAt(0).toUpperCase() + t.slice(1);
  }

  function pintarBadge(el, p) {
    var cont = el.querySelector('[data-live="badge"]');
    if (!cont) { return; }
    var clase, texto;
    if (p.estado === 'en_curso') {
      clase = 'danger';
      texto = 'EN VIVO' + (p.minuto ? ' ' + p.minuto : '');
    } else {
      clase = BADGES[p.estado] || 'secondary';
      texto = etiqueta(p.estado);
    }
    var actual = cont.firstElementChild;
    if (actual && actual.className === 'badge bg-' + clase && actual.textContent === texto) { return; }
    cont.textContent = '';
    var b = crear('span', 'badge bg-' + clase, texto);
    cont.appendChild(b);
    destello(b);
  }

  function pintarMarcador(el, p) {
    var n = el.querySelector('[data-live="score"]');
    if (!n) { return; }
    var muestra = !!p.marcador && (p.estado === 'en_curso' || p.estado === 'finalizado');
    var nuevo = muestra ? p.marcador.local + '-' + p.marcador.visita : 'VS';
    if (limpio(n.textContent) === nuevo) { return; }
    if (n.classList.contains('pd-score')) {            // cabecera de partido.php
      n.textContent = '';
      if (muestra) {
        n.appendChild(document.createTextNode(p.marcador.local + ' '));
        n.appendChild(crear('span', '', '-'));
        n.appendChild(document.createTextNode(' ' + p.marcador.visita));
      } else {
        n.appendChild(crear('span', 'pd-vs', 'VS'));
      }
    } else {                                           // tarjetas de lista / calendario
      n.classList.toggle('match-score', muestra);
      n.classList.toggle('match-vs', !muestra);
      n.textContent = muestra ? p.marcador.local + ' - ' + p.marcador.visita : 'VS';
    }
    destello(n);
  }

  /** Igual que el servidor: cuotas solo mientras está 'programado'; apostar solo si aún no empezó. */
  function pintarApuesta(el, p, ahoraMs) {
    var odds = el.querySelector('.odds-row');
    if (odds) { odds.style.display = p.estado === 'programado' ? '' : 'none'; }
    var a = el.querySelector('a[href*="apostar.php"]');
    if (!a) { return; }
    if (p.estado !== 'programado') { a.parentNode.removeChild(a); return; }
    if (Date.parse(p.inicio_utc) > ahoraMs) { return; } // sigue abierto
    var b = crear('button', 'btn btn-secondary btn-sm w-100');
    b.type = 'button';
    b.disabled = true;
    b.appendChild(crear('i', 'bi bi-lock-fill'));
    b.appendChild(document.createTextNode(' Cerrado para apuestas (en curso)'));
    a.parentNode.replaceChild(b, a);
  }

  // ------------------------------------------------------------------ pintar: goles, tarjetas, cambios

  function pintarGoleadores(el, eventos) {
    var listas = { local: [], visita: [] };
    eventos.forEach(function (ev) {
      var texto = (ev.jugador || '') + ' ' + (ev.minuto || '');
      if (ev.tipo === 'gol' || ev.tipo === 'gol_penal') {
        if (ev.lado) { listas[ev.lado].push(texto + (ev.tipo === 'gol_penal' ? ' (p)' : '')); }
      } else if (ev.tipo === 'autogol') {                       // el gol cuenta para el rival
        listas[ev.lado === 'local' ? 'visita' : 'local'].push(texto + ' (a.g.)');
      }
    });
    [].forEach.call(el.querySelectorAll('[data-live="goleadores"]'), function (ul) {
      var items = listas[ul.dataset.lado] || [];
      ul.textContent = '';
      items.forEach(function (t) { ul.appendChild(crear('li', '', '⚽ ' + t)); });
    });
  }

  function tarjetaEvento(ev) {
    var card, cuerpo;
    switch (ev.tipo) {
      case 'gol': case 'gol_penal': case 'autogol':
        card = crear('div', 'tl-card tl-gol');
        card.appendChild(crear('span', 'tl-ico', '⚽'));
        cuerpo = crear('div');
        cuerpo.appendChild(crear('strong', '', ev.jugador || ''));
        if (ev.tipo === 'gol_penal') { cuerpo.appendChild(document.createTextNode(' ')); cuerpo.appendChild(crear('small', '', '(penal)')); }
        if (ev.tipo === 'autogol') { cuerpo.appendChild(document.createTextNode(' ')); cuerpo.appendChild(crear('small', '', '(autogol)')); }
        if (ev.marcador) { cuerpo.appendChild(crear('span', 'tl-score', ev.marcador.local + '-' + ev.marcador.visita)); }
        if (ev.asistente) { cuerpo.appendChild(crear('div', 'tl-sub', 'Asistencia: ' + ev.asistente)); }
        card.appendChild(cuerpo);
        return card;
      case 'amarilla': case 'roja': case 'segunda_amarilla':
        card = crear('div', 'tl-card');
        card.appendChild(crear('span', 'tl-ico', ev.tipo === 'amarilla' ? '🟨' : '🟥'));
        cuerpo = crear('div');
        cuerpo.appendChild(crear('strong', '', ev.jugador || ''));
        cuerpo.appendChild(crear('div', 'tl-sub',
          ev.tipo === 'amarilla' ? 'Tarjeta amarilla' : (ev.tipo === 'roja' ? 'Tarjeta roja' : 'Segunda amarilla (expulsado)')));
        card.appendChild(cuerpo);
        return card;
      case 'cambio':
        card = crear('div', 'tl-card');
        var ico = crear('span', 'tl-ico text-success');
        ico.appendChild(crear('i', 'bi bi-arrow-up-circle-fill'));
        card.appendChild(ico);
        cuerpo = crear('div');
        cuerpo.appendChild(crear('strong', '', ev.entra || ev.jugador || ''));
        var sub = crear('div', 'tl-sub');
        sub.appendChild(crear('i', 'bi bi-arrow-down-circle-fill text-danger'));
        sub.appendChild(document.createTextNode(' ' + (ev.sale || '')));
        cuerpo.appendChild(sub);
        card.appendChild(cuerpo);
        return card;
      case 'penal_fallado':
        card = crear('div', 'tl-card');
        card.appendChild(crear('span', 'tl-ico', '❌'));
        cuerpo = crear('div');
        cuerpo.appendChild(crear('strong', '', ev.jugador || ''));
        cuerpo.appendChild(crear('div', 'tl-sub', 'Penal fallado'));
        card.appendChild(cuerpo);
        return card;
      default: // revisión VAR, igual que en partido.php
        card = crear('div', 'tl-card');
        var tv = crear('span', 'tl-ico');
        tv.appendChild(crear('i', 'bi bi-tv'));
        card.appendChild(tv);
        cuerpo = crear('div');
        cuerpo.appendChild(crear('strong', '', 'Revisión VAR'));
        card.appendChild(cuerpo);
        return card;
    }
  }

  function pintarCronologia(pane, eventos, nuevosIds) {
    pane.textContent = '';
    if (!eventos.length) {
      pane.appendChild(crear('p', 'text-muted mb-0', 'Todavía no hay eventos.'));
      return;
    }
    var tl = crear('div', 'tl');
    eventos.forEach(function (ev) {
      if (ev.tipo === 'medio_tiempo') { tl.appendChild(crear('div', 'tl-break', 'Descanso')); return; }
      var fila = crear('div', 'tl-row' + (nuevosIds[ev.id] ? ' tl-nuevo' : ''));
      var izq = crear('div', 'tl-side tl-left');
      var der = crear('div', 'tl-side tl-right');
      (ev.lado === 'local' ? izq : der).appendChild(tarjetaEvento(ev));
      fila.appendChild(izq);
      fila.appendChild(crear('div', 'tl-min', ev.minuto || ''));
      fila.appendChild(der);
      tl.appendChild(fila);
    });
    pane.appendChild(tl);
  }

  function anunciar(el, texto, enlace) {
    var box = el.querySelector('[data-live="aviso"]');
    if (!box) { return; }
    clearTimeout(box._t);
    box.textContent = texto;
    if (enlace) {
      var a = crear('a', 'ms-2', enlace);
      a.href = window.location.href;
      box.appendChild(a);
    } else {
      box._t = setTimeout(function () { box.hidden = true; }, 9000);
    }
    box.hidden = false;
  }

  function textoAviso(ev) {
    var min = ev.minuto ? ' ' + ev.minuto : '';
    if (ev.tipo === 'roja' || ev.tipo === 'segunda_amarilla') {
      return '🟥 Tarjeta roja: ' + (ev.jugador || '') + min;
    }
    var t = (ev.tipo === 'autogol' ? '⚽ Autogol de ' : '⚽ ¡Gol de ') + (ev.jugador || '') + min;
    if (ev.marcador) { t += ' · ' + ev.marcador.local + '-' + ev.marcador.visita; }
    if (ev.asistente) { t += ' · Asistencia: ' + ev.asistente; }
    return t;
  }

  function pintarEventos(el, p) {
    if (!Array.isArray(p.eventos)) { return; }
    var firma = JSON.stringify(p.eventos);
    if (el._liveFirma === firma) { return; }
    el._liveFirma = firma;

    var primera = !el._liveVistos;                // la 1.ª respuesta no cuenta como "novedad"
    var vistos = el._liveVistos || (el._liveVistos = {});
    var nuevos = {}, ultimo = null;
    p.eventos.forEach(function (ev) {
      if (vistos[ev.id]) { return; }
      vistos[ev.id] = true;
      if (primera) { return; }
      nuevos[ev.id] = true;
      if (/^(gol|gol_penal|autogol|roja|segunda_amarilla)$/.test(ev.tipo)) { ultimo = ev; }
    });

    pintarGoleadores(el, p.eventos);

    var pane = document.querySelector('[data-live="cronologia"]');
    if (pane) {
      pintarCronologia(pane, p.eventos, nuevos);
    } else if (p.eventos.length && !el._liveAvisoSinPanel) {
      // La página se abrió antes de que existiera el detalle (no hay pestañas donde dibujar la línea de tiempo).
      el._liveAvisoSinPanel = true;
      anunciar(el, 'Ya hay cronología y alineaciones de este partido.', 'Ver ahora');
      return;
    }
    if (ultimo) { anunciar(el, textoAviso(ultimo)); }
  }

  // ------------------------------------------------------------------ aplicar una respuesta

  function aplicar(data) {
    var ahoraMs = Date.parse(data.ahora) || Date.now();
    var partidos = data.partidos || {};
    Object.keys(partidos).forEach(function (id) {
      var p = partidos[id];
      (porId[id] || []).forEach(function (el) {
        el.dataset.liveEstado = p.estado;
        if (p.inicio_utc) { el.dataset.liveInicio = p.inicio_utc; }
        pintarBadge(el, p);
        pintarMarcador(el, p);
        pintarApuesta(el, p, ahoraMs);
        pintarEventos(el, p);
      });
    });
  }

  // ------------------------------------------------------------------ conexión

  function estadoConexion(tipo) {
    [].forEach.call(document.querySelectorAll('[data-live="conexion"]'), function (n) {
      n.textContent = '';
      n.className = 'live-status';
      if (tipo === 'ok') {
        n.classList.add('live-on');
        n.textContent = '● Se actualiza solo';
      } else if (tipo === 'error') {
        n.classList.add('live-off');
        n.textContent = '● Sin conexión, reintentando…';
      } else if (tipo === 'sesion') {
        n.classList.add('live-off');
        n.appendChild(document.createTextNode('● Tu sesión expiró. '));
        if (LOGIN) {
          var a = crear('a', '', 'Inicia sesión');
          a.href = LOGIN;
          n.appendChild(a);
        }
      }
    });
  }

  // ------------------------------------------------------------------ ciclo de consulta

  function programar(seg) {
    if (detenido) { return; }
    clearTimeout(timer);
    // ±10 % de variación para que muchos navegadores no consulten en el mismo segundo
    timer = setTimeout(tick, Math.round(seg * 1000 * (0.9 + Math.random() * 0.2)));
  }

  function detener(motivo) {
    detenido = true;
    clearTimeout(timer);
    timer = null;
    estadoConexion(motivo === 'sesion' ? 'sesion' : '');
  }

  function consultar(ids) {
    var url = API + '?ids=' + ids.join(',') + (conEventos(ids) ? '&eventos=1' : '');
    var headers = { 'Accept': 'application/json' };
    if (ultimaV && url === ultimaUrl) { headers['If-None-Match'] = '"' + ultimaV + '"'; }

    var ctl = typeof AbortController === 'function' ? new AbortController() : null;
    var t = setTimeout(function () { if (ctl) { ctl.abort(); } }, TIMEOUT_MS);
    enVuelo = true;

    fetch(url, { headers: headers, credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
      .then(function (res) {
        if (res.status === 401) { detener('sesion'); return 'fin'; }
        if (res.status === 304) { return 'igual'; }
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.json();
      })
      .then(function (data) {
        if (data === 'fin') { return; }
        if (data !== 'igual') {
          if (!data || data.ok !== true) { throw new Error('respuesta inválida'); }
          if (!(data.v && data.v === ultimaV && url === ultimaUrl)) { aplicar(data); }
          ultimaUrl = url;
          ultimaV = data.v || null;
          ultimoPoll = (typeof data.poll === 'number') ? data.poll : null;
        }
        fallos = 0;
        estadoConexion('ok');
        programar(ultimoPoll !== null ? ultimoPoll : SIN_POLL);
      })
      .catch(function (err) {
        if (window.console && console.error) { console.error('[live]', err); }
        fallos++;
        estadoConexion('error');
        programar(Math.min(15 * Math.pow(2, fallos - 1), 120)); // 15, 30, 60, 120, 120...
      })
      .then(function () {
        clearTimeout(t);
        enVuelo = false;
      });
  }

  function tick() {
    timer = null;
    if (detenido || enVuelo || document.hidden) { return; }
    var ids = candidatos();
    if (!ids.length) {
      estadoConexion('');
      if (hayPendientes()) { programar(60); } else { detener(''); }
      return;
    }
    consultar(ids);
  }

  function reanudar() {
    if (detenido || enVuelo || document.hidden) { return; }
    clearTimeout(timer);
    tick();
  }

  // Pestaña oculta = no consultar (ahorra batería y peticiones); al volver, se actualiza de inmediato.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearTimeout(timer); timer = null; } else { reanudar(); }
  });
  window.addEventListener('online', reanudar);

  tick();
})();
