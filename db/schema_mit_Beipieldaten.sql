-- MySQL dump 10.13  Distrib 8.0.44, for Linux (x86_64)
--
-- Host: localhost    Database: internetcafe
-- ------------------------------------------------------
-- Server version	8.0.44-0ubuntu0.24.04.2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `internetcafe`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `internetcafe` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;

USE `internetcafe`;

--
-- Table structure for table `blocked_sites`
--

DROP TABLE IF EXISTS `blocked_sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blocked_sites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pattern` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blocked_sites`
--

LOCK TABLES `blocked_sites` WRITE;
/*!40000 ALTER TABLE `blocked_sites` DISABLE KEYS */;
INSERT INTO `blocked_sites` VALUES (1,'xhamster.com','Pornografie',1,'2025-12-17 08:20:46'),(2,'erotikum.de','',1,'2025-12-17 10:15:54');
/*!40000 ALTER TABLE `blocked_sites` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `computers`
--

DROP TABLE IF EXISTS `computers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `computers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `mac_address` varchar(17) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `current_state` enum('starting','frei','gast','pause','wartung','STOP','OFF') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'OFF',
  `last_checkin` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `state` varchar(32) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'frei',
  `name` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostname` (`hostname`),
  UNIQUE KEY `uniq_name` (`name`),
  KEY `idx_computers_state` (`current_state`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `computers`
--

LOCK TABLES `computers` WRITE;
/*!40000 ALTER TABLE `computers` DISABLE KEYS */;
INSERT INTO `computers` VALUES (1,'pc01','00:11:22:33:44:01','192.168.1.101','Oben links','frei',NULL,'2025-12-04 16:35:13','wartung','pc01'),(2,'pc02','00:11:22:33:44:02','192.168.1.102','Arbeitsplatz 2','frei',NULL,'2025-12-04 16:35:13','off','pc02'),(3,'pc03','00:11:22:33:44:03','192.168.1.103','Arbeitsplatz 3','frei',NULL,'2025-12-04 16:35:13','wartung','pc03'),(4,'pc04','00:11:22:33:44:04','192.168.1.104','Arbeitsplatz 4','gast',NULL,'2025-12-04 16:35:13','frei','pc04'),(5,'pc05','00:11:22:33:44:05','192.168.1.105','Arbeitsplatz 5','frei',NULL,'2025-12-04 16:35:13','frei','pc05'),(6,'pc06','00:11:22:33:44:06','192.168.1.106','Arbeitsplatz 6','frei',NULL,'2025-12-04 16:35:13','frei','pc06'),(8,'PC-01','AA:BB:CC:00:01','10.0.0.101','Eingangsbereich','frei','2025-12-04 20:55:17','2025-12-04 20:55:17','off','PC-01'),(9,'PC-02','AA:BB:CC:00:02','10.0.0.102','Nebenzimmer','OFF','2025-12-04 20:55:17','2025-12-04 20:55:17','frei','PC-02'),(10,'PC-03','AA:BB:CC:00:03','10.0.0.103','Fensterplatz','gast','2025-12-04 20:55:17','2025-12-04 20:55:17','frei','PC-03'),(11,'PC-04','AA:BB:CC:00:04','10.0.0.104','Hinterraum','frei','2025-12-04 20:55:17','2025-12-04 20:55:17','frei','PC-04');
/*!40000 ALTER TABLE `computers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_type` enum('guest','registered') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'guest',
  `name` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_diako` tinyint(1) NOT NULL DEFAULT '0',
  `note` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `email` varchar(200) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'registered','Alice Meier',0,'Testkunde, regelmäßiger Nutzer','2025-12-04 20:55:17',NULL,0.00),(2,'registered','Bernd Schulz',0,'Testkunde 2','2025-12-04 20:55:17',NULL,0.00),(3,'guest','Alice Meier',0,NULL,'2025-12-06 22:08:15','alice@example.local',12.50),(4,'guest','Bernd Schulz',0,NULL,'2025-12-06 22:08:16','bernd@example.local',5.00),(5,'guest','Anton Tester',0,'','2025-12-20 15:49:09','',0.01);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_items`
--

DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_invoice_items_invoices` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_items_products` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_items`
--

LOCK TABLES `invoice_items` WRITE;
/*!40000 ALTER TABLE `invoice_items` DISABLE KEYS */;
INSERT INTO `invoice_items` VALUES (3,3,1,'Kaffee groß',1,2.00,2.00),(4,4,1,'Kaffee groß',1,2.00,2.00),(5,5,3,'Kaffee klein',2,1.50,3.00),(8,6,3,'Kaffee klein',2,1.50,3.00),(9,7,3,'Kaffee klein',1,1.50,1.50),(10,8,3,'Kaffee klein',1,1.50,1.50),(11,9,1,'Kaffee groß',2,2.00,4.00),(12,10,1,'Kaffee groß',1,2.00,2.00);
/*!40000 ALTER TABLE `invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_transactions`
--

DROP TABLE IF EXISTS `invoice_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `transaction_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_invoice_transactions_invoices` (`invoice_id`),
  KEY `fk_invoice_transactions_transactions` (`transaction_id`),
  CONSTRAINT `fk_invoice_transactions_invoices` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_transactions_transactions` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_transactions`
--

LOCK TABLES `invoice_transactions` WRITE;
/*!40000 ALTER TABLE `invoice_transactions` DISABLE KEYS */;
INSERT INTO `invoice_transactions` VALUES (1,3,1,'2025-12-20 11:06:26'),(2,4,2,'2025-12-20 11:17:38'),(3,5,3,'2025-12-20 11:19:24'),(4,11,4,'2025-12-20 13:48:51'),(5,11,5,'2025-12-20 13:48:51'),(6,11,6,'2025-12-20 13:48:51'),(7,11,7,'2025-12-20 13:48:51');
/*!40000 ALTER TABLE `invoice_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_invoices_customers` (`customer_id`),
  CONSTRAINT `fk_invoices_customers` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES (3,1,1.50,'2025-12-20 11:06:26'),(4,1,5.00,'2025-12-20 11:17:38'),(5,4,2.00,'2025-12-20 11:19:24'),(6,3,3.00,'2025-12-20 13:08:10'),(7,3,1.50,'2025-12-20 13:08:26'),(8,3,1.50,'2025-12-20 13:08:47'),(9,1,4.00,'2025-12-20 13:31:40'),(10,4,2.00,'2025-12-20 13:47:35'),(11,3,13.00,'2025-12-20 13:48:51');
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_types`
--

DROP TABLE IF EXISTS `price_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_types`
--

LOCK TABLES `price_types` WRITE;
/*!40000 ALTER TABLE `price_types` DISABLE KEYS */;
INSERT INTO `price_types` VALUES (1,'normal','Normalpreis','2025-12-04 16:35:13'),(2,'diako','Diako Sonderpreis','2025-12-04 16:35:13');
/*!40000 ALTER TABLE `price_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `print_jobs`
--

DROP TABLE IF EXISTS `print_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `print_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `computer_id` int NOT NULL,
  `user_description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `pages` int DEFAULT '0',
  `color_pages` int DEFAULT '0',
  `bw_pages` int DEFAULT '0',
  `copies` int DEFAULT '1',
  `price` decimal(10,2) DEFAULT '0.00',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `invoice_item_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `computer_id` (`computer_id`),
  KEY `invoice_item_id` (`invoice_item_id`),
  CONSTRAINT `print_jobs_ibfk_1` FOREIGN KEY (`computer_id`) REFERENCES `computers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `print_jobs_ibfk_2` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `print_jobs`
--

LOCK TABLES `print_jobs` WRITE;
/*!40000 ALTER TABLE `print_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `print_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sku` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `name` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `price_normal` decimal(10,2) NOT NULL,
  `price_diako` decimal(10,2) DEFAULT NULL,
  `vat_percent` decimal(5,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'KAF01','Kaffee groß','großer schwarzer Kaffee',2.00,1.00,0.00,'2025-12-04 16:35:13'),(2,'SNK01','Snack','Kleiner Snack',1.50,1.00,0.00,'2025-12-04 16:35:13'),(3,NULL,'Kaffee klein','kleiner schwarzer Kaffee',1.50,1.00,0.00,'2025-12-18 23:45:48'),(4,NULL,'PC-Nutzung','PC-Nutzung Minutenpreis',0.10,0.10,0.00,'2025-12-20 05:53:13');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `scan_jobs`
--

DROP TABLE IF EXISTS `scan_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scan_jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `computer_id` int NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `pages` int DEFAULT '0',
  `price` decimal(10,2) DEFAULT '0.00',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `invoice_item_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `computer_id` (`computer_id`),
  KEY `invoice_item_id` (`invoice_item_id`),
  CONSTRAINT `scan_jobs_ibfk_1` FOREIGN KEY (`computer_id`) REFERENCES `computers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scan_jobs_ibfk_2` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `scan_jobs`
--

LOCK TABLES `scan_jobs` WRITE;
/*!40000 ALTER TABLE `scan_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `scan_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `computer_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `invoice_id` int DEFAULT NULL,
  `start_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `end_time` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tariffs`
--

DROP TABLE IF EXISTS `tariffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tariffs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `price_type_id` int NOT NULL,
  `service_code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `unit` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `currency` varchar(10) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'EUR',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `price_type_id` (`price_type_id`),
  CONSTRAINT `tariffs_ibfk_1` FOREIGN KEY (`price_type_id`) REFERENCES `price_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tariffs`
--

LOCK TABLES `tariffs` WRITE;
/*!40000 ALTER TABLE `tariffs` DISABLE KEYS */;
INSERT INTO `tariffs` VALUES (1,1,'pc_minute','minute',0.20,'EUR','2025-12-04 16:35:13'),(2,2,'pc_minute','minute',0.15,'EUR','2025-12-04 16:35:13'),(3,1,'print_bw','page',0.10,'EUR','2025-12-04 16:35:13'),(4,1,'print_color','page',0.50,'EUR','2025-12-04 16:35:13'),(5,1,'scan_page','page',0.05,'EUR','2025-12-04 16:35:13'),(6,2,'print_bw','page',0.08,'EUR','2025-12-04 16:35:13'),(7,2,'print_color','page',0.30,'EUR','2025-12-04 16:35:13'),(8,2,'scan_page','page',0.03,'EUR','2025-12-04 16:35:13');
/*!40000 ALTER TABLE `tariffs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaction_items`
--

DROP TABLE IF EXISTS `transaction_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `product_id` int NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` int NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `transaction_items_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaction_items`
--

LOCK TABLES `transaction_items` WRITE;
/*!40000 ALTER TABLE `transaction_items` DISABLE KEYS */;
INSERT INTO `transaction_items` VALUES (1,9,1,'Kaffee groß',1,2.00,2.00),(2,10,1,'Kaffee groß',1,2.00,2.00),(3,10,2,'Snack',1,1.50,1.50),(4,11,1,'Kaffee groß',1,2.00,2.00);
/*!40000 ALTER TABLE `transaction_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `vat_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_id` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (1,1,1.50,0.29,'2025-12-20 11:04:30'),(2,1,5.00,0.95,'2025-12-20 11:16:21'),(3,4,2.00,0.38,'2025-12-20 11:19:11'),(4,3,2.00,0.38,'2025-12-20 12:50:51'),(5,3,3.00,0.57,'2025-12-20 12:51:11'),(6,3,4.00,0.76,'2025-12-20 12:51:30'),(7,3,4.00,0.76,'2025-12-20 12:52:03'),(9,5,2.00,0.38,'2025-12-20 17:50:03'),(10,5,3.50,0.67,'2025-12-20 19:29:43'),(11,3,2.00,0.38,'2025-12-20 19:39:12');
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `display_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `role` enum('admin','operator') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'operator',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'internetcafe'
--

--
-- Dumping routines for database 'internetcafe'
--
/*!50003 DROP PROCEDURE IF EXISTS `__internetcafe_ensure_computers_state__` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE DEFINER=`internetcafe`@`localhost` PROCEDURE `__internetcafe_ensure_computers_state__`()
BEGIN
  DECLARE cnt INT DEFAULT 0;

  
  SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'computers'
     AND COLUMN_NAME = 'state';
  IF cnt = 0 THEN
    ALTER TABLE `computers`
      ADD COLUMN `state` VARCHAR(32) NOT NULL DEFAULT 'frei';
  END IF;

  
  SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'computers'
     AND COLUMN_NAME = 'is_on';
  IF cnt > 0 THEN
    
    UPDATE `computers`
     SET `state` = 'frei'
     WHERE (`state` IS NULL OR `state` = '')
       AND (
         `is_on` IN (1,'1')
         OR LOWER(CAST(`is_on` AS CHAR)) IN ('true','on','online','up')
       );

    
    UPDATE `computers`
     SET `state` = 'off'
     WHERE (`state` IS NULL OR `state` = '')
       AND (
         `is_on` IN (0,'0')
         OR LOWER(CAST(`is_on` AS CHAR)) IN ('false','off','down')
       );
  END IF;

  
  SELECT COUNT(*) INTO cnt
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'computers'
     AND COLUMN_NAME = 'status';
  IF cnt > 0 THEN
    UPDATE `computers`
     SET `state` = 'gast'
     WHERE (`state` IS NULL OR `state` = '')
       AND LOWER(CAST(`status` AS CHAR)) LIKE '%guest%';

    UPDATE `computers`
     SET `state` = 'wartung'
     WHERE (`state` IS NULL OR `state` = '')
       AND LOWER(CAST(`status` AS CHAR)) REGEXP 'maint|wartung';

    UPDATE `computers`
     SET `state` = 'pause'
     WHERE (`state` IS NULL OR `state` = '')
       AND LOWER(CAST(`status` AS CHAR)) LIKE '%pause%';

    UPDATE `computers`
     SET `state` = 'starting'
     WHERE (`state` IS NULL OR `state` = '')
       AND LOWER(CAST(`status` AS CHAR)) REGEXP 'start|boot';

    UPDATE `computers`
     SET `state` = 'stop'
     WHERE (`state` IS NULL OR `state` = '')
       AND LOWER(CAST(`status` AS CHAR)) LIKE '%stop%';

    UPDATE `computers`
     SET `state` = 'off'
     WHERE (`state` IS NULL OR `state` = '')
       AND LOWER(CAST(`status` AS CHAR)) LIKE '%off%';
  END IF;

  
  UPDATE `computers`
   SET `state` = 'frei'
   WHERE `state` IS NULL OR `state` = '';

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-12-20 21:02:13
