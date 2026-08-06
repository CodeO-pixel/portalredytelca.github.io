# Diagrama de casos de uso

## Actores
- Administrador
- Usuario del sistema

## Casos de uso principales
1. Iniciar sesión
2. Cambiar contraseña
3. Registrar cliente
4. Editar cliente
5. Eliminar cliente
6. Consultar dashboard
7. Ver detalles de la OLT y dirección asociada

```mermaid
flowchart TD
    A[Administrador] --> B[Iniciar sesión]
    A --> C[Cambiar contraseña]
    A --> D[Registrar cliente]
    A --> E[Editar cliente]
    A --> F[Eliminar cliente]
    A --> G[Consultar dashboard]
    G --> H[Ver estado de la red]
    D --> I[Asignar dirección y OLT]
    E --> I
```
