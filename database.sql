-- Create database
CREATE DATABASE IF NOT EXISTS dahiana_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dahiana_db;

-- Roles table
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    access_level ENUM('admin', 'client') NOT NULL DEFAULT 'client',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO roles (name, slug, access_level) VALUES
('Administrador', 'admin', 'admin'),
('Cliente', 'client', 'client')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    access_level = VALUES(access_level);

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    phone VARCHAR(50) DEFAULT NULL,
    role VARCHAR(100) NOT NULL DEFAULT 'client',
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Services table (Pre-defined types of services)
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    type ENUM('iguala', 'puntual') NOT NULL
);

-- Requests table (Client's active/past services)
CREATE TABLE IF NOT EXISTS requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    service_id INT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pendiente',
    period VARCHAR(20) DEFAULT NULL, -- For "Iguala", e.g. "2023-10"
    estimated_delivery_date DATE DEFAULT NULL, -- For "Puntual"
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
);

-- Insert default admin user (password: admin123)
INSERT INTO users (name, email, phone, role, password_hash) VALUES 
('Dahiana Asesora', 'admin@admin.com', '+18090000000', 'admin', '$2y$10$I45br4kYvXav/SALFAnJ1u6I7HP8rbtWWJmN7ut3Ppl/oV2TYbJbm');

-- Insert default client user (password: client123)
INSERT INTO users (name, email, phone, role, password_hash) VALUES 
('Cliente Ejemplo', 'client@client.com', '+18091111111', 'client', '$2y$10$TaJ68bCIy.GI9r77XrJRQOEbgbNTBpFkHSaGxM9OWDKlamB5uRvmC');

-- Insert default services
INSERT INTO services (title, type) VALUES
('Presentación de ITBIS (IT-1)', 'iguala'),
('Envío de formatos 606, 607 y 608', 'iguala'),
('Declaraciones IR-17 e IR-3', 'iguala'),
('Manejo de anticipos de ISR', 'iguala'),
('Seguimiento fiscal básico', 'iguala'),
('Asesoría tributaria recurrente', 'iguala'),

('Constitución de compañías', 'puntual'),
('Renovación de Registro Mercantil', 'puntual'),
('Inscripción en TSS (empresa nueva)', 'puntual'),
('Inclusión y exclusión de empleados en TSS', 'puntual'),
('Preparación de estados financieros', 'puntual'),
('Trámites ante la DGII', 'puntual'),
('Regularización de impuestos atrasados', 'puntual'),
('Cierres fiscales', 'puntual'),
('Organización contable desde cero', 'puntual'),
('Solicitudes y gestiones para bancos', 'puntual');
