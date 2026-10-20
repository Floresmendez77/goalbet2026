# Plan de Pruebas Maestro & Matriz de Casos · GoalBet2026

**Proyecto**: GoalBet2026 — Plataforma Multideporte de Apuestas  
**Módulo Auditoría**: Control de Calidad, Integración BD & Automatización E2E  
**Autor**: Analista QA / Control de Calidad  
**Fecha de Actualización**: Septiembre 2026  
**Estado**: Finalizado & Verificado  

---

## 1. Introducción y Objetivos

Este Plan de Pruebas define la estrategia de aseguramiento de calidad para **GoalBet2026**, una aplicación web de apuestas deportivas desarrollada en **PHP 8.3 + MySQL 8.0 + Bootstrap 5**.

### Objetivos Clave:
1. Validar la integridad del flujo de apuestas (monto mínimo, saldo disponible, revalidación de cuotas en backend).
2. Verificar la ejecución y consistencia de la liquidación automática de apuestas vía Triggers y Stored Procedures en MySQL.
3. Evaluar el comportamiento del sistema ante cambios de estado administrativo de los eventos deportivos.
4. Identificar y documentar fallos funcionales, inconsistencias de base de datos y brechas de diseño (*gaps*).
5. Automatizar un **Smoke Test E2E** crítico con Cypress e integrar reporte HTML interactivo con capturas.
6. Realizar pruebas empíricas de regresión (ej. re-guardado de resultados de partidos).

---

## 2. Alcance y Entorno de Pruebas

### 2.1 En Alcance
- **Módulo Autenticación & Usuario**: Registro, inicio de sesión, sesiones PHP y control de roles (`cliente` vs `admin`).
- **Módulo de Partidos & Visualización**: Filtros por deporte, estado y ordenamiento cronológico.
- **Módulo de Apuestas Simples**: Selección de cuota, validación de concurrencia (`FOR UPDATE`), descuento de saldo y generación de referencias.
- **Módulo Admin & Liquidación**: Cambio de estado de partidos, carga de marcadores finales, ejecución del trigger `trg_partidos_resolver_apuestas` y stored procedure `sp_resolver_partido`.
- **Módulo de Recargas**: Solicitud de clientes y aprobación/rechazo por administradores con transacción SQL.

### 2.2 Entorno Técnico Verificado
- **Servidor Web**: PHP 8.3.1 (Built-in Server en `http://localhost:8000`).
- **Base de Datos**: MySQL 8.0 / MariaDB (MAMP local) con importación de `goalbet2026.sql`.
- **Framework de Automatización**: Cypress 16.0.0 + `cypress-mochawesome-reporter`.
- **Usuarios de Prueba**:
  - `qa_cliente` (Cliente con $1,000.00 de saldo acreditado).
  - `qa_admin` (Administrador de sistema).

---

## 3. Clasificación de Severidad y Tipo de Hallazgos

| Tipo | Descripción |
| :--- | :--- |
| **Bug Funcional** | Comportamiento anómalo en el código PHP, procedimiento SQL o trigger que incumple la regla de negocio. |
| **Gap de diseño / Feature no implementada** | Funcionalidad presente en el esquema de BD o UI pero carente de implementación en el backend. |

| Severidad | Descripción |
| :--- | :--- |
| **Crítica / Alta** | Bloqueo de dinero de usuarios, fallos en la liquidación de saldos o transacciones SQL corruptas. |
| **Media** | Funciones inaccesibles, inconsistencias de estado o falta de opciones administrativas básicas. |
| **Baja** | Inconvenientes menores de UI/UX sin impacto financiero ni de datos. |

---

## 4. Matriz de Casos de Prueba

| ID | Módulo | Precondición | Pasos de Prueba | Resultado Esperado | Prioridad | Estado |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **TC-AUT-01** | Autenticación | Usuario registrado | 1. Ir a `/login.php`<br>2. Ingresar credenciales válidas (`qa_cliente`) | Inicio de sesión exitoso, sesión PHP creada y redirección a `/dashboard.php`. | Alta | **PASÓ** |
| **TC-AUT-02** | Autenticación | Ninguna | 1. Ir a `/register.php`<br>2. Completar formulario con email único<br>3. Enviar | Registro exitoso, contraseña hasheada (`PASSWORD_DEFAULT`) y rol asignado `cliente`. | Alta | **PASÓ** |
| **TC-AUT-03** | Autenticación | Usuario cliente logueado | 1. Intentar ingresar a `/admin/index.php` directamente | Acceso denegado, redirección a `/dashboard.php` con mensaje flash de error. | Alta | **PASÓ** |
| **TC-PAR-01** | Partidos | Partidos cargados en BD | 1. Ir a `/partidos.php`<br>2. Filtrar por deporte (Ej: Fútbol) | Listado filtrado correctamente mostrando partidos programados del deporte seleccionado. | Media | **PASÓ** |
| **TC-APU-01** | Apuestas | Usuario logueado con $1,000 | 1. Seleccionar partido programado<br>2. Elegir opción Local ($50.00)<br>3. Confirmar | Apuesta registrada en DB, saldo reducido a $950.00, transacción tipo `apuesta` creada. | Alta | **PASÓ** |
| **TC-APU-02** | Apuestas | Partido programado | 1. Modificar cuota HTML en cliente a 99.00<br>2. Enviar formulario de apuesta | El backend re-consulta la cuota real en BD, detecta la discrepancia y rechaza la apuesta. | Alta | **PASÓ** |
| **TC-APU-03** | Apuestas | Saldo de $10.00 | 1. Intentar apostar $20.00 en partido programado | Validación de saldo insuficiente activa, detiene la transacción y alerta al cliente. | Alta | **PASÓ** |
| **TC-APU-04** | Apuestas | Saldo de $100.00 | 1. Intentar apostar $5.00 (menor al mínimo $20.00) | Validación de monto mínimo activa, alerta al usuario. | Media | **PASÓ** |
| **TC-LIQ-01** | Liquidación | Apuestas pendientes | 1. Admin entra a `/admin/partido_editar.php`<br>2. Carga marcador 2-1 y finaliza | Trigger `trg_partidos_resolver_apuestas` ejecuta `sp_resolver_partido`, paga a ganadores y crea transacciones. | Alta | **PASÓ** |
| **TC-REG-01** | Regresión Liquidación | Partido ya finalizado y pagado | 1. Admin vuelve a hacer clic en "Guardar y finalizar" con el mismo resultado 2-1 | **PRUEBA EMPÍRICA**: `sp_resolver_partido` solo procesa apuestas `pendiente`. NO se genera un 2do pago ni se altera el saldo. | Alta | **PASÓ** |
| **TC-REC-01** | Recargas | Cliente logueado | 1. Solicitar recarga de $500 en `/recargar.php` | Solicitud creada con estado `pendiente` y referencia `REC-...`. Saldo del cliente se mantiene. | Alta | **PASÓ** |
| **TC-REC-02** | Recargas Admin | Recarga pendiente | 1. Admin ingresa a `/admin/recargas.php`<br>2. Hace clic en "Aprobar" | Transacción SQL atómica: `usuarios.saldo` incrementa en $500, `recargas.estado = aprobada` y transacción `deposito` creada. | Alta | **PASÓ** |
| **TC-BUG-01** | Admin / Estados | Apuestas activas en partido | 1. Admin cambia estado de partido a `pospuesto` o `cancelado` | **FALLO IDENTIFICADO**: Las apuestas quedan en `pendiente` de por vida y el dinero bloqueado sin reembolso. | Alta | **FALLÓ ([BUG-001](bugs/BUG-001.md))** |
| **TC-GAP-01** | Apuestas Combinadas | Usuario explora la app | 1. Intentar armar un parlay / boleto combinado con 2+ partidos | **GAP DE DISEÑO**: La tabla `boletos` existe en BD y el badge en UI, pero no hay código PHP para crear o procesar boletos. | Media | **FALLÓ ([GAP-002](bugs/GAP-002.md))** |
| **TC-BUG-02** | Consistencia BD | Ninguna | 1. Consultar estados de apuestas en BD (`SELECT DISTINCT estado FROM apuestas`) | **FALLO IDENTIFICADO**: Los estados `cancelada` y `pagada` jamás se asignan en el código, pero existen en la UI. | Media | **FALLÓ ([BUG-003](bugs/BUG-003.md))** |

---

## 5. Caso Destacado de Pruebas de Regresión (Empírico)

### Protocolo de Verificación `TC-REG-01`:
1. **Escenario**: Partido ID 2 (`Hull City vs Manchester United`) con apuesta de $100.00 a opción `Local` (cuota 2.00, ganancia potencial $200.00).
2. **Primera Finalización**: Admin establece marcador `2 - 1` y estado `finalizado`.
   - *Resultado*: Apuesta pasa a `ganada`. Saldo del cliente aumenta de $700.00 a $900.00. Transacción `PAGO-2-x` registrada.
3. **Re-guardado de Resultado**: Admin vuelve a enviar el formulario de resultado `2 - 1` en `/admin/partido_editar.php`.
   - *Resultado Observado*: El procedimiento `sp_resolver_partido` filtra explícitamente por `WHERE estado = 'pendiente'`, por lo que **no vuelve a pagar ni acreditar saldo**.
   - *Evidencia SQL*: Total de transacciones de pago para el partido 2 = 1. Saldo se mantiene exacto en $900.00.

---

## 6. Conclusiones del Plan de Pruebas

El sistema **GoalBet2026** presenta una sólida arquitectura de apuestas simples y liquidación de partidos finalizados con control de concurrencia SQL (`FOR UPDATE`). Sin embargo, se identificaron **2 Bugs Funcionales críticos/medios** en el manejo de eventos pospuestos/cancelados y estados inalcanzables, así como **1 Gap de Diseño importante** en la funcionalidad de parlays/combinadas.
