-- schema.sql
-- Datenbank: internetcafe
-- Verwendung: MariaDB / MySQL (utf8mb4)

CREATE DATABASE IF NOT EXISTS internetcafe
  DEFAULT CHARACTER SET = 'utf8mb4'
  DEFAULT COLLATE = 'utf8mb4_general_ci';
USE internetcafe;

-- Administratoren / Mitarbeiter (Login für Webfrontend)
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL, -- z.B. password_hash PHP
  display_name VARCHAR(150),
  role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Computer / Clients
CREATE TABLE computers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hostname VARCHAR(100) NOT NULL UNIQUE,
  mac_address VARCHAR(17),
  ip_address VARCHAR(45),
  description VARCHAR(255),
  current_state ENUM('starting','frei','gast','pause','wartung','STOP','OFF') NOT NULL DEFAULT 'OFF',
  last_checkin TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Kunde / Gast (optional feste Kunden: z.B. Diako)
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_type ENUM('guest','registered') NOT NULL DEFAULT 'guest',
  name VARCHAR(200) DEFAULT NULL,
  is_diako TINYINT(1) NOT NULL DEFAULT 0, -- 1=Diako-Sonderpreis
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Preistypen und Tarife
CREATE TABLE price_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE, -- 'normal','diako', etc.
  label VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE tariffs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  price_type_id INT NOT NULL,
  service_code VARCHAR(50) NOT NULL, -- 'pc_minute','print_bw','print_color','scan_page'
  unit VARCHAR(20) NOT NULL, -- 'minute','page','unit'
  price DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (price_type_id) REFERENCES price_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Produkte (z.B. Kaffee)
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(50) DEFAULT NULL,
  name VARCHAR(200) NOT NULL,
  description TEXT,
  price_normal DECIMAL(10,2) NOT NULL,
  price_diako DECIMAL(10,2) DEFAULT NULL,
  vat_percent DECIMAL(5,2) DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- PC-Sessions (für Abrechnung der PC-Nutzung)
CREATE TABLE sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  computer_id INT NOT NULL,
  customer_id INT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  billed_minutes INT DEFAULT 0,
  price_per_min DECIMAL(10,2) DEFAULT 0.00,
  total_price DECIMAL(10,2) DEFAULT 0.00,
  invoice_id INT DEFAULT NULL,
  FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Rechnungen
CREATE TABLE invoices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(100) NOT NULL UNIQUE,
  customer_id INT NULL,
  created_by INT NULL, -- user id
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  total_amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(10) DEFAULT 'EUR',
  pdf_path VARCHAR(255) DEFAULT NULL,
  paid TINYINT(1) DEFAULT 0,
  paid_at DATETIME NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Rechnungspositionen
CREATE TABLE invoice_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  line_order INT NOT NULL DEFAULT 0,
  description VARCHAR(255) NOT NULL,
  qty DECIMAL(10,2) DEFAULT 1.00,
  unit_price DECIMAL(10,2) NOT NULL,
  total_price DECIMAL(12,2) NOT NULL,
  product_id INT NULL,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Zahlungen / Transaktionen
CREATE TABLE transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method VARCHAR(50), -- 'cash','card','voucher'
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Druckjobs (für Abrechnung)
CREATE TABLE print_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  computer_id INT NOT NULL,
  user_description VARCHAR(255),
  pages INT DEFAULT 0,
  color_pages INT DEFAULT 0,
  bw_pages INT DEFAULT 0,
  copies INT DEFAULT 1,
  price DECIMAL(10,2) DEFAULT 0.00,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  invoice_item_id INT NULL,
  FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE,
  FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Scan jobs (für Abrechnung)
CREATE TABLE scan_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  computer_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  pages INT DEFAULT 0,
  price DECIMAL(10,2) DEFAULT 0.00,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  invoice_item_id INT NULL,
  FOREIGN KEY (computer_id) REFERENCES computers(id) ON DELETE CASCADE,
  FOREIGN KEY (invoice_item_id) REFERENCES invoice_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Gesperrte Websites (URL oder Hostnames)
CREATE TABLE blocked_sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pattern VARCHAR(255) NOT NULL, -- z.B. domain or URL fragment
  note VARCHAR(255) DEFAULT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Import Logs für blocklist-Import (Originaldatei, Parsingfehler)
CREATE TABLE blocked_imports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255),
  total_lines INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by INT NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE blocked_import_errors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  import_id INT NOT NULL,
  line_number INT NOT NULL,
  raw_text TEXT,
  error_message VARCHAR(255),
  FOREIGN KEY (import_id) REFERENCES blocked_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Indexe zur Performance
CREATE INDEX idx_computers_state ON computers(current_state);
CREATE INDEX idx_sessions_started ON sessions(started_at);
CREATE INDEX idx_invoices_created ON invoices(created_at);
