-- LEXSHIELD database schema and seed data
CREATE DATABASE IF NOT EXISTS `lexsh_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `lexsh_db`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `messages`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `compliance_checks`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `case_file_documents`;
DROP TABLE IF EXISTS `case_file_folders`;
DROP TABLE IF EXISTS `cases`;
DROP TABLE IF EXISTS `case_files`;
DROP TABLE IF EXISTS `risk_assessments`;
DROP TABLE IF EXISTS `site_settings`;
DROP TABLE IF EXISTS `case_notes`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `lawyers`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','lawyer','client') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` DATETIME DEFAULT NULL,
  `avatar_stored_name` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB;

CREATE TABLE `lawyers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `bar_number` VARCHAR(60) NOT NULL,
  `specialization` VARCHAR(120) NOT NULL,
  `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
  `bio` TEXT,
  `background` TEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lawyers_user` (`user_id`),
  UNIQUE KEY `uq_lawyers_bar` (`bar_number`),
  KEY `idx_lawyers_status_specialization` (`status`, `specialization`),
  CONSTRAINT `fk_lawyers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `contact_number` VARCHAR(40) NOT NULL,
  `address` TEXT,
  `risk_level` ENUM('low','medium','high') NOT NULL DEFAULT 'low',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_user` (`user_id`),
  KEY `idx_clients_risk` (`risk_level`),
  CONSTRAINT `fk_clients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `cases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_number` VARCHAR(50) NOT NULL,
  `title` VARCHAR(180) NOT NULL,
  `description` TEXT NOT NULL,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `status` ENUM('open','ongoing','closed','archived') NOT NULL DEFAULT 'open',
  `priority` ENUM('low','normal','urgent') NOT NULL DEFAULT 'normal',
  `filed_date` DATE NOT NULL,
  `closed_date` DATE DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cases_number` (`case_number`),
  KEY `idx_cases_lawyer_status` (`lawyer_id`, `status`),
  KEY `idx_cases_client_status` (`client_id`, `status`),
  CONSTRAINT `fk_cases_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cases_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `case_files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `case_identifier` VARCHAR(80) NOT NULL,
  `case_file_title` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `date_created` DATE NOT NULL,
  `client_user_id` INT UNSIGNED NOT NULL,
  `assigned_lawyer_user_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('open','ongoing','closed') NOT NULL DEFAULT 'open',
  `folder_name` VARCHAR(180) NOT NULL,
  `attachments_json` LONGTEXT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `updated_by_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_files_identifier` (`case_identifier`),
  UNIQUE KEY `uq_case_files_folder` (`folder_name`),
  KEY `idx_case_files_fullname` (`full_name`),
  KEY `idx_case_files_title` (`case_file_title`),
  KEY `idx_case_files_client` (`client_user_id`),
  KEY `idx_case_files_lawyer` (`assigned_lawyer_user_id`),
  CONSTRAINT `fk_case_files_client_user` FOREIGN KEY (`client_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_files_assigned_lawyer_user` FOREIGN KEY (`assigned_lawyer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_case_files_created_by_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_case_files_updated_by_user` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `case_file_folders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_file_id` INT UNSIGNED NOT NULL,
  `parent_folder_id` INT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(170) NOT NULL,
  `created_by_user_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_file_folder_slug` (`case_file_id`, `slug`),
  KEY `idx_case_file_folders_case` (`case_file_id`),
  KEY `idx_case_file_folders_parent` (`parent_folder_id`),
  CONSTRAINT `fk_case_file_folders_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_folders_parent` FOREIGN KEY (`parent_folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_folders_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `case_file_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_file_id` INT UNSIGNED NOT NULL,
  `folder_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) DEFAULT NULL,
  `file_size` INT UNSIGNED DEFAULT NULL,
  `file_hash` CHAR(64) DEFAULT NULL,
  `upload_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `uploaded_by_user_id` INT UNSIGNED NOT NULL,
  `approved_by_user_id` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `is_confidential` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_file_documents_stored` (`case_file_id`, `stored_name`),
  KEY `idx_case_file_documents_case` (`case_file_id`, `upload_status`),
  KEY `idx_case_file_documents_folder` (`folder_id`),
  KEY `idx_case_file_documents_uploaded_by` (`uploaded_by_user_id`),
  CONSTRAINT `fk_case_file_documents_case` FOREIGN KEY (`case_file_id`) REFERENCES `case_files` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `case_file_folders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_file_documents_uploaded_by` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_case_file_documents_approved_by` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `appointments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `scheduled_at` DATETIME NOT NULL,
  `status` ENUM('pending','confirmed','cancelled','deleted') NOT NULL DEFAULT 'pending',
  `notes` TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_appointments_lawyer_date` (`lawyer_id`, `scheduled_at`),
  KEY `idx_appointments_client_date` (`client_id`, `scheduled_at`),
  CONSTRAINT `fk_appointments_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointments_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `lawyer_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lawyer_id` INT UNSIGNED NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lawyer_reviews_pair` (`lawyer_id`, `client_id`),
  KEY `idx_lawyer_reviews_lawyer` (`lawyer_id`),
  KEY `idx_lawyer_reviews_client` (`client_id`),
  CONSTRAINT `fk_lawyer_reviews_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `lawyers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lawyer_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_id` INT UNSIGNED NOT NULL,
  `receiver_id` INT UNSIGNED NOT NULL,
  `case_id` INT UNSIGNED NOT NULL,
  `message_text` TEXT NOT NULL,
  `attachment_original_name` VARCHAR(255) DEFAULT NULL,
  `attachment_stored_name` VARCHAR(255) DEFAULT NULL,
  `attachment_mime_type` VARCHAR(120) DEFAULT NULL,
  `attachment_size` INT UNSIGNED DEFAULT NULL,
  `is_encrypted` TINYINT(1) NOT NULL DEFAULT 1,
  `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_messages_case_sent` (`case_id`, `sent_at`),
  KEY `idx_messages_receiver_read` (`receiver_id`, `is_read`),
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(120) NOT NULL,
  `target_table` VARCHAR(120) NOT NULL,
  `target_id` VARCHAR(64) DEFAULT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `performed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user_action_date` (`user_id`, `action`, `performed_at`),
  KEY `idx_audit_target` (`target_table`, `target_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `session_token` VARCHAR(128) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `device_info` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_token` (`session_token`),
  KEY `idx_sessions_user_expiry` (`user_id`, `expires_at`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(60) NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `risk_assessments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL,
  `score` INT NOT NULL,
  `level` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `assessed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` TEXT,
  PRIMARY KEY (`id`),
  KEY `idx_risk_client_level` (`client_id`, `level`),
  CONSTRAINT `fk_risk_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `case_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `note_text` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_case_notes_case_date` (`case_id`, `created_at`),
  CONSTRAINT `fk_case_notes_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `filepath` VARCHAR(255) NOT NULL,
  `file_hash` CHAR(64) NOT NULL,
  `encryption_key_ref` VARCHAR(255) DEFAULT NULL,
  `is_confidential` TINYINT(1) NOT NULL DEFAULT 1,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_documents_case` (`case_id`),
  KEY `idx_documents_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_documents_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `compliance_checks` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id` INT UNSIGNED NOT NULL,
  `checked_by` INT UNSIGNED NOT NULL,
  `checklist` JSON NOT NULL,
  `passed` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` TEXT,
  `checked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_compliance_case` (`case_id`),
  KEY `idx_compliance_checked_by` (`checked_by`),
  CONSTRAINT `fk_compliance_case` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_compliance_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `site_settings` (
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB;

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `failed_login_attempts`, `locked_until`, `last_login`) VALUES
(1, 'LEXSHIELD ADMIN', 'admin@lexshield', '$2y$12$OgMnY80/P9pXGDRvxlsA4.193UhJ6Dy5J3i7stPoZrAbi0MoZa/pm', 'admin', 1, 0, NULL, NOW()),
(2, 'Jonathan Reed', 'lawyer1@lexshield.local', '$2y$12$CupKMgrlMQpNB92gcq/cWemVGnDlXIzmwS2vqs.sKOSNkPgec1XmG', 'lawyer', 1, 0, NULL, NOW()),
(3, 'Priya Shah', 'lawyer2@lexshield.local', '$2y$12$CupKMgrlMQpNB92gcq/cWemVGnDlXIzmwS2vqs.sKOSNkPgec1XmG', 'lawyer', 1, 0, NULL, NOW()),
(4, 'Northstar Industries', 'client1@lexshield.local', '$2y$12$4xQtsTtoo7D0PN4nNcd74uhucIZxGRIhJRDbEgRmiIfWwyh5GIWr.', 'client', 1, 0, NULL, NOW()),
(5, 'Blue Ridge Holdings', 'client2@lexshield.local', '$2y$12$4xQtsTtoo7D0PN4nNcd74uhucIZxGRIhJRDbEgRmiIfWwyh5GIWr.', 'client', 1, 0, NULL, NOW()),
(6, 'Cobalt Retail Group', 'client3@lexshield.local', '$2y$12$4xQtsTtoo7D0PN4nNcd74uhucIZxGRIhJRDbEgRmiIfWwyh5GIWr.', 'client', 1, 0, NULL, NOW());

INSERT INTO `lawyers` (`id`, `user_id`, `bar_number`, `specialization`, `status`, `bio`, `background`) VALUES
(1, 2, 'BAR-LEX-2024-1001', 'Corporate Compliance', 'active', 'Specializes in governance, cybersecurity policy, and high-risk commercial disputes.', 'Former in-house compliance lead with experience in corporate governance, cybersecurity audits, and board reporting.'),
(2, 3, 'BAR-LEX-2024-1002', 'Data Privacy and Litigation', 'active', 'Advises on privacy programs, breach response, and regulated-sector defense.', 'Background in privacy counsel, incident response, and litigation strategy for regulated industries.');

INSERT INTO `clients` (`id`, `user_id`, `contact_number`, `address`, `risk_level`) VALUES
(1, 4, '+1-555-0100', '120 Market Street, Suite 600, New York, NY', 'high'),
(2, 5, '+1-555-0200', '88 Commerce Avenue, Boston, MA', 'medium'),
(3, 6, '+1-555-0300', '7 Harbor Road, San Francisco, CA', 'low');

INSERT INTO `cases` (`id`, `case_number`, `title`, `description`, `lawyer_id`, `client_id`, `status`, `priority`, `filed_date`, `closed_date`) VALUES
(1, 'LEX-2026-001', 'Data Breach Response', 'Retainer for breach triage, forensic coordination, and regulator notifications.', 1, 1, 'ongoing', 'urgent', '2026-03-04', NULL),
(2, 'LEX-2026-002', 'Vendor Contract Review', 'Cybersecurity addendum and liability review for a critical SaaS vendor agreement.', 1, 2, 'open', 'normal', '2026-03-12', NULL),
(3, 'LEX-2026-003', 'Employment Dispute', 'Internal whistleblower response and litigation readiness pack.', 2, 3, 'ongoing', 'normal', '2026-03-18', NULL),
(4, 'LEX-2026-004', 'Privacy Policy Overhaul', 'GDPR and CCPA policy refresh with retention schedule alignment.', 2, 1, 'open', 'urgent', '2026-03-25', NULL),
(5, 'LEX-2026-005', 'Corporate Restructuring', 'Board approvals, data room checks, and compliance signoff.', 1, 2, 'closed', 'low', '2026-02-15', '2026-04-01');

INSERT INTO `case_files` (`id`, `full_name`, `case_identifier`, `case_file_title`, `description`, `date_created`, `client_user_id`, `assigned_lawyer_user_id`, `status`, `folder_name`, `attachments_json`, `created_by_user_id`, `updated_by_user_id`) VALUES
(1, 'Northstar Industries', 'LEX-2026-001', 'Data Breach Response', 'Case file for breach triage, forensic coordination, and regulator notifications.', '2026-03-04', 4, 2, 'ongoing', 'CF_20260304_NORTHSTAR_INDUSTRIES_DATA_BREACH_RESPONSE_LEX_2026_001', '[]', 2, 2),
(2, 'Blue Ridge Holdings', 'LEX-2026-002', 'Vendor Contract Review', 'Case file for cybersecurity addendum and liability review.', '2026-03-12', 5, 2, 'open', 'CF_20260312_BLUE_RIDGE_HOLDINGS_VENDOR_CONTRACT_REVIEW_LEX_2026_002', '[]', 2, 2),
(3, 'Cobalt Retail Group', 'LEX-2026-003', 'Employment Dispute', 'Case file for whistleblower response and litigation readiness.', '2026-03-18', 6, 3, 'ongoing', 'CF_20260318_COBALT_RETAIL_GROUP_EMPLOYMENT_DISPUTE_LEX_2026_003', '[]', 3, 3),
(4, 'Northstar Industries', 'LEX-2026-004', 'Privacy Policy Overhaul', 'Case file for GDPR and CCPA policy refresh with retention alignment.', '2026-03-25', 4, 3, 'open', 'CF_20260325_NORTHSTAR_INDUSTRIES_PRIVACY_POLICY_OVERHAUL_LEX_2026_004', '[]', 3, 3),
(5, 'Blue Ridge Holdings', 'LEX-2026-005', 'Corporate Restructuring', 'Case file for board approvals, data room checks, and compliance signoff.', '2026-02-15', 5, 2, 'closed', 'CF_20260215_BLUE_RIDGE_HOLDINGS_CORPORATE_RESTRUCTURING_LEX_2026_005', '[]', 2, 2);

INSERT INTO `case_file_folders` (`case_file_id`, `parent_folder_id`, `name`, `slug`, `created_by_user_id`) VALUES
(1, NULL, 'Documents', 'DOCUMENTS', 2),
(1, NULL, 'Evidence', 'EVIDENCE', 2),
(1, NULL, 'Court Filings', 'COURT_FILINGS', 2),
(1, NULL, 'Photos', 'PHOTOS', 2),
(1, NULL, 'Correspondence', 'CORRESPONDENCE', 2),
(1, NULL, 'Client Uploads', 'CLIENT_UPLOADS', 2),
(2, NULL, 'Documents', 'DOCUMENTS', 2),
(2, NULL, 'Evidence', 'EVIDENCE', 2),
(2, NULL, 'Court Filings', 'COURT_FILINGS', 2),
(2, NULL, 'Photos', 'PHOTOS', 2),
(2, NULL, 'Correspondence', 'CORRESPONDENCE', 2),
(2, NULL, 'Client Uploads', 'CLIENT_UPLOADS', 2),
(3, NULL, 'Documents', 'DOCUMENTS', 3),
(3, NULL, 'Evidence', 'EVIDENCE', 3),
(3, NULL, 'Court Filings', 'COURT_FILINGS', 3),
(3, NULL, 'Photos', 'PHOTOS', 3),
(3, NULL, 'Correspondence', 'CORRESPONDENCE', 3),
(3, NULL, 'Client Uploads', 'CLIENT_UPLOADS', 3),
(4, NULL, 'Documents', 'DOCUMENTS', 3),
(4, NULL, 'Evidence', 'EVIDENCE', 3),
(4, NULL, 'Court Filings', 'COURT_FILINGS', 3),
(4, NULL, 'Photos', 'PHOTOS', 3),
(4, NULL, 'Correspondence', 'CORRESPONDENCE', 3),
(4, NULL, 'Client Uploads', 'CLIENT_UPLOADS', 3),
(5, NULL, 'Documents', 'DOCUMENTS', 2),
(5, NULL, 'Evidence', 'EVIDENCE', 2),
(5, NULL, 'Court Filings', 'COURT_FILINGS', 2),
(5, NULL, 'Photos', 'PHOTOS', 2),
(5, NULL, 'Correspondence', 'CORRESPONDENCE', 2),
(5, NULL, 'Client Uploads', 'CLIENT_UPLOADS', 2);

INSERT INTO `appointments` (`case_id`, `client_id`, `lawyer_id`, `scheduled_at`, `status`, `notes`) VALUES
(1, 1, 1, '2026-04-20 10:00:00', 'confirmed', 'Breach response review and incident scope confirmation.'),
(2, 2, 1, '2026-04-22 14:30:00', 'pending', 'Contract redline discussion.'),
(3, 3, 2, '2026-04-23 09:00:00', 'confirmed', 'Witness prep and evidence planning.');

INSERT INTO `documents` (`case_id`, `uploaded_by`, `filename`, `filepath`, `file_hash`, `encryption_key_ref`, `is_confidential`) VALUES
(1, 2, 'Incident-Response-Plan.pdf', 'storage/documents/ir-plan.enc', SHA2('ir-plan', 256), 'kms://lexshield/doc/ir-plan', 1),
(2, 2, 'Vendor-Addendum.docx', 'storage/documents/vendor-addendum.enc', SHA2('vendor-addendum', 256), 'kms://lexshield/doc/vendor-addendum', 1),
(3, 3, 'Witness-List.xlsx', 'storage/documents/witness-list.enc', SHA2('witness-list', 256), 'kms://lexshield/doc/witness-list', 1);

INSERT INTO `messages` (`sender_id`, `receiver_id`, `case_id`, `message_text`, `is_encrypted`, `sent_at`, `is_read`) VALUES
(4, 2, 1, 'Please confirm the incident response window for our board update.', 1, '2026-04-17 08:00:00', 0),
(2, 4, 1, 'We have the first draft ready and will share the containment summary today.', 1, '2026-04-17 09:15:00', 1),
(5, 2, 2, 'Can you review the updated indemnity clause?', 1, '2026-04-16 10:00:00', 0);

INSERT INTO `audit_logs` (`user_id`, `action`, `target_table`, `target_id`, `ip_address`, `user_agent`, `performed_at`) VALUES
(1, 'seed_create', 'users', '1', '127.0.0.1', 'Seeder', NOW()),
(2, 'seed_create', 'cases', '1', '127.0.0.1', 'Seeder', NOW()),
(4, 'view_document', 'documents', '1', '127.0.0.1', 'Seeder', NOW());

INSERT INTO `compliance_checks` (`case_id`, `checked_by`, `checklist`, `passed`, `notes`, `checked_at`) VALUES
(1, 2, JSON_OBJECT('privilege', true, 'gdpr', true, 'retention', true, 'incident-response', true), 1, 'All core items passed.', NOW()),
(2, 3, JSON_OBJECT('privilege', true, 'gdpr', false, 'retention', true, 'incident-response', true), 0, 'GDPR clause needs refinement.', NOW());

INSERT INTO `sessions` (`user_id`, `session_token`, `ip_address`, `device_info`, `expires_at`) VALUES
(1, SHA2(UUID(), 256), '127.0.0.1', 'Seeder', DATE_ADD(NOW(), INTERVAL 8 HOUR));

INSERT INTO `notifications` (`user_id`, `type`, `message`, `is_read`, `created_at`) VALUES
(1, 'security', 'New audit event logged for the dashboard.', 0, NOW()),
(2, 'appointment', 'You have a confirmed client appointment tomorrow.', 0, NOW()),
(4, 'billing', 'Invoice INV-LEX-10001 is now due.', 0, NOW());

INSERT INTO `risk_assessments` (`client_id`, `score`, `level`, `notes`, `assessed_at`) VALUES
(1, 92, 'critical', 'Breached vendor links and regulatory exposure.', NOW()),
(2, 58, 'medium', 'Moderate compliance maturity with limited issues.', NOW()),
(3, 24, 'low', 'Strong baseline controls and low exposure.', NOW());

INSERT INTO `case_notes` (`case_id`, `user_id`, `note_text`, `created_at`) VALUES
(1, 2, 'Initial incident scoping complete. Waiting on IR vendor extracts.', NOW()),
(2, 2, 'Need to push revised indemnity section back to the client.', NOW()),
(3, 3, 'Witness prep checklist drafted and ready for review.', NOW());

INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'LEXSHIELD'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_pass', ''),
('session_timeout', '1800'),
('default_currency', 'USD');
