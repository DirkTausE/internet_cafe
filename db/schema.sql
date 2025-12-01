-- db/schema.sql - Beispiel-DB-Struktur (kürzer gehalten).
-- Ergänze hier die vollständige Struktur nach Bedarf (siehe Conversation).
CREATE DATABASE IF NOT EXISTS internetcafe DEFAULT CHARACTER SET = 'utf8mb4' DEFAULT COLLATE = 'utf8mb4_general_ci';
USE internetcafe;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(150),
  role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS computers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostname VARCHAR(100) NOT NULL UNIQUE,
  mac_address VARCHAR(17),
  ip_address VARCHAR(45),
  description VARCHAR(255),
  current_state ENUM('starting','frei','gast','pause','wartung','STOP','OFF') NOT NULL DEFAULT 'OFF',
  last_checkin TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- Ergänze weitere Tabellen (sessions, invoices, invoice_items, tariffs, products, print_jobs, scan_jobs, blocked_sites usw.)
