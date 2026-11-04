-- ============================================================
-- Registration Pricing System Migration
-- Dynamic period-wise category pricing, GST, add-on options,
-- special discounts, registrations snapshot & Razorpay payments.
-- Safe to re-run (DROP only for the unused legacy slab table,
-- CREATE TABLE IF NOT EXISTS for everything else).
-- ============================================================

-- Legacy empty table (no code references it) replaced by the
-- period-based architecture below.
DROP TABLE IF EXISTS `category_pricing_slabs`;

-- 1. Registration periods (Early Bird / Regular / Late / On Spot ...)
CREATE TABLE IF NOT EXISTS `registration_periods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period_name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `display_order` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_period_dates` (`start_date`,`end_date`),
  KEY `idx_period_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2. Category price per period (one row per category + period)
CREATE TABLE IF NOT EXISTS `registration_category_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` bigint unsigned NOT NULL,
  `period_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `availability` tinyint(1) NOT NULL DEFAULT 1,
  `gst_type` enum('included','excluded','not_applicable') NOT NULL DEFAULT 'not_applicable',
  `gst_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_period` (`category_id`,`period_id`),
  KEY `idx_price_period` (`period_id`),
  CONSTRAINT `fk_rcp_category` FOREIGN KEY (`category_id`) REFERENCES `registration_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcp_period` FOREIGN KEY (`period_id`) REFERENCES `registration_periods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3. Workshop / add-on options under a category
CREATE TABLE IF NOT EXISTS `registration_category_options` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` bigint unsigned NOT NULL,
  `option_name` varchar(150) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `availability` tinyint(1) NOT NULL DEFAULT 1,
  `gst_type` enum('included','excluded','not_applicable') NOT NULL DEFAULT 'not_applicable',
  `gst_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_option_category` (`category_id`),
  CONSTRAINT `fk_rco_category` FOREIGN KEY (`category_id`) REFERENCES `registration_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4. Special occasion discounts
CREATE TABLE IF NOT EXISTS `registration_discounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `discount_name` varchar(150) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `discount_type` enum('percentage','fixed') NOT NULL,
  `discount_value` decimal(12,2) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_discount_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5. Discount <-> category mapping (one, multiple or all categories)
CREATE TABLE IF NOT EXISTS `registration_discount_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `discount_id` int NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_discount_category` (`discount_id`,`category_id`),
  CONSTRAINT `fk_rdc_discount` FOREIGN KEY (`discount_id`) REFERENCES `registration_discounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rdc_category` FOREIGN KEY (`category_id`) REFERENCES `registration_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 6. Registrations (immutable price snapshot per delegate)
CREATE TABLE IF NOT EXISTS `registrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `registration_ref` varchar(30) NOT NULL,
  `user_id` int NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  `category_name` varchar(150) NOT NULL,
  `period_id` int DEFAULT NULL,
  `period_name` varchar(100) NOT NULL,
  `original_fee` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `option_id` int DEFAULT NULL,
  `option_name` varchar(150) DEFAULT NULL,
  `option_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_id` int DEFAULT NULL,
  `discount_name` varchar(150) DEFAULT NULL,
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(12,2) NOT NULL,
  `gst_type` varchar(20) NOT NULL DEFAULT 'not_applicable',
  `gst_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `gst_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payable_amount` decimal(12,2) NOT NULL,
  `payment_status` enum('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_registration_ref` (`registration_ref`),
  KEY `idx_reg_user` (`user_id`),
  KEY `idx_reg_category` (`category_id`),
  KEY `idx_reg_status` (`payment_status`),
  CONSTRAINT `fk_reg_user` FOREIGN KEY (`user_id`) REFERENCES `registered_users` (`student_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reg_category` FOREIGN KEY (`category_id`) REFERENCES `registration_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_reg_period` FOREIGN KEY (`period_id`) REFERENCES `registration_periods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reg_option` FOREIGN KEY (`option_id`) REFERENCES `registration_category_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reg_discount` FOREIGN KEY (`discount_id`) REFERENCES `registration_discounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 7. Razorpay payment attempts / confirmations
CREATE TABLE IF NOT EXISTS `registration_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `registration_id` int NOT NULL,
  `razorpay_order_id` varchar(64) DEFAULT NULL,
  `razorpay_payment_id` varchar(64) DEFAULT NULL,
  `razorpay_signature` varchar(191) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `status` enum('created','captured','failed') NOT NULL DEFAULT 'created',
  `method` varchar(50) DEFAULT NULL,
  `raw_response` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_registration` (`registration_id`),
  KEY `idx_pay_order` (`razorpay_order_id`),
  CONSTRAINT `fk_rpay_registration` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 8. Payment gateway settings (same pattern as recaptcha_settings)
CREATE TABLE IF NOT EXISTS `payment_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider` varchar(30) NOT NULL DEFAULT 'razorpay',
  `key_id` varchar(100) NOT NULL,
  `key_secret` varchar(255) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 9. Admin navigation permissions (titles used by navbar.php permission checks)
INSERT IGNORE INTO `navigation_menus` (`id`, `title`, `status`) VALUES
  (140, 'Registration Periods', '1'),
  (141, 'Registration Pricing', '1'),
  (142, 'Registration Options', '1'),
  (143, 'Registration Discounts', '1'),
  (144, 'View Registrations', '1');
