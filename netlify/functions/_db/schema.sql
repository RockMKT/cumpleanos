-- Correr una sola vez contra el proyecto de Neon (neon.com, plan free) antes del
-- primer deploy que use la función submit-birthday. Se puede ejecutar desde el
-- SQL editor del dashboard de Neon.

CREATE TABLE IF NOT EXISTS birthday_claims (
  id SERIAL PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  nombre TEXT NOT NULL,
  fecha_nacimiento DATE NOT NULL,
  sucursal TEXT NOT NULL,
  acepta_marketing BOOLEAN NOT NULL DEFAULT false,
  ip TEXT,
  user_agent TEXT,
  claimed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS submission_attempts (
  id SERIAL PRIMARY KEY,
  ip TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_attempts_ip_time ON submission_attempts (ip, created_at);
