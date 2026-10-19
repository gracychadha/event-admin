-- ============================================================
-- Invoice / Payment Receipt module: schema migration
-- Database: main_admin
-- Safe to re-run (CREATE TABLE IF NOT EXISTS).
-- ============================================================

-- ------------------------------------------------------------
-- 1. Company profiles (brands such as TMC / Northfert)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `company_profiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `profile_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Short label shown in dropdown e.g. TMC',
  `company_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `postal_code` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_number` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `gstin` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `letterhead_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `signature_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `signatory_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `footer_text` text COLLATE utf8mb4_general_ci,
  `tax_label` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'GST',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '18.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL COMMENT 'admin._id',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 2. Invoices / receipts  (company + customer details snapshotted)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_number` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `document_type` enum('invoice','receipt') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'invoice',
  `company_profile_id` int DEFAULT NULL,
  `document_date` date NOT NULL,

  -- Company snapshot (frozen at issuance)
  `company_snapshot` longtext COLLATE utf8mb4_general_ci COMMENT 'JSON snapshot of company profile',

  -- Customer / delegate
  `customer_name` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `customer_email` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_contact` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `billing_address` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_category` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,

  -- Event / registration
  `event_name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `registration_id` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,

  -- Payment references
  `payment_reference` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_mode` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `utr_reference` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,

  -- Financial values (frozen)
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_label` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_label` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'GST',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_included` tinyint(1) NOT NULL DEFAULT '0',
  `tax_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_in_words` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,

  -- Receipt / payment status (independent of grand total)
  `amount_received` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_date` date DEFAULT NULL,
  `payment_status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|partial|paid',

  -- Lifecycle
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'issued' COMMENT 'issued|void',
  `void_reason` text COLLATE utf8mb4_general_ci,
  `voided_by` int DEFAULT NULL COMMENT 'admin._id',
  `voided_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,

  `created_by` int DEFAULT NULL COMMENT 'admin._id',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_profile_document` (`company_profile_id`, `document_number`),
  KEY `idx_type` (`document_type`),
  KEY `idx_date` (`document_date`),
  KEY `idx_customer` (`customer_name`),
  KEY `idx_registration` (`registration_id`),
  KEY `idx_profile` (`company_profile_id`),
  CONSTRAINT `fk_invoice_profile` FOREIGN KEY (`company_profile_id`) REFERENCES `company_profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 3. Invoice line items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `hsn_sac` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `rate` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `taxable_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_item_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 4. Payments (supports multiple / partial payments)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `payment_date` date DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_mode` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_reference` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `created_by` int DEFAULT NULL COMMENT 'admin._id',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_invoice` (`invoice_id`),
  CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 5. Audit log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `actor_id` int DEFAULT NULL COMMENT 'admin._id',
  `actor_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `action` varchar(40) COLLATE utf8mb4_general_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- 6. Numbering sequences (atomic, per profile + type + year)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_sequences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_profile_id` int NOT NULL DEFAULT '0',
  `document_type` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `year` int NOT NULL,
  `last_number` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_sequence` (`company_profile_id`, `document_type`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
