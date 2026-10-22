# ⚽ GoalBet2026 — Plataforma de Apuestas Deportivas Simuladas & Suite QA

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL%2FMariaDB-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![Cypress](https://img.shields.io/badge/Cypress-E2E-17202C?style=for-the-badge&logo=cypress&logoColor=white)
[![QA CI](https://github.com/Floresmendez77/goalbet2026/actions/workflows/qa.yml/badge.svg)](https://github.com/Floresmendez77/goalbet2026/actions/workflows/qa.yml)

> **Demo en vivo:** _(pendiente: pega aquí la URL de AlwaysData cuando despliegues)_

> [!WARNING]
> **Proyecto educativo y de portafolio.** Todo el dinero y los saldos son **simulados**: no existe ningún procesamiento de pagos reales. El sitio incluye aviso +18 y de juego responsable.

## 📌 Presentación del Proyecto

**GoalBet2026** es una aplicación web full-stack de apuestas deportivas multideporte (Fútbol, Béisbol, Baloncesto, NFL), construida con arquitectura modular en **PHP 8.3 + MySQL/MariaDB**, con tema oscuro estilo casa de apuestas, control estricto de concurrencia y una suite completa de automatización QA.

**Qué demuestra este proyecto:**

- Diseño de base de datos con triggers y procedimientos almacenados idempotentes (`sp_resolver_partido`, `sp_reembolsar_partido`).
- Manejo de concurrencia con transacciones y `SELECT ... FOR UPDATE` (liquidación vs. cancelación simultáneas).
- Seguridad de aplicación: CSRF, rate limiting de login, RBAC, recuperación de contraseña con token de un solo uso, cabeceras HTTP y bloqueo de rutas sensibles.
- Aseguramiento de calidad: plan de pruebas, reportes de hallazgos con evidencia, y pruebas E2E automatizadas con CI.

### 🧑‍💻 Autoría y uso de IA

Diseñado y desarrollado por **Andrew Flores Méndez** como proyecto de portafolio. Para acelerar el desarrollo usé asistentes de IA (generación de código, revisión y depuración); las decisiones de arquitectura, la validación de resultados y la ejecución de las pruebas las realicé y verifiqué yo.

## 📊 Resumen Ejecutivo de QA

| Métrica / Artefacto | Resultado / Cobertura | Estado |
|---|---|---|
| Suite E2E (Cypress) | 5 specs, 11 tests: autenticación, rate limiting, RBAC, apuesta simple, combinada, cancelaciones, recarga + aprobación de admin | ✅ 11/11 pasan |
| Regresión de liquidación | Re-finalizar un partido no duplica pagos ni altera saldo | ✅ Pasó |
| Concurrencia (50 corridas CLI) | Admin (SP) vs. cliente (cancelación), 0 dobles pagos | ✅ Verificado |
| Hallazgos registrados | BUG-001, GAP-002, BUG-003, BUG-004 | ✅ 4/4 resueltos |
| Reporte HTML | Mochawesome con capturas por paso en `qa/reports/` | Generado localmente |
| CI/CD | GitHub Actions (MySQL 8, PHP 8.3, Cypress) | Ver badge arriba |

## 🖼️ Capturas

_(Agrega aquí 3–4 imágenes: landing, listado de partidos, boleto combinado, reporte de Cypress. Guárdalas en `docs/img/` y enlázalas con `![descripción](docs/img/archivo.png)`.)_

## 🔍 Hallazgos de Calidad

Cada hallazgo tiene un reporte técnico con pasos, resultado esperado/real y evidencia.

- 🟢 **[BUG-001](qa/bugs/BUG-001.md)** — Apuestas bloqueadas indefinidamente al posponer, cancelar o suspender partidos. Resuelto con `sp_reembolsar_partido` y un trigger idempotente; incluye el log de las 50 corridas concurrentes.
- 🟢 **[GAP-002](qa/bugs/GAP-002.md)** — Apuestas combinadas (parlay) sin implementar. Resuelto: constructor de boletos en `apostar_combinada.php`, cuota total recalculada en el servidor, transacción atómica con `FOR UPDATE`, y liquidación/reembolso en los SP. Validado por [`03_combinadas_y_cancelacion.cy.js`](qa/cypress/e2e/03_combinadas_y_cancelacion.cy.js).
- 🟢 **[BUG-003](qa/bugs/BUG-003.md)** — Estados `cancelada` y `pagada` inalcanzables. Resuelto y demostrado con `SELECT DISTINCT estado FROM apuestas`.
- 🟢 **[BUG-004](qa/bugs/BUG-004.md)** — Sin opción de cancelar apuestas pendientes. Resuelto: botón en `mis_apuestas.php` con orden estricto de bloqueos y CSRF.

📄 Plan de pruebas maestro y matriz de casos: [`qa/test-plan.md`](qa/test-plan.md)

## 🛠️ Cómo correr el proyecto localmente

**Requisitos:** PHP 8.2+ (extensiones `pdo_mysql`, `mysqli`, `mbstring`), MySQL 8 o MariaDB 10.x, Node.js 20+.

```bash
# 1. Clonar
git clone https://github.com/Floresmendez77/goalbet2026.git
cd goalbet2026

# 2. Crear la base e importar esquema + datos demo
mysql -u root -p -e "CREATE DATABASE goalbet2026 CHARACTER SET utf8mb4;"
mysql -u root -p goalbet2026 < database/goalbet2026.sql
mysql -u root -p goalbet2026 < database/seed_demo.sql

# 3. Configurar entorno
cp seeder/.env.example seeder/.env     # y completa DB_USER, DB_PASS, etc.

# 4. Sembrar usuarios de prueba QA (SOLO en local/CI, nunca en producción)
php seeder/create_qa_users.php

# 5. Levantar el servidor
php -S 127.0.0.1:8000
```

En otra terminal, la suite de pruebas:

```bash
cd qa
npm ci
npx cypress run
```

Test de estrés y concurrencia (50 corridas): `php qa/scripts/test_concurrencia_reembolso.php`

Reporte HTML: abre `qa/reports/index.html`.

> [!NOTE]
> Los usuarios `qa_cliente` y `qa_admin` existen solo para pruebas. **No ejecutes `create_qa_users.php` en producción.** El administrador real se crea con el script CLI del seed, sin contraseñas en claro en el repositorio.

## ⚙️ Integración Continua (CI/CD)

`.github/workflows/qa.yml` se ejecuta en cada push y pull request: levanta MySQL 8, configura PHP 8.3, importa `database/goalbet2026.sql` y `seed_demo.sql`, siembra los usuarios QA, inicia el servidor embebido, corre `npm ci` + Cypress y guarda los reportes y capturas como artefactos descargables.

## 🔒 Seguridad

- Ningún secreto en el repositorio: `seeder/.env` está ignorado y existe `seeder/.env.example` con valores vacíos.
- `.htaccess` fuerza HTTPS y bloquea el acceso web a `qa/`, `seeder/`, `database/` y `tools/`, además de archivos `.env`, `.sql`, `.log` y `.md`.
- Scripts de mantenimiento solo por CLI o con rol administrador.
- Rate limiting de login: 5 fallos por identificador+IP bloquean 15 minutos.
- `cron/sync_cron.php` exige `CRON_SECRET_KEY`; si no está definida, aborta.

## 🚀 Despliegue en AlwaysData

> Probado contra la documentación vigente de AlwaysData. Nota: allí la base de datos es **MariaDB**, y el plan gratuito está limitado a ~100 MB y a uso sin fines de lucro.

1. **Sitio PHP.** En **Web → Sites → Add a site**: tipo `PHP`, versión 8.3 (o 8.2), dirección `tucuenta.alwaysdata.net` y raíz `/home/tucuenta/goalbet2026/`. Activa el acceso SSH en el panel.
2. **Base de datos.** En **Databases → MySQL**, crea la base y un usuario con permisos sobre ella. Host: `mysql-tucuenta.alwaysdata.net`.
3. **Código.** Por SSH: `git clone https://github.com/Floresmendez77/goalbet2026.git` en `/home/tucuenta/`, o súbelo por SFTP (`ssh-tucuenta.alwaysdata.net`). Crea la carpeta de logs:
   ```bash
   mkdir -p seeder/logs && chmod -R 775 seeder/logs
   ```
4. **Importar la base:**
   ```bash
   mysql -h mysql-tucuenta.alwaysdata.net -u tucuenta_usr -p tucuenta_goalbet2026 < database/goalbet2026.sql
   mysql -h mysql-tucuenta.alwaysdata.net -u tucuenta_usr -p tucuenta_goalbet2026 < database/seed_demo.sql
   ```
   Los SQL no usan `DEFINER`, lo que evita el Error 1227.
5. **Entorno.** Crea `seeder/.env` en el servidor:
   ```ini
   APP_ENV=production
   APP_TZ=America/Tegucigalpa
   APP_BASE_PATH=
   MONTO_MAXIMO_APUESTA=5000
   CRON_SECRET_KEY=<32+ caracteres aleatorios>
   DB_HOST=mysql-tucuenta.alwaysdata.net
   DB_PORT=3306
   DB_NAME=tucuenta_goalbet2026
   DB_USER=tucuenta_usr
   DB_PASS=<contraseña>
   FOOTBALL_DATA_API_KEY=<clave nueva>
   API_SPORTS_KEY=<clave nueva>
   THESPORTSDB_API_KEY=3
   ```
6. **HTTPS.** AlwaysData genera y renueva el certificado Let's Encrypt automáticamente para las direcciones del sitio. Activa **Force HTTPS** en **Web → Sites → ⚙️ del sitio → SSL**. El `.htaccess` añade además los encabezados de seguridad (HSTS, CSP, `X-Frame-Options`, etc.).
7. **Tarea programada.** En **Advanced → Scheduled tasks**, crea un comando cada 30–60 minutos:
   ```
   php /home/tucuenta/goalbet2026/cron/sync_cron.php
   ```

## 📄 Licencia y Contacto

Proyecto de portafolio. Si tienes comentarios o quieres conversar sobre él:

- **Andrew Flores Méndez** — [GitHub @Floresmendez77](https://github.com/Floresmendez77)
- _(agrega aquí tu LinkedIn o correo de contacto)_


## ✉️ Correo electrónico (SMTP)

GoalBet envía correos reales con PHPMailer (incluido en `includes/PHPMailer/`, sin Composer): confirmación de correo al registrarse, bienvenida, recuperación de contraseña, aviso de contraseña cambiada y recarga aprobada/rechazada.

1. Importar la migración: `database/migracion_fase1_email.sql` (las cuentas existentes quedan como verificadas).
2. Agregar en `seeder/.env` las claves `MAIL_*`, `APP_PUBLIC_URL` y `EMAIL_VERIFY_REQUIRED` (ver `seeder/.env.example`).
3. Sin SMTP y con `APP_ENV=local` los correos no se envían: se guardan en `seeder/logs/mail_dev.log` y el enlace de confirmación se muestra en pantalla.

### 🔔 Avisos automáticos (favoritos y apuestas)

`cron/avisos_cron.php` envía: recordatorio de partidos de equipos favoritos (3 días, 1 día y 3 horas antes), 11 titular (fútbol), resultado final con lo ganado/perdido en tus apuestas, resultado de combinadas y reembolsos. Cada usuario puede darse de baja desde el enlace de cualquier aviso (`baja_avisos.php`).

1. Importar `database/migracion_fase1b_avisos.sql`.
2. Programar cada 15 minutos: `php /ruta/al/proyecto/cron/avisos_cron.php` (junto a `cron/sync_cron.php`, que es quien actualiza los marcadores).

### 👤 Perfil de usuario

`perfil.php` (menú del usuario → «Mi perfil»): editar nombre/apellido/teléfono, cambiar contraseña pidiendo la actual (con límite de intentos), cambiar correo con confirmación por enlace al correo nuevo (y aviso al anterior) y activar/desactivar los avisos por correo. Requiere importar `database/migracion_fase2_perfil.sql`.

### 📄 Términos y privacidad

`terminos.php` y `privacidad.php` (públicas, enlazadas desde el registro y el pie de página). Al registrarse se guarda la versión aceptada (`usuarios.terminos_version` y `terminos_aceptados_at`). Si cambiás los textos, subí `TERMINOS_VERSION` y `LEGAL_FECHA` en `includes/legal.php`. Requiere importar `database/migracion_fase3_legal.sql` y definir `CONTACT_EMAIL` en `seeder/.env`.

### 🛟 Errores, ayuda y contacto

- `404.php`: página para URLs inexistentes (la activa la regla de `.htaccess`; requiere `mod_rewrite`).
- Errores 500: `includes/errores.php` muestra una página amable con un código de incidente; el detalle queda en `seeder/logs/php_errors.log` (en `APP_ENV=local` también se ve en pantalla).
- `ayuda.php`: preguntas frecuentes y formulario de contacto que llega por correo a `CONTACT_EMAIL` (o `MAIL_FROM` si no está definido). Máximo 5 mensajes por hora por IP.
- Si un administrador desactiva una cuenta, el usuario con sesión abierta es sacado en su próxima petición (`requerir_login()`).

## ⚡ Tiempo real (partidos de fútbol)

### Principio de diseño

**Solo UN proceso (el cron) consulta a ESPN. Los usuarios NUNCA llaman a ESPN**: leen de nuestra base de datos mediante un endpoint JSON liviano. Así el costo de la API depende de cuántos partidos hay en vivo, no de cuántos usuarios hay conectados.

```
ESPN ──(1 consulta/min, solo si hay partidos activos)──> cron/live_cron.php ──> base de datos
                                                                                   │
navegadores ──(sondeo cada 15-60 s, con ETag)──> api/live.php ─────────────────────┘
```

### Etapas

| Etapa | Qué hace | Archivo |
|---|---|---|
| 1 | Cron en vivo: sale de inmediato si no hay partidos activos, con `flock` y una sola sincronización por liga | `cron/live_cron.php` |
| 2 | Minuto actual, marcador y estado «en_curso» + insignia «EN VIVO 67'» | `seeder/Seeders/EspnSeeder.php`, `badge_en_vivo()` |
| 3 | Endpoint JSON de solo lectura (marcador, minuto, goles con asistente, tarjetas, estado) | `api/live.php`, `includes/live_data.php` |
| 4 | JavaScript que consulta el endpoint y actualiza la pantalla sola (marcador, minuto, estado, goles con asistente, tarjetas, cambios) | `public/js/live.js`, `public/css/live.css` |

### Programar el cron (cada minuto)

- Linux / AlwaysData: `* * * * * /usr/bin/php /ruta/al/proyecto/cron/live_cron.php >> /ruta/al/proyecto/seeder/logs/live_cron.out 2>&1`
- Windows (MAMP): Programador de tareas, cada 1 minuto: `C:\MAMP\bin\php\php8.3.1\php.exe C:\MAMP\htdocs\...\cron\live_cron.php`

Un partido está «activo» si está `en_curso` o `programado` con inicio entre −4 h y +15 min (todo en UTC). Si no hay ninguno, el cron no hace ninguna llamada HTTP.

### API: `GET api/live.php`

Requiere sesión iniciada (responde `401` en JSON, no redirige). Solo lee de la base de datos.

| Parámetro | Descripción |
|---|---|
| `ids` | Ids de partido separados por coma (máx. 50). Obligatorio. |
| `eventos=1` | Agrega la línea de tiempo. Solo con **un** partido. |

```json
{
  "ok": true,
  "poll": 15,
  "partidos": {
    "123": {
      "estado": "en_curso", "en_vivo": true, "minuto": "67'",
      "marcador": { "local": 2, "visita": 1 },
      "inicio_utc": "2026-10-22T20:00:00Z", "poll": 15,
      "eventos": [
        { "id": 1107, "orden": 3, "minuto": "55'", "tipo": "gol", "lado": "visita",
          "jugador": "James Justin", "asistente": "Gabriel Gudmundsson",
          "marcador": { "local": 0, "visita": 1 } }
      ]
    }
  },
  "v": "e75a606003e3b3f116fa", "ahora": "2026-10-22T21:05:11Z"
}
```

- `poll`: cada cuántos **segundos** conviene volver a preguntar (15 en vivo, 30 en la ventana del cron, 60 si falta menos de 2 h). `null` = no hace falta seguir preguntando (finalizado o falta mucho).
- **ETag**: si nada cambió, responde `304` sin cuerpo (enviar `If-None-Match`).
- Tipos de evento: `gol`, `gol_penal`, `autogol`, `penal_fallado`, `amarilla`, `roja`, `segunda_amarilla`, `cambio` (`entra` / `sale`), `var`, `medio_tiempo`.
- Errores (JSON): `401 no_autenticado | cuenta_desactivada`, `400 ids_requeridos | ids_invalidos | demasiados_ids | eventos_requiere_un_solo_partido`, `405 metodo_no_permitido`, `500 error_interno` (con `codigo` de incidente que aparece en `seeder/logs/php_errors.log`).

### Pantalla en vivo (etapa 4): `public/js/live.js`

Lo carga `includes/footer.php` en todas las páginas, pero **no hace nada** si la página no tiene elementos `data-live-partido` (los pone `live_attrs()` en `partido.php`, en la lista de `partidos.php` y en las tarjetas del día de la vista calendario).

- Consulta `api/live.php` con los partidos que importan: los `en_curso` y los `programado` dentro de la ventana −4 h … +2 h (máx. 50). En `partido.php` pide además `eventos=1`.
- Respeta el `poll` del servidor (15 / 30 / 60 s, con ±10 % de variación para no sincronizar a todos los navegadores). Usa `If-None-Match` con la huella `v`: si nada cambió, el servidor responde `304` y la pantalla no se toca.
- Pestaña oculta = no consulta; al volver a verla, actualiza al instante. Si falla la red o el servidor, reintenta con espera creciente (15 → 30 → 60 → 120 s). Con `401` se detiene y ofrece «Inicia sesión».
- Cuando ya no queda ningún partido en vivo o por empezar, deja de consultar por completo.
- Todo el texto se inserta con `textContent` (nunca `innerHTML`), así que un nombre de jugador con HTML no puede inyectar nada.
- Un gol, una roja o un cambio nuevo se resalta en la línea de tiempo y en `partido.php` aparece un aviso «⚽ ¡Gol de … · Asistencia: …» (los eventos que ya existían al abrir la página no se anuncian).
- Solo pinta; no apuesta ni liquida nada. Las apuestas se siguen resolviendo únicamente con el trigger `trg_partidos_resolver_apuestas`.

### Probar sin esperar un partido real

```sql
-- 1) Elegí un partido de fútbol programado
SELECT id, fecha_partido FROM partidos WHERE estado = 'programado' AND id_deporte = 1 ORDER BY fecha_partido LIMIT 5;
-- 2) Ponelo "en vivo" (cambiá 123 por su id)
UPDATE partidos SET estado = 'en_curso', marcador_local = 1, marcador_visita = 0, minuto_actual = '34''' WHERE id = 123;
-- 3) Agregale un gol con asistencia
INSERT INTO partido_eventos (id_partido, espn_event_id, orden, minuto, tipo, id_equipo, jugador, jugador2, marcador_local, marcador_visita)
SELECT id, 'TEST1', 1, '34''', 'gol', id_equipo_local, 'Jugador Prueba', 'Asistente Prueba', 1, 0 FROM partidos WHERE id = 123;
-- 4) Con sesión iniciada abrí:  /api/live.php?ids=123&eventos=1
-- 5) Dejalo como estaba
DELETE FROM partido_eventos WHERE id_partido = 123 AND espn_event_id = 'TEST1';
UPDATE partidos SET estado = 'programado', marcador_local = NULL, marcador_visita = NULL, minuto_actual = NULL WHERE id = 123;
```

> No pases el partido de prueba a `finalizado`: el trigger liquida las apuestas reales de ese partido.

Para ver la etapa 4: **pausá el cron en vivo mientras probás** (si no, ESPN puede pisar tus cambios manuales), abrí `partido.php?id=123` y `partidos.php?vista=lista` con sesión iniciada y ejecutá los pasos 2 y 3 en phpMyAdmin. En ≤ 15 s la pantalla cambia sola. Cambiá el minuto, el marcador o agregá otro gol y mirá cómo se actualiza sin recargar.
