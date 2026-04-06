-- schema.sql
-- Estructura de Base de Datos para FitPaisa

CREATE DATABASE IF NOT EXISTS fitpaisa;
USE fitpaisa;

-- Tabla de Usuarios
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('CLIENT', 'COACH', 'ADMIN') DEFAULT 'CLIENT',
    premium BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de Perfiles (para Macros)
CREATE TABLE IF NOT EXISTS profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    weight DECIMAL(5,2),
    height DECIMAL(5,2),
    age INT,
    gender ENUM('M', 'F', 'OTHER'),
    activity_level VARCHAR(50),
    goal VARCHAR(50),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Tabla de Ingestas (Nutrición)
CREATE TABLE IF NOT EXISTS meals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    calories INT NOT NULL,
    protein INT NOT NULL,
    carbs INT NOT NULL,
    fats INT NOT NULL,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Inserción de un usuario Admin inicial pasword = 123456
INSERT INTO users (name, email, password_hash, role, premium) 
VALUES ('Administrador', 'admin@fitpaisa.com', '$2y$10$oX1y4.SgqY./A9OOGk4aPuh4G1B1c/aT1bI7Xqy/K6/E3VvM1YfR6', 'ADMIN', TRUE)
ON DUPLICATE KEY UPDATE id=id;
