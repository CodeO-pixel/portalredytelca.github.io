# REDYTELCA - Documentación rápida

Resumen de configuración y arquitectura:

1) Estructura y navegación del menú
- Secciones principales: "Descripción", "Menú" y "Principal".
- Sección "Configuraciones" para parámetros globales (empresa, apariencia, reglas del sistema).
- El menú muestra solo opciones permitidas por el rol del usuario.

2) Gestión de la empresa
- Datos básicos: logo, nombre y colores corporativos se deben almacenar y aplicar dinámicamente.

3) Administración de usuarios y roles
- Roles (p.ej. Administrador, Operador) con permisos granulares por módulo y operación.
- Usuarios vinculados a roles; permisos efectivos = unión de permisos de roles.

4) Diseño de la interfaz (Grid y paginación)
- Implementar Grid con columnas configurables y paginación del lado del servidor para grandes volúmenes.

5) Gestión de base de datos
- Tablas principales sugeridas: usuarios, roles, permisos, rol_permiso, usuario_rol, clientes.
- Mantener integridad referencial y esquemas claros (actualizar ER cuando se modifiquen tablas).

6) Seguridad y Sesiones
- Usar tokens (JWT) con expiración; almacenar en LocalStorage/SessionStorage con limpieza al cerrar sesión.
- Validar permisos en el servidor y transmitir siempre por HTTPS.

---

Este README fue generado automáticamente a partir de DOCUMENTACION.md y se agregó una página de "configuraciones" mínima (configuraciones.php) y mejoras en la gestión de sesión del cliente (app.js) para almacenar/limpiar un token local y rol en LocalStorage. Integre el guardado en servidor cuando esté listo.

Fecha: 2026-06-20

Mejoras detalladas y plan de trabajo en IMPROVEMENTS.md
