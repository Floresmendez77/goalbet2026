# ⚽ GoalBet2026 — Plataforma de Apuestas Deportivas & Suite QA Portfolio

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)
![Cypress](https://img.shields.io/badge/Cypress-16.0-17202C?style=for-the-badge&logo=cypress&logoColor=white)
![GitHub Actions](https://img.shields.io/badge/GitHub_Actions-CI%2FCD-2088FF?style=for-the-badge&logo=githubactions&logoColor=white)

---

## 📌 Presentación del Proyecto & Autoría

**GoalBet2026** es una aplicación web full-stack de apuestas deportivas multideporte (Fútbol, Béisbol, Baloncesto, NFL) construida con arquitectura modular en **PHP 8.3 + MySQL** con tema oscuro premium estilo casa de apuestas deportiva, control estricto de concurrencia y suite completa de automatización QA.

> [!IMPORTANT]  
> **Declaración de Autoría**: La aplicación completa, el rediseño UI/UX deportivo, la arquitectura de base de datos con triggers y procedimientos almacenados (incluyendo la resolución de concurrencia y mitigación de deadlocks), así como todo el trabajo de **Aseguramiento de Calidad (QA)**, diseño del Plan de Pruebas, documentación empírica de fallos y la suite de automatización E2E con Cypress y CI/CD son de **mi autoría exclusiva**. Este proyecto sirve como pieza de portfolio profesional para vacantes de **Analista de QA / Control de Calidad / QA Automation Engineer / Senior Full-Stack DevOps**.

---

## 📊 Resumen Ejecutivo de QA & Resultados

| Métrica / Artefacto | Resultado / Cobertura | Estado |
| :--- | :--- | :--- |
| **Casos de Prueba Diseñados** | Cobertura completa de Autenticación, Rate Limiting, RBAC, Apuestas Simples, Combinadas (Parlay), Cancelaciones, Recargas y Admin | **100% Ejecutados** |
| **Prueba de Regresión (Liquidación)** | Confirmado que re-finalizar un partido **no duplica pagos** ni altera saldo | **PASÓ** |
| **Prueba Empírica de Concurrencia (50 runs)** | 50 iteraciones reales CLI (Admin SP vs Cliente Cancelación). **0 Dobles Pagos** | **VERIFICADO** |
| **Suite Automatizada E2E (Cypress)** | Cobertura modular E2E en `qa/cypress/e2e/` (Auth, Rate Limiting, RBAC, Simples, Combinadas, Cancelaciones, Recarga + Aprobación) | **100% PASS** |
| **Hallazgos Totales Registrados** | 4 Hallazgos Totales Resueltos (BUG-001, GAP-002, BUG-003, BUG-004) | **100% Resueltos** |
| **Reporte HTML Interactivo** | Mochawesome HTML con screenshots por paso embebidos en `qa/reports/` | **Generado** |
| **Pipeline de CI/CD** | Workflow GitHub Actions con MySQL 8, PHP 8.3 y Cypress sobre `database/goalbet2026.sql` | **Configurado** |

---

## 🔍 Hallazgos de Calidad & Reportes Integrados

Los hallazgos fueron verificados y corregidos con evidencia empírica. Hacé clic en cada enlace para ver el reporte técnico completo:

1. 🟢 **[BUG-001: Apuestas bloqueadas indefinidamente al posponer, cancelar o suspender partidos](qa/bugs/BUG-001.md)**  
   *Tipo: Bug Funcional | Estado: Resuelto*  
   Resuelto vía Stored Procedure `sp_reembolsar_partido` y Trigger idempotente en MySQL. Incluye log empírico completo de las 50 corridas concurrentes.

2. 🟢 **[GAP-002: Funcionalidad de apuestas combinadas / parlays (`boletos`)](qa/bugs/GAP-002.md)**  
   *Tipo: Feature / Arquitectura | Estado: Resuelto*  
   Implementado constructor de boletos en [`apostar_combinada.php`](apostar_combinada.php), re-cálculo seguro de cuota multiplicada server-side, transacción atómica con `SELECT ... FOR UPDATE`, liquidación y reembolso atómico en `sp_resolver_partido` y `sp_reembolsar_partido`, y validación automatizada mediante tests Cypress en [`qa/cypress/e2e/03_combinadas_y_cancelacion.cy.js`](qa/cypress/e2e/03_combinadas_y_cancelacion.cy.js).

3. 🟢 **[BUG-003: Estados 'cancelada' y 'pagada' de apuestas inalcanzables en la lógica del sistema](qa/bugs/BUG-003.md)**  
   *Tipo: Bug Funcional / Inconsistencia | Estado: Resuelto*  
   Estado `'cancelada'` activado y usado en todo el ciclo de vida. Demostrado con consulta `SELECT DISTINCT estado FROM apuestas`.

4. 🟢 **[BUG-004: Ausencia de opción para cancelar apuestas pendientes antes del inicio](qa/bugs/BUG-004.md)**  
   *Tipo: Gap de UX / Regla de Negocio | Estado: Resuelto*  
   Implementado botón **"Cancelar apuesta"** en [`mis_apuestas.php`](mis_apuestas.php) con orden estricto de bloqueos (`usuarios` FOR UPDATE -> `apuestas` FOR UPDATE) y CSRF.

📄 **[Consultar el Plan de Pruebas Maestro completo y la Matriz de Casos (`qa/test-plan.md`)](qa/test-plan.md)**

---

## 🛠️ Cómo Correr las Pruebas Localmente

### Requisitos Previos:
- **PHP 8.2+ o 8.3** (con extensiones `pdo_mysql`, `mysqli`, `session`, `mbstring`)
- **MySQL 8.0+**
- **Node.js 20+ & npm**

### Pasos de Instalación:

1. **Clonar el repositorio**:
   ```bash
   git clone https://github.com/tu-usuario/goalbet2026.git
   cd goalbet2026
   ```

2. **Importar la Base de Datos**:
   Importá el esquema canónico completo (incluye triggers y procedimientos sin DEFINER) y los datos demo iniciales:
   ```bash
   mysql -u root -p goalbet2026 < database/goalbet2026.sql
   mysql -u root -p goalbet2026 < database/seed_demo.sql
   ```

3. **Configurar el Entorno (`.env`)**:
   Copiá la plantilla y ajustá tus credenciales locales:
   ```bash
   cp seeder/.env.example seeder/.env
   ```

4. **Sembrar Usuarios de Prueba y Fixtures QA**:
   Inicializá los usuarios `qa_cliente` (saldo inicial L. 1,000) y `qa_admin` (saldo L. 5,000):
   ```bash
   php seeder/create_qa_users.php
   ```

5. **Levantar el Servidor Web**:
   ```bash
   php -S 127.0.0.1:8000
   ```

6. **Ejecutar la Suite de Pruebas Automatizadas con Cypress**:
   En otra ventana de terminal:
   ```bash
   cd qa
   npm ci
   npx cypress run
   ```

7. **Ejecutar el Test de Estrés y Concurrencia (50 corridas CLI)**:
   ```bash
   php qa/scripts/test_concurrencia_reembolso.php
   ```

8. **Ver el Reporte HTML**:
   Abrí `qa/reports/index.html` en tu navegador preferido.

---

## ⚙️ Integración Continua (CI/CD)

El proyecto cuenta con un flujo automatizado de **GitHub Actions** en `.github/workflows/qa.yml` que en cada `push` o `pull_request`:
1. Levanta un servicio con **MySQL 8.0**.
2. Configura **PHP 8.3** con todas las extensiones requeridas.
3. Importa el esquema limpio [`database/goalbet2026.sql`](database/goalbet2026.sql) y el seed demo [`database/seed_demo.sql`](database/seed_demo.sql).
4. Genera el entorno de prueba `seeder/.env` y siembra usuarios y partidos con `php seeder/create_qa_users.php`.
5. Inicia el servidor embebido PHP en `127.0.0.1:8000`.
6. Instala dependencias con `npm ci` y ejecuta la suite completa de Cypress.
7. Almacena reportes Mochawesome y capturas de pantalla como artefactos descargables.

---

## 🚀 Guía de Despliegue en Producción (AlwaysData)

### 1. Creación de Cuenta y Sitio PHP
1. En el panel de AlwaysData, dirigirse a **Web → Sites → Add a site**.
2. Configurar:
   - **Type**: `PHP`
   - **PHP Version**: `8.3` (o `8.2`)
   - **Addresses**: Tu dominio o subdominio asignado (`tucuenta.alwaysdata.net`).
   - **Document Root**: `/home/tucuenta/goalbet2026/` (directorio raíz del proyecto donde residen `index.php` y `.htaccess`).

### 2. Base de Datos MySQL
1. En **Databases → MySQL**:
   - Crear una nueva base de datos (ej. `tucuenta_goalbet2026`).
   - Crear un usuario MySQL con contraseña segura y otorgarle todos los permisos sobre dicha base.
   - Anotar el host MySQL provisto por AlwaysData (típicamente `mysql-tucuenta.alwaysdata.net`).
2. Importar el esquema y el seed inicial (vía phpMyAdmin o terminal SSH):
   ```bash
   mysql -h mysql-tucuenta.alwaysdata.net -u tucuenta_usr -p tucuenta_goalbet2026 < database/goalbet2026.sql
   mysql -h mysql-tucuenta.alwaysdata.net -u tucuenta_usr -p tucuenta_goalbet2026 < database/seed_demo.sql
   ```
   > [!NOTE]
   > Ambos archivos SQL fueron diseñados sin cláusula `DEFINER`, lo que previene errores de permisos (Error 1227) en entornos AlwaysData.

### 3. Subida del Código y Configuración de Entorno
1. Subir el proyecto mediante Git (`git clone`) o SFTP a `/home/tucuenta/goalbet2026/`.
2. Crear el archivo `seeder/.env` en el servidor con los valores de producción:
   ```ini
   DB_HOST=mysql-tucuenta.alwaysdata.net
   DB_PORT=3306
   DB_NAME=tucuenta_goalbet2026
   DB_USER=tucuenta_usr
   DB_PASS=tu_password_seguro
   APP_ENV=production
   APP_URL=https://tucuenta.alwaysdata.net
   APP_BASE_PATH=
   APP_TZ=America/Tegucigalpa
   CRON_SECRET_KEY=clave_aleatoria_segura_de_mas_de_32_caracteres
   MONTO_MAXIMO_APUESTA=5000
   API_FOOTBALL_KEY=tu_nueva_api_key_rotada
   API_SPORTS_KEY=tu_nueva_api_key_rotada
   ```
3. Verificar permisos de escritura en la carpeta de logs:
   ```bash
   chmod -R 775 seeder/logs/
   ```

### 4. Certificado SSL / HTTPS
1. En **SSL / Certificates**, solicitar el certificado gratuito Let's Encrypt para el dominio.
2. El archivo `.htaccess` en la raíz ya incluye la regla de redirección 301 para forzar HTTPS y encabezados de seguridad (`HSTS`, `X-Content-Type-Options`, `X-Frame-Options`, `CSP`).

### 5. Tarea Programada (Cron Job)
1. En **Advanced → Scheduled tasks → Add a scheduled task**:
   - **Command**: `php /home/tucuenta/goalbet2026/cron/sync_cron.php`
   - **Frequency**: Cada 30 o 60 minutos (`*/30 * * * *`).
   - El script valida internamente `CRON_SECRET_KEY` impidiendo ejecuciones no autorizadas.

---

## 📄 Licencia & Contacto

Desarrollado para demostración de competencias de **Senior Full-Stack, QA Engineering, Concurrencia de Datos, Automatización E2E y DevOps**.
