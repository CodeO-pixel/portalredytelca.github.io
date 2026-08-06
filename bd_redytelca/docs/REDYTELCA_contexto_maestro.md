# DOCUMENTO MAESTRO DE CONTEXTO — PROYECTO REDYTELCA
## Para continuidad en otra sesión de Claude

**Instrucción de uso:** Pega este documento completo como primer mensaje en la nueva conversación, junto con el archivo de contexto del código fuente (`redytelca-context.txt` o equivalente actualizado). Este documento reemplaza la necesidad de re-explicar el proyecto desde cero.

---

## PARTE 1 — ROL Y PROTOCOLO DE TRABAJO (system prompt del usuario)

Actúa como **Arquitecto de Software Senior y Auditor de Código de Élite**. Reglas no negociables:

1. **Fidelidad absoluta al dominio del código (zero-assumption):** nunca asumas nomenclatura "estándar". Si una tabla/variable existente tiene un nombre específico, se respeta exactamente. Si un cambio de nombre es obligatorio por una corrección, se identifican y modifican TODOS los archivos afectados por ese acoplamiento.
2. **Análisis de impacto antes de código:** cada cambio se evalúa primero por su efecto mecánico/estructural en todo el ecosistema del proyecto.
3. **Prohibido código en fragmentos.** Cada entrega debe ser el **archivo completo de principio a fin**, listo para copiar-pegar y reemplazar el original. Nunca `// resto del código aquí`.
4. **Documentar el porqué** al final de cada archivo entregado: qué corrección específica se está resolviendo y su justificación mecánica.
5. **Entrega en el propio chat**, no como archivo descargable — el usuario copia y pega directo a su IDE.
6. **Tono/estándar de consultoría:** excelencia técnica, terminología precisa, mentalidad crítica (cuestionar si algo viola principios de ingeniería), sin complacencia. Contexto: tesis universitaria en Maracaibo, Venezuela, con estándares de ingeniería globales.

---

## PARTE 2 — QUÉ ES REDYTELCA

Sistema de gestión administrativa para un ISP (Internet Service Provider) pequeño/mediano, desarrollado como **proyecto de tesis**. Stack: PHP puro (sin framework) + PDO + MySQL/MariaDB, frontend vanilla JS (SPA en `index.html` + `app.js`, sin build tools), estilo propio en `styles.css`.

**Alcance explícitamente descartado por decisión del usuario:** integración real con hardware de red (OLT vía SNMP/TR-069/API propietaria tipo Mikrotik/Huawei). Es decisión consciente de alcance de tesis, no una limitación técnica no resuelta — cualquier propuesta de automatización de red debe descartarse salvo que el usuario lo pida explícitamente.

**Módulos funcionales existentes al inicio de la auditoría:**
- Autenticación de staff (Admin/Operador) con RBAC dinámico por módulo/vista
- Gestión de clientes (CRUD)
- Infraestructura de red como *inventario*, no automatización: Nodos → OLTs → NAPs, con mapa (Google Maps embed) posicionando elementos por coordenadas
- Tickets de soporte
- Tareas internas
- Reportes básicos (dashboard con datos parcialmente simulados/hardcodeados)
- `portal_cliente.php` — existía como **mockup HTML estático sin backend real**

---

## PARTE 3 — HALLAZGOS DE LA AUDITORÍA INICIAL

### 3.1 Hallazgo crítico: dos sistemas RBAC duplicados

**Sistema A — el real, en producción:**
```
usuarios → rol → modulos → paginas → rol_modulo_pagina
```
Autenticación por token en tabla `sesiones`, transmitido por header `X-Session-Token` con fallback a query string. Consumido por: `login.php`, `auth_state.php`, `logout.php`, `permisos_listar.php`, `permisos_guardar.php`, `roles_listar.php` (todos en raíz del proyecto), y `app.js` (`renderMenu()`, `showPage()`, `isAdminRole()`).

**Sistema B — código muerto, nunca alcanzado por el usuario final:**
```
usuarios(?) → roles → permissions → role_permission → user_role
             permisos → rm_permiso
             user (tabla paralela, nunca poblada por el login real)
```
Vivía en: `api/roles.php`, `api/permissions.php`, `api/role_permissions.php`, `api/clientes_listar.php`, `includes/auth.php` (funciones `has_permission()`/`require_permission()` basadas en `$_SESSION['id_rol']`, variable de sesión PHP nativa **nunca sincronizada** con el flujo real de tokens), y `roles_admin.php` (pantalla HTML sin ningún enlace desde `index.html`/sidebar — solo alcanzable tecleando la URL exacta, y aun así fallaría con 401).

Origen: `migrations/001_create_rbac_tables.sql`, nunca ejecutada por ningún script de arranque real (`run_migrations.php` apunta a `002_rbac_dinamico_seed.sql`, el sembrador del Sistema A).

### 3.2 Hallazgo: `portal_cliente.php` no tiene NINGÚN RBAC conectado

Verificado explícitamente a petición del usuario. El archivo original:
- No tiene `require 'conexion.php'` — no toca la base de datos
- No tiene `session_start()` ni validación de sesión
- El formulario apunta a `action="/api/pagar.php"`, endpoint **inexistente** en todo el árbol
- El JS inline solo hace `preventDefault()` y muestra un mensaje fijo de "Solicitud recibida" sin enviar nada

Conclusión: es un mockup visual puro, desconectado de ambos sistemas RBAC y de la BD.

### 3.3 Hallazgo: tres "rutas" completamente desconectadas entre sí

Vía `router.php`:

| Pieza | Auth | Conectada a BD | Alcanzable por navegación normal |
|---|---|---|---|
| `index.html` + `app.js` (SPA admin) | Sistema A | Sí | Sí — `/admin` o `/` |
| `portal_cliente.php` | Ninguna | No | Sí — `/cliente`, `/portal`, `/pagos`, `/pago` |
| `roles_admin.php` | Sistema B (roto) | Sí, pero contra tablas muertas | No — huérfana |

### 3.4 Hallazgo: esquema "fantasma" — el dump SQL no refleja producción

`bd_redytelca.sql` define cosas que el código real sobreescribe en cada request vía `ALTER TABLE`/`ensureColumn()` repetido en 6+ archivos distintos (`api_naps.php`, `api_olts.php`, `api_tickets.php`, `clientes_listar.php`, `cliente_actualizar.php`, `registrar_cliente.php`). Ejemplos:
- `tickets.estado` es `ENUM('no leido','leido','resuelto')` en el dump, pero en runtime se fuerza a `VARCHAR(30) DEFAULT 'Abierto'` con valores `Abierto`/`En proceso`/`Cerrado`
- `olts.codigo` no existe en el dump, se añade vía `ensureColumn()`
- `servicios.id_naps` no existe en el dump, se añade vía `ensureColumn()` + FK dinámica
- La tabla `sesiones` (crítica para que el login funcione) **no existe en el dump en absoluto**

Esto es deuda técnica de "auto-reparación en runtime" en vez de una fuente de verdad única.

### 3.5 Hallazgo: tablas con esquema definido pero cero backend (dead schema)

| Tabla | Estado | Evidencia |
|---|---|---|
| `equipos` | Muerta, reconocida por comentario propio del código | Comentario textual en `api_naps.php`: *"nunca se llena porque no existe ningún api_equipos.php ni formulario que inserte ahí — es una tabla muerta en la práctica actual del sistema"* |
| `pagos` | Muerta | Cero endpoints la tocan |
| `notificaciones` | Muerta | FK a `clientes` existe, cero uso |
| `logs` | Muerta | Cero `INSERT` en cualquier flujo |
| `planes` | Viva pero decorativa | Solo 1 fila fija (`id_plan=1`, "Plan Básico") auto-sembrada. Sin CRUD, sin selector en UI |
| `direcciones` | Viva pero sobre-normalizada | Solo se usa `calle_avenida` como texto libre. `sector`, `n_casa_apto`, `punto_referencia`, `latitud`, `longitud` están muertos en la práctica |

### 3.6 Comparativa contra estándar de industria (Splynx, UISP CRM, Sonar, WispHub, Powercode)

Dominios funcionales estándar de un ISP CRM/Billing (excluyendo aprovisionamiento automático de red, fuera de alcance por decisión de tesis):
1. Customer Management — parcial en REDYTELCA
2. Service/Subscription Management — esqueleto sin gestión real de planes
3. **Billing & Payments — brecha más grave, tabla existe, funcionalidad cero**
4. Ticketing/Helpdesk — implementado y funcional
5. Field Operations/Tasks — implementado y funcional
6. Network Inventory (sin automatización) — implementado salvo `equipos`
7. RBAC + Audit Trail — RBAC funcional (Sistema A), auditoría inexistente
8. Notifications — inexistente
9. Customer Self-Service Portal — mockup sin conexión real
10. Reports/Dashboard — implementado con datos parcialmente simulados (ej. `ticketsAbiertos` calculado con fórmula matemática ficticia `18 + (totalClientes % 5) - 2`, no `COUNT()` real)

---

## PARTE 4 — DECISIONES CERRADAS CON EL USUARIO (todas confirmadas, no reabrir sin motivo)

### 4.1 Portal de pagos — arquitectura de integración
**Decisión: Single source of truth.** REDYTELCA es el sistema de registro (`pagos`/`facturas` viven aquí). El portal de cliente NO tiene su propia verdad de datos financieros — solo actúa como cliente que reporta transacciones vía endpoint con autoridad limitada.

Regla de negocio clave: el portal **nunca** puede marcar un pago como `validado`. Esa transición es exclusiva de staff con rol Administrador desde el backoffice (módulo Finanzas nuevo).

### 4.2 `equipos` — inventario manual, SIN automatización de OLT
**Decisión: mantener y activar como inventario manual puro.** Distinción técnica clave explicada al usuario: automatización real de OLT = SNMP/TR-069/API propietaria contra hardware (fuera de alcance). Inventario manual = un técnico teclea MAC/puerto NAP/estado físico en un formulario — pura persistencia CRUD, sin tocar la red. Se implementará `api_equipos.php` (hoy inexistente, causa raíz de que la tabla esté muerta).

**Puerto de NAP:** se fijó por defecto `UNIQUE KEY (id_naps, num_puerto_nap)` — comportamiento correcto si las NAPs físicas tienen numeración fija de 8/16 puertos. **Pendiente de confirmación explícita del usuario** si su operación real numera puertos estrictamente o si debe relajarse a campo libre sin constraint.

### 4.3 `direcciones` — ELIMINAR la tabla, denormalizar en `servicios`
**Decisión: DROP `direcciones`.** Razonamiento de modelado: la dirección de instalación pertenece al **punto de servicio** (`servicios`), no al cliente como persona — un mismo cliente podría en teoría tener más de un servicio en direcciones distintas. Acoplarla a `clientes` sería error de modelado aunque hoy la UI solo maneje un servicio por cliente.

Columnas que sobreviven, migradas a `servicios`:
- `direccion_texto` (ya se usaba, mapea desde `direcciones.calle_avenida`)
- `latitud_instalacion`, `longitud_instalacion` — **NUEVAS, nullable, incluidas por defecto según fijación del usuario** (costo cero si no se usan, permiten mapa con ubicación real vs. la aproximación actual por promedio de coordenadas en `calculateMapCenter()`)

Columnas descartadas por estar muertas en la práctica: `sector`, `n_casa_apto`, `punto_referencia` (de la vieja tabla `direcciones`).

**IMPORTANTE — esta eliminación NO se ejecutó todavía.** A diferencia del Sistema B (código muerto real), `direcciones` está **viva** en producción: la usan `cliente_actualizar.php`, `clientes_listar.php`, `registrar_cliente.php` activamente. Su baja requiere migrar primero las columnas a `servicios` y reescribir esos 3 archivos — es trabajo de Fase 0 (DDL) + Fase 2, no de la purga simple de Fase 1.

### 4.4 `contratos` — CREAR, acoplada a `servicios`
**Decisión: crear tabla nueva.** Misma lógica de dominio que `direcciones`: un contrato ampara un servicio específico, no a la persona en abstracto.

```sql
CREATE TABLE contratos (
  id_contrato    INT AUTO_INCREMENT PRIMARY KEY,
  id_servicio    INT NOT NULL,
  fecha_inicio   DATE NOT NULL,
  fecha_fin      DATE NULL,                         -- NULL = indefinido
  tipo_contrato  ENUM('indefinido','plazo_fijo','promocional') DEFAULT 'indefinido',
  estado         ENUM('vigente','vencido','rescindido') DEFAULT 'vigente',
  observaciones  TEXT NULL,
  creado_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contrato_servicio FOREIGN KEY (id_servicio) REFERENCES servicios(id_servicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.5 Facturas vs. Pagos — separación profesional, NO el atajo simple
**Decisión explícita del usuario: "haz lo que mejor veas que se deba hacer no lo más sencillo, lo más acorde profesionalmente".** Se separa la obligación de cobro (`facturas`) del evento de cobro (`pagos`), como hacen Splynx/Sonar/WispHub. Mezclarlos (como estaba originalmente) rompe trazabilidad de mora, pagos parciales y estados de cuenta.

```sql
-- FACTURAS: la obligación
CREATE TABLE facturas (
  id_factura        INT AUTO_INCREMENT PRIMARY KEY,
  id_servicio       INT NOT NULL,
  periodo           VARCHAR(7) NOT NULL,           -- '2026-07'
  monto             DECIMAL(10,2) NOT NULL,
  fecha_emision     DATE NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  estado            ENUM('pendiente','pagada','parcial','vencida','anulada') DEFAULT 'pendiente',
  creado_en         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_factura_servicio FOREIGN KEY (id_servicio) REFERENCES servicios(id_servicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PAGOS: el evento (reestructura de la tabla actual del dump)
CREATE TABLE pagos (
  id_pago             INT AUTO_INCREMENT PRIMARY KEY,
  id_factura          INT NULL,                    -- nullable: pago a cuenta sin factura previa
  id_cliente          INT NOT NULL,
  id_servicio         INT NOT NULL,
  monto               DECIMAL(10,2) NOT NULL,
  fecha_pago          DATETIME NOT NULL,
  metodo_pago         ENUM('transferencia','pago_movil','zelle','efectivo') NULL,
  referencia_bancaria VARCHAR(255) NULL,
  estado              ENUM('pendiente','validado','rechazado') DEFAULT 'pendiente',
  fecha_validacion    DATETIME NULL,
  id_usuario_valido   INT NULL,                     -- staff que validó (FK usuarios)
  origen              ENUM('portal_cliente','backoffice') DEFAULT 'backoffice',
  CONSTRAINT fk_pago_factura   FOREIGN KEY (id_factura) REFERENCES facturas(id_factura),
  CONSTRAINT fk_pago_cliente   FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente),
  CONSTRAINT fk_pago_servicio  FOREIGN KEY (id_servicio) REFERENCES servicios(id_servicio),
  CONSTRAINT fk_pago_usuario   FOREIGN KEY (id_usuario_valido) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Regla de negocio a implementar en `api_pagos.php`: al validar un pago vinculado a factura, si `SUM(pagos.monto WHERE id_factura=X AND estado='validado') >= facturas.monto` → factura pasa a `pagada`; si es menor pero mayor a cero → `parcial`. Lógica en PHP, no trigger de BD (consistente con el patrón existente del proyecto).

Vencimiento automático (sin cron, por limitación de hosting tipo XAMPP/cPanel para tesis): en el `GET` de facturas, ejecutar antes del `SELECT`:
```sql
UPDATE facturas SET estado='vencida' WHERE estado='pendiente' AND fecha_vencimiento < CURDATE()
```
Mismo patrón que ya usa el proyecto (`ensureColumn()` al inicio de cada endpoint).

### 4.6 Portal de cliente — Opción 2: CUENTA PROPIA CON LOGIN (confirmado por el usuario)
Se descartó la Opción 1 (consulta ligera sin cuenta, solo cédula+verificación). El usuario **confirmó explícitamente Opción 2**: portal con sistema de login propio para el cliente final, análogo a las "áreas de cliente" de Splynx/UISP.

**Modelo de identidad de 3 capas resultante:**
1. Staff interno (`usuarios` → `rol` → `modulos`/`paginas`) — Sistema A existente, se mantiene como columna vertebral
2. Cliente final (nueva entidad `clientes_credenciales`) — acceso exclusivo a su propia información, aislado del backoffice
3. Sistema B — eliminado (Fase 1)

Razonamiento de por qué NO reutilizar `usuarios`/`rol` para clientes: un cliente no es "un rol con permisos reducidos", es una entidad de negocio distinta. Mezclarlos introduce riesgo de seguridad real (un bug de permisos podría filtrar acceso administrativo a un cliente). Separar por tabla es la barrera de aislamiento más simple y auditable.

```sql
CREATE TABLE clientes_credenciales (
  id_credencial       INT AUTO_INCREMENT PRIMARY KEY,
  id_cliente          INT NOT NULL,
  username            VARCHAR(50) NOT NULL UNIQUE,   -- por defecto = cédula, PENDIENTE confirmar con usuario
  password            VARCHAR(255) NOT NULL,          -- hash con password_hash(), NO texto plano
  correo_recuperacion VARCHAR(80) NULL,
  estado              ENUM('activa','suspendida') DEFAULT 'activa',
  creado_en           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso       DATETIME NULL,
  UNIQUE KEY uq_cliente (id_cliente),
  CONSTRAINT fk_credencial_cliente FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Sesión de cliente: se EXTIENDE la tabla `sesiones` existente, NO se duplica** (evita repetir el mismo antipatrón que se está purgando en Fase 1):
```sql
ALTER TABLE sesiones ADD COLUMN tipo_usuario ENUM('staff','cliente') NOT NULL DEFAULT 'staff';
ALTER TABLE sesiones ADD COLUMN id_referencia INT NOT NULL; -- id_usuario si staff, id_credencial si cliente
-- (o formalizado directamente así en el dump nuevo de Fase 0, ya que `sesiones` no existe todavía en el dump)
```

**Endpoints nuevos de Fase 5:**
- `login_cliente.php` — valida contra `clientes_credenciales`, emite token `tipo_usuario='cliente'`
- `cliente_estado_cuenta.php` — GET, requiere token de cliente, devuelve `facturas`+`pagos` **solo del `id_cliente` resuelto server-side desde el token** (nunca acepta `id_cliente` desde query string, previene que un cliente consulte el estado de otro)
- `cliente_pago_registrar.php` — POST, inserta en `pagos` con `estado='pendiente'`, `origen='portal_cliente'`. Nunca valida.

**Nota de seguridad crítica identificada:** la tabla `usuarios` (staff) actual guarda `password` en **texto plano** (`login.php` compara `$userRow['password'] !== $password` directo). Se aprovechará la Fase 1 (o Fase 5) para migrar también `usuarios` a `password_hash()`/`password_verify()`, no solo la tabla nueva de clientes.

**Preguntas aún SIN responder por el usuario (no asumir, preguntar de nuevo si se retoma este punto):**
1. ¿`username` de `clientes_credenciales` = cédula exacta (sin prefijo V-/E-) o un campo separado/autogenerado?
2. ¿Cuántos usuarios staff existen hoy en producción real (solo `admin`, o varios)? Determina la complejidad del script de migración de contraseñas a hash.

### 4.7 RBAC extendido — módulo Finanzas
Nuevo módulo `Finanzas` en tabla `modulos`. Nuevas páginas en `paginas`: Facturación (`facturas`), Pagos (`pagos`), Planes (`planes`), Contratos (`contratos`), Notificaciones (`notificaciones`). Asignadas por defecto solo a `id_rol=1` (Administrador) en `rol_modulo_pagina`.

El permiso `finanzas.validar` (para transición de estado en `pagos`) **NO se modela como fila nueva en `rol_modulo_pagina`** — ese sistema controla visibilidad de vistas, no acciones granulares. Se resuelve en `api_pagos.php` verificando `roleId === 1` explícitamente, replicando el patrón ya existente `isAdminRole()` de `app.js` para el módulo Configuración. Consistencia con el patrón actual, sin inventar un cuarto sistema de permisos.

---

## PARTE 5 — TABLA RESUMEN DEL ESQUEMA OBJETIVO FINAL

| # | Tabla | Acción | Estado de ejecución |
|---|---|---|---|
| 1 | `roles`, `permissions`, `role_permission`, `user_role`, `permisos`, `rm_permiso`, `user` | **DROP** | ⏳ Instrucciones entregadas en Fase 1 (ver Parte 6), pendiente confirmación de ejecución por el usuario |
| 2 | `direcciones` | **DROP**, migrar a `servicios` | ❌ No iniciado — requiere DDL de Fase 0 + reescritura de 3 archivos |
| 3 | `sesiones` | **Formalizar en dump + extender** con `tipo_usuario`/`id_referencia` | ❌ No iniciado |
| 4 | `olts.codigo`, `servicios.id_naps`+FK, `tickets` (VARCHAR estado, FKs nullable) | **Formalizar en dump** (ya viven en runtime vía ALTER dinámico) | ❌ No iniciado |
| 5 | `equipos` | **Mantener + activar**, crear `api_equipos.php` | ❌ No iniciado (Fase 3) |
| 6 | `planes` | **Activar**, CRUD real + selector en UI | ❌ No iniciado (Fase 2) |
| 7 | `notificaciones` | **Activar**, bandeja interna | ❌ No iniciado (Fase 4) |
| 8 | `logs` | **Activar**, interceptor en puntos críticos | ❌ No iniciado (Fase 4) |
| 9 | `facturas` | **Crear** | ❌ No iniciado (Fase 2) |
| 10 | `pagos` | **Reestructurar** (añadir `id_factura`, `id_usuario_valido`, `origen`) | ❌ No iniciado (Fase 2) |
| 11 | `contratos` | **Crear** | ❌ No iniciado (Fase 2) |
| 12 | `clientes_credenciales` | **Crear** | ❌ No iniciado (Fase 5) |

---

## PARTE 6 — ESTADO ACTUAL DE EJECUCIÓN (última acción realizada)

**Fase 1 (purga de RBAC muerto) — instrucciones entregadas al usuario, ejecución NO confirmada todavía.**

Archivos que el usuario debe haber eliminado físicamente de su proyecto:
```
redytelca/api/roles.php
redytelca/api/permissions.php
redytelca/api/role_permissions.php
redytelca/api/clientes_listar.php
redytelca/includes/auth.php
redytelca/roles_admin.php
redytelca/migrations/001_create_rbac_tables.sql
```

Script SQL entregado para ejecución manual en phpMyAdmin (no automatizada vía `run_migrations.php`, por ser un `DROP` único que no debe repetirse en cada arranque):

```sql
-- migrations/002_drop_dead_rbac.sql
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `rm_permiso`;
DROP TABLE IF EXISTS `role_permission`;
DROP TABLE IF EXISTS `user_role`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `permisos`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `user`;

SET FOREIGN_KEY_CHECKS = 1;
```

Orden de DROP respeta dependencias de FK (hijas primero: `rm_permiso` referencia `permisos`/`rol_modulo_pagina`; `role_permission` referencia `roles`/`permissions`; `user_role` referencia `roles`/`user`).

**Explícitamente NO tocado en Fase 1** (por diseño, no por olvido): `direcciones` — está viva en producción (usada por `cliente_actualizar.php`, `clientes_listar.php`, `registrar_cliente.php`), su baja requiere Fase 0 completa (DDL + reescritura de esos 3 archivos), no es purga simple.

---

## PARTE 7 — PLAN DE FASES COMPLETO (orden de dependencia técnica)

**Fase 0 — Congelar esquema real.** Nuevo `bd_redytelca.sql` único que sea la única fuente de verdad: incluye todo lo formalizado (sesiones, olts.codigo, servicios.id_naps, tickets corregido), las tablas nuevas de Parte 4 (facturas, pagos reestructurado, contratos, clientes_credenciales), elimina `direcciones` con sus columnas migradas a `servicios` (incluyendo `direccion_texto`, `latitud_instalacion`, `longitud_instalacion`), y ya NO incluye las 7 tablas del Sistema B. Reduce la auto-reparación (`ensureColumn`/`ensureInfraSchema`) a salvaguarda mínima, no mecanismo primario.

**Fase 1 — Purga de código muerto + corrección de seguridad.** (Instrucciones ya entregadas, ver Parte 6). Incluye también migrar `usuarios.password` a `password_hash()` con script de migración one-time.

**Fase 2 — Módulo Finanzas en backoffice.** `api_facturas.php`, `api_pagos.php` (reestructurado con lógica de estado parcial/pagada), `api_planes.php`, `api_contratos.php`. Vistas nuevas en `index.html`/`app.js` siguiendo el patrón visual ya existente (modal + tabla + `renderXPage()` de paginación, igual que NAPs/Tickets). También aquí se ejecuta la migración de `direcciones` → `servicios` (reescritura de `cliente_actualizar.php`, `clientes_listar.php`, `registrar_cliente.php`).

**Fase 3 — Inventario de equipos.** `api_equipos.php` + vista en `index.html` bajo el módulo Infraestructura existente (junto a Nodos/OLTs/NAPs).

**Fase 4 — Notificaciones y auditoría.** `api_notificaciones.php` (bandeja interna staff, disparada por: factura próxima a vencer, ticket sin respuesta > N días) + interceptor de `logs` en: login (ambos tipos de usuario), eliminar cliente, cambio de permisos de rol, validación de pago.

**Fase 5 — Portal de cliente real (Opción 2, cuenta propia).** `clientes_credenciales`, `login_cliente.php`, `cliente_estado_cuenta.php`, `cliente_pago_registrar.php`, reescritura completa de `portal_cliente.php` (login → fetch estado de cuenta → formulario de pago conectado). Requiere resolver antes las 2 preguntas pendientes de la Parte 4.6.

**Fase 6 — Corrección de dashboard.** Reemplazar fórmulas simuladas (`ticketsAbiertos = 18 + (totalClientes % 5) - 2`, `servicePercent` artificial en `updateDashboardData()`) por `COUNT()`/`SUM()` reales contra `facturas`/`pagos`/`tickets`, ahora que existen datos reales que agregar.

---

## PARTE 8 — REGLAS DE CONTINUIDAD PARA LA NUEVA SESIÓN

1. **No reabrir decisiones ya cerradas** (Parte 4) sin que el usuario lo pida explícitamente. Son acuerdos ya negociados y confirmados.
2. **Antes de escribir cualquier DDL o código de Fase 0 en adelante**, verificar si las 2 preguntas pendientes de la sección 4.6 (username de cliente, cantidad de usuarios staff actuales) ya fueron respondidas en la conversación nueva; si no, preguntarlas antes de asumir.
3. **Mantener el protocolo de entrega**: archivos completos en el chat, nunca fragmentos, con explicación del porqué al final.
4. **Siempre cruzar contra el código real** proporcionado por el usuario (`redytelca-context.txt` o el archivo que aporte en la sesión nueva) antes de generar cualquier archivo — no fiarse solo de este resumen para detalles de nomenclatura exacta, siempre verificar contra el dump/contexto de código más reciente que el usuario suba.
5. El usuario espera que se le pida **confirmación explícita antes de generar el DDL de Fase 0**, ya que ese archivo es la base de todo lo que sigue.

---

*Fin del documento de contexto. Siguiente paso pendiente al momento de generar este resumen: confirmar con el usuario que ejecutó la Fase 1 (borrado de archivos + DROP de tablas del Sistema B), y resolver las 2 preguntas abiertas de la sección 4.6 antes de emitir el DDL completo de Fase 0.*
