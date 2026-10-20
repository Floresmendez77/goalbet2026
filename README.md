# ⚽ GoalBet2026 — Plataforma de Apuestas Deportivas Simuladas & Suite QA

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL%2FMariaDB-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![Cypress](https://img.shields.io/badge/Cypress-E2E-17202C?style=for-the-badge&logo=cypress&logoColor=white)
[![QA CI](https://github.com/Floresmendez77/goalbet2026/actions/workflows/qa.yml/badge.svg)](https://github.com/Floresmendez77/goalbet2026/actions/workflows/qa.yml)



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
