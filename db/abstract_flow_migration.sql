-- ============================================================
-- Abstract Management: core flow migration
-- Tables: abstract_submissions, abstract_reviewer_assignments, abstract_logs
-- Adds roles (Reviewer, Organizer) + navigation_menus permissions
-- Safe to re-run (CREATE TABLE IF NOT EXISTS / guards).
-- ============================================================

-- ------------------------------------------------------------
-- 1. Submissions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `abstract_submissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'registered_users.student_id',
  `category_id` int NOT NULL COMMENT 'abstract_category.id',
  `theme_id` int NOT NULL COMMENT 'abstract_theme.id',
  `title` varchar(255) NOT NULL,
  `background` text NOT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `presenting_author` varchar(150) DEFAULT NULL,
  `co_authors` text,
  `file_path` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'submitted' COMMENT 'submitted|reviewer_accepted|reviewer_rejected|sent_back|resubmitted|organizer_accepted|organizer_rejected',
  `reviewer_id` int DEFAULT NULL COMMENT 'admin._id',
  `reviewer_score` int DEFAULT NULL COMMENT '0-10',
  `reviewer_remark` text,
  `reviewed_at` datetime DEFAULT NULL,
  `organizer_id` int DEFAULT NULL COMMENT 'admin._id',
  `organizer_remark` text,
  `organizer_decided_at` datetime DEFAULT NULL,
  `sent_back_reason` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_reviewer` (`reviewer_id`),
  KEY `idx_cat_theme` (`category_id`, `theme_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. Reviewer assignments (reviewer <-> category+theme)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `abstract_reviewer_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reviewer_id` int NOT NULL COMMENT 'admin._id',
  `category_id` int NOT NULL,
  `theme_id` int NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_assign` (`reviewer_id`, `category_id`, `theme_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. Activity log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `abstract_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `submission_id` int NOT NULL,
  `actor_type` varchar(20) NOT NULL COMMENT 'author|reviewer|organizer|system',
  `actor_id` int DEFAULT NULL,
  `actor_name` varchar(150) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `remarks` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission` (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. Roles
-- ------------------------------------------------------------
INSERT INTO `roles` (`role_name`, `status`)
SELECT 'Reviewer', '1' WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_name` = 'Reviewer');

INSERT INTO `roles` (`role_name`, `status`)
SELECT 'Organizer', '1' WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_name` = 'Organizer');

-- ------------------------------------------------------------
-- 5. Permissions (navigation_menus)
-- ------------------------------------------------------------
INSERT INTO `navigation_menus` (`title`, `status`) SELECT 'Abstract Submissions', '1'
WHERE NOT EXISTS (SELECT 1 FROM `navigation_menus` WHERE `title` = 'Abstract Submissions');

INSERT INTO `navigation_menus` (`title`, `status`) SELECT 'Reviewer Assignments', '1'
WHERE NOT EXISTS (SELECT 1 FROM `navigation_menus` WHERE `title` = 'Reviewer Assignments');

INSERT INTO `navigation_menus` (`title`, `status`) SELECT 'My Review Queue', '1'
WHERE NOT EXISTS (SELECT 1 FROM `navigation_menus` WHERE `title` = 'My Review Queue');

-- ------------------------------------------------------------
-- 6. Grant permissions
--    admin   -> everything
--    Reviewer-> My Review Queue
--    Organizer -> Abstract Submissions
-- ------------------------------------------------------------
INSERT INTO `role_permissions` (`role_name`, `permission_id`)
SELECT 'admin', p.id FROM `navigation_menus` p
WHERE p.title IN ('Add Abstract Category', 'All Abstract Categories', 'Add Abstract Theme',
                  'All Abstract Themes', 'Abstract Submissions', 'Reviewer Assignments', 'My Review Queue')
AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.role_name = 'admin' AND rp.permission_id = p.id);

INSERT INTO `role_permissions` (`role_name`, `permission_id`)
SELECT 'reviewer', p.id FROM `navigation_menus` p
WHERE p.title IN ('My Review Queue')
AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.role_name = 'reviewer' AND rp.permission_id = p.id);

INSERT INTO `role_permissions` (`role_name`, `permission_id`)
SELECT 'organizer', p.id FROM `navigation_menus` p
WHERE p.title IN ('Abstract Submissions')
AND NOT EXISTS (SELECT 1 FROM `role_permissions` rp WHERE rp.role_name = 'organizer' AND rp.permission_id = p.id);
