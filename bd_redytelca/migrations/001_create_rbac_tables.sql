-- Migration: Create RBAC tables

CREATE TABLE IF NOT EXISTS roles (
  id_rol INT AUTO_INCREMENT PRIMARY KEY,
  nombre_rol VARCHAR(100) NOT NULL UNIQUE,
  descripcion TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
  id_permiso INT AUTO_INCREMENT PRIMARY KEY,
  nombre_permiso VARCHAR(150) NOT NULL UNIQUE,
  descripcion TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permission (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_rol INT NOT NULL,
  id_permiso INT NOT NULL,
  UNIQUE KEY role_perm_unique (id_rol, id_permiso),
  FOREIGN KEY (id_rol) REFERENCES roles(id_rol) ON DELETE CASCADE,
  FOREIGN KEY (id_permiso) REFERENCES permissions(id_permiso) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_role (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  id_rol INT NOT NULL,
  UNIQUE KEY user_role_unique (id_usuario, id_rol),
  FOREIGN KEY (id_rol) REFERENCES roles(id_rol) ON DELETE CASCADE
  -- Note: id_usuario should reference usuarios.id_usuario when available
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed basic roles
INSERT IGNORE INTO roles (id_rol, nombre_rol, descripcion) VALUES
(1, 'Administrador', 'Acceso total al sistema'),
(2, 'Operador', 'Funciones operativas y de campo');

-- Seed example permissions
INSERT IGNORE INTO permissions (nombre_permiso, descripcion) VALUES
('clientes.view', 'Ver lista de clientes'),
('clientes.create', 'Crear cliente'),
('clientes.edit', 'Editar cliente'),
('clientes.delete', 'Eliminar cliente'),
('roles.manage', 'Gestionar roles y permisos');

-- Assign some permissions to Administrador
INSERT IGNORE INTO role_permission (id_rol, id_permiso)
SELECT 1, p.id_permiso FROM permissions p WHERE p.nombre_permiso IN ('clientes.view','clientes.create','clientes.edit','clientes.delete','roles.manage');
