-- Correr una sola vez contra la base MySQL (Laragon en local, o la MySQL
-- provisionada por Laravel Forge en producción) antes del primer deploy que use
-- submit-birthday.php. Se puede ejecutar desde HeidiSQL / phpMyAdmin o con:
--   mysql -u USUARIO -p NOMBRE_DB < schema.sql

CREATE TABLE IF NOT EXISTS birthday_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  nombre VARCHAR(255) NOT NULL,
  fecha_nacimiento DATE NOT NULL,
  sucursal VARCHAR(255) NOT NULL,
  acepta_marketing TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45),
  user_agent TEXT,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submission_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempts_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
