-- ============================================================================
--  WEEKLY LOAN MANAGEMENT SYSTEM — Database Schema
--  MariaDB 10.4+ / MySQL 5.7+   ·   utf8mb4
--  Pure OOP PHP · PDO · no framework
--
--  Money columns are DECIMAL(14,2) — TZS has no cents in practice, but the
--  column keeps two places so future products/shares cannot corrupt totals.
--  All datetimes are stored in server local time (Africa/Dar_es_Salaam).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. USERS  (Administrator + Loan Officers)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`          VARCHAR(150) NOT NULL,
  `username`           VARCHAR(60)  NOT NULL,
  `password`           VARCHAR(255) NOT NULL,
  `role`               ENUM('admin','officer') NOT NULL DEFAULT 'officer',
  `status`             ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `phone`              VARCHAR(30)  DEFAULT NULL,
  `officer_code`       VARCHAR(20)  DEFAULT NULL,
  `preferred_language` ENUM('en','sw') NOT NULL DEFAULT 'en',
  `last_login_at`      DATETIME     DEFAULT NULL,
  `first_login_at`     DATETIME     DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_officer_code` (`officer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. APPLICATION SETTINGS  (mirrors the SETTINGS sheet of the workbook)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(80)  NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `notes`         VARCHAR(255) DEFAULT NULL,
  `updated_by`    BIGINT UNSIGNED DEFAULT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. CUSTOMERS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customers` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`           VARCHAR(20)  NOT NULL,
  `first_name`          VARCHAR(80)  NOT NULL DEFAULT '',
  `middle_name`         VARCHAR(80)  NOT NULL DEFAULT '',
  `last_name`           VARCHAR(80)  NOT NULL DEFAULT '',
  `gender`              ENUM('male','female','other') DEFAULT NULL,
  `full_name`           VARCHAR(200) NOT NULL DEFAULT '',
  `nida`                VARCHAR(30)  DEFAULT NULL,
  `phone`               VARCHAR(30)  DEFAULT NULL,
  `phone_alt`           VARCHAR(30)  DEFAULT NULL,
  `photo_path`          VARCHAR(255) DEFAULT NULL,
  `emergency_contact`   VARCHAR(120) DEFAULT NULL,
  `emergency_phone`     VARCHAR(30)  DEFAULT NULL,
  `notes`               TEXT         DEFAULT NULL,
  `data_status`         ENUM('complete','incomplete') NOT NULL DEFAULT 'complete',
  `status`              ENUM('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
  `registered_by`       BIGINT UNSIGNED DEFAULT NULL,
  `assigned_officer_id` BIGINT UNSIGNED DEFAULT NULL,
  `registered_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`          DATETIME     DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_reference` (`reference`),
  UNIQUE KEY `uq_customers_nida` (`nida`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_officer` (`assigned_officer_id`),
  KEY `idx_customers_fullname` (`full_name`),
  KEY `idx_customers_status` (`status`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Original workbook key(s) for customers that were merged during the import.
CREATE TABLE IF NOT EXISTS `customer_legacy_refs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`      BIGINT UNSIGNED NOT NULL,
  `legacy_reference` VARCHAR(20)  NOT NULL,
  `legacy_name`      VARCHAR(200) DEFAULT NULL,
  `source`           VARCHAR(40)  NOT NULL DEFAULT 'workbook',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_legacy_ref` (`legacy_reference`),
  KEY `idx_legacy_customer` (`customer_id`),
  CONSTRAINT `fk_legacy_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. CUSTOMER DOCUMENTS (passport photo + any scanned ID)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_documents` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_uuid`     CHAR(36)      DEFAULT NULL,
  `customer_id`       BIGINT UNSIGNED NOT NULL,
  `doc_type`          VARCHAR(40)   NOT NULL DEFAULT 'photo',
  `document_category` VARCHAR(40)   NOT NULL DEFAULT 'supporting',
  `path`              VARCHAR(255)  NOT NULL,
  `storage_key`       VARCHAR(255)  DEFAULT NULL,
  `thumbnail_key`     VARCHAR(255)  DEFAULT NULL,
  `mime_type`         VARCHAR(100)  DEFAULT NULL,
  `file_size_bytes`   BIGINT UNSIGNED DEFAULT NULL,
  `width`             INT UNSIGNED  DEFAULT NULL,
  `height`            INT UNSIGNED  DEFAULT NULL,
  `checksum`          CHAR(64)      DEFAULT NULL,
  `original_filename` VARCHAR(255)  DEFAULT NULL,
  `document_status`   ENUM('processing','active','rejected','deleted') NOT NULL DEFAULT 'active',
  `version_number`    INT UNSIGNED  NOT NULL DEFAULT 1,
  `is_primary`        TINYINT(1)    NOT NULL DEFAULT 0,
  `issue_date`        DATE          DEFAULT NULL,
  `expiry_date`       DATE          DEFAULT NULL,
  `ocr_status`        ENUM('none','pending','done','failed','unavailable') NOT NULL DEFAULT 'none',
  `uploaded_by`       BIGINT UNSIGNED DEFAULT NULL,
  `uploaded_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_uuid` (`document_uuid`),
  KEY `idx_docs_customer` (`customer_id`),
  KEY `idx_docs_category` (`customer_id`,`document_category`),
  KEY `idx_docs_status` (`document_status`),
  KEY `idx_docs_expiry` (`expiry_date`),
  CONSTRAINT `fk_docs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. CUSTOMER ASSIGNMENTS (which officer owns which customer)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `customer_assignments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `officer_id`  BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED DEFAULT NULL,
  `assigned_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asg_customer` (`customer_id`,`released_at`),
  KEY `idx_asg_officer` (`officer_id`,`released_at`),
  CONSTRAINT `fk_asg_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. LOAN PRODUCTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_products` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                 VARCHAR(120) NOT NULL,
  `default_weeks`        SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `default_weekly_amount` DECIMAL(14,2) NOT NULL DEFAULT 20000.00,
  `interest_type`        ENUM('flat','reducing','none') NOT NULL DEFAULT 'flat',
  `interest_rate`        DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
  `grace_days`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. LOANS
--    Loan date is the day the money left the office. The first instalment is
--    normally due one week later (workbook rule: first = loan date + 7 days).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loans` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`          VARCHAR(20)  NOT NULL,
  `legacy_reference`   VARCHAR(20)  DEFAULT NULL,
  `customer_id`        BIGINT UNSIGNED NOT NULL,
  `product_id`         BIGINT UNSIGNED DEFAULT NULL,
  `principal`          DECIMAL(14,2) NOT NULL,
  `weekly_amount`      DECIMAL(14,2) NOT NULL,
  `number_of_weeks`    SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `total_payable`      DECIMAL(14,2) NOT NULL,
  `loan_date`          DATE NOT NULL,
  `first_payment_date` DATE NOT NULL,
  `final_payment_date` DATE NOT NULL,
  `disbursed_at`       DATETIME DEFAULT NULL,
  `is_written_off`     TINYINT(1) NOT NULL DEFAULT 0,
  `written_off_at`     DATETIME DEFAULT NULL,
  `write_off_reason`   VARCHAR(255) DEFAULT NULL,
  `notes`              TEXT DEFAULT NULL,
  `created_by`         BIGINT UNSIGNED DEFAULT NULL,
  `deleted_at`         DATETIME DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loans_reference` (`reference`),
  KEY `idx_loans_customer` (`customer_id`),
  KEY `idx_loans_firstpay` (`first_payment_date`),
  KEY `idx_loans_active` (`deleted_at`,`is_written_off`),
  CONSTRAINT `fk_loans_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 8. REPAYMENT SCHEDULES (one row per weekly instalment)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `repayment_schedules` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id`            BIGINT UNSIGNED NOT NULL,
  `installment_number` SMALLINT UNSIGNED NOT NULL,
  `due_date`           DATE NOT NULL,
  `amount_due`         DECIMAL(14,2) NOT NULL,
  `amount_paid`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `paid_at`            DATETIME DEFAULT NULL,
  `status`             ENUM('pending','paid','partial','missed') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sched_loan_inst` (`loan_id`,`installment_number`),
  KEY `idx_sched_due` (`due_date`),
  KEY `idx_sched_status` (`status`),
  CONSTRAINT `fk_sched_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9. PAYMENTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`          VARCHAR(20)  NOT NULL,
  `legacy_reference`   VARCHAR(20)  DEFAULT NULL,
  `loan_id`            BIGINT UNSIGNED NOT NULL,
  `customer_id`        BIGINT UNSIGNED NOT NULL,
  `schedule_id`        BIGINT UNSIGNED DEFAULT NULL,
  `amount`             DECIMAL(14,2) NOT NULL,
  `method`             ENUM('cash','mobile_money','bank_transfer','other') NOT NULL DEFAULT 'cash',
  `status`             ENUM('confirmed','reversed','pending') NOT NULL DEFAULT 'confirmed',
  `received_by`        BIGINT UNSIGNED NOT NULL,
  `paid_at`            DATETIME NOT NULL,
  `external_reference` VARCHAR(120) DEFAULT NULL,
  `notes`              TEXT DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_reference` (`reference`),
  UNIQUE KEY `uq_pay_legacy_reference` (`legacy_reference`),
  KEY `idx_pay_loan` (`loan_id`,`status`),
  KEY `idx_pay_customer` (`customer_id`),
  KEY `idx_pay_paid_at` (`paid_at`),
  CONSTRAINT `fk_pay_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`),
  CONSTRAINT `fk_pay_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which instalment(s) a payment was applied to (oldest unpaid first).
CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`  BIGINT UNSIGNED NOT NULL,
  `schedule_id` BIGINT UNSIGNED NOT NULL,
  `amount`      DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alloc_payment` (`payment_id`),
  KEY `idx_alloc_schedule` (`schedule_id`),
  CONSTRAINT `fk_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alloc_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `repayment_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_reversals` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_payment_id` BIGINT UNSIGNED NOT NULL,
  `amount`             DECIMAL(14,2) NOT NULL,
  `reason`             TEXT NOT NULL,
  `reversed_by`        BIGINT UNSIGNED NOT NULL,
  `reversed_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rev_payment` (`original_payment_id`),
  CONSTRAINT `fk_rev_payment` FOREIGN KEY (`original_payment_id`) REFERENCES `payments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 10. DISBURSEMENTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `disbursements` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id`      BIGINT UNSIGNED NOT NULL,
  `amount`       DECIMAL(14,2) NOT NULL,
  `method`       ENUM('cash','mobile_money','bank_transfer','other') NOT NULL DEFAULT 'cash',
  `reference`    VARCHAR(80) DEFAULT NULL,
  `disbursed_by` BIGINT UNSIGNED NOT NULL,
  `disbursed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disb_loan` (`loan_id`),
  CONSTRAINT `fk_disb_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 11. COLLECTION ACTIVITIES (follow-up log for overdue loans)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `collection_activities` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id`        BIGINT UNSIGNED NOT NULL,
  `officer_id`     BIGINT UNSIGNED NOT NULL,
  `activity_type`  ENUM('call','sms','visit','note','promise') NOT NULL,
  `notes`          TEXT DEFAULT NULL,
  `promise_date`   DATE DEFAULT NULL,
  `promise_amount` DECIMAL(14,2) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ca_loan` (`loan_id`),
  KEY `idx_ca_officer` (`officer_id`),
  CONSTRAINT `fk_act_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 12. DAILY CASH RECONCILIATION
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `daily_reconciliations` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `officer_id`          BIGINT UNSIGNED NOT NULL,
  `reconciliation_date` DATE NOT NULL,
  `opening_cash`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `cash_expected`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `cash_declared`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `difference`          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes`               TEXT DEFAULT NULL,
  `status`              ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
  `submitted_at`        DATETIME DEFAULT NULL,
  `reviewed_by`         BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at`         DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recon_officer_date` (`officer_id`,`reconciliation_date`),
  KEY `idx_recon_date` (`reconciliation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 13. AUDIT LOG
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL,
  `username`    VARCHAR(60) DEFAULT NULL,
  `action`      VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(60) DEFAULT NULL,
  `entity_id`   BIGINT UNSIGNED DEFAULT NULL,
  `details`     TEXT DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 14. LOGIN ATTEMPTS (rate limiting)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(60) NOT NULL,
  `ip_address`   VARCHAR(45) NOT NULL,
  `success`      TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_username_time` (`username`,`attempted_at`),
  KEY `idx_la_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 15. USER PREFERENCES
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `preference_key`   VARCHAR(80) NOT NULL,
  `preference_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_userpref_key` (`user_id`,`preference_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 16. NOTIFICATIONS (internal follow-up alerts)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_type` ENUM('user','customer','officer') NOT NULL,
  `recipient_id`   BIGINT UNSIGNED NOT NULL,
  `channel`        ENUM('internal','sms','email','whatsapp') NOT NULL DEFAULT 'internal',
  `subject`        VARCHAR(200) DEFAULT NULL,
  `body`           TEXT NOT NULL,
  `status`         ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
  `scheduled_at`   DATETIME DEFAULT NULL,
  `sent_at`        DATETIME DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_recipient` (`recipient_type`,`recipient_id`),
  KEY `idx_notif_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loan application workflow tables already present in the XAMPP loan_ims database.
CREATE TABLE IF NOT EXISTS `loan_applications` (
  `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`               BIGINT UNSIGNED NOT NULL,
  `product_id`                BIGINT UNSIGNED DEFAULT NULL,
  `requested_principal`       DECIMAL(14,2) NOT NULL,
  `requested_weeks`           SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `requested_weekly_amount`   DECIMAL(14,2) DEFAULT NULL,
  `status`                    ENUM('pending','approved','rejected','more_info') NOT NULL DEFAULT 'pending',
  `officer_id`                BIGINT UNSIGNED DEFAULT NULL,
  `notes`                     TEXT DEFAULT NULL,
  `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_app_customer` (`customer_id`),
  KEY `idx_la_app_status` (`status`),
  KEY `idx_la_app_officer` (`officer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loan_approvals` (
  `id`                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id`           BIGINT UNSIGNED NOT NULL,
  `approved_by`              BIGINT UNSIGNED NOT NULL,
  `decision`                 ENUM('approved','rejected','more_info') NOT NULL,
  `approved_amount`          DECIMAL(14,2) DEFAULT NULL,
  `approved_weeks`           SMALLINT UNSIGNED DEFAULT NULL,
  `approved_weekly_amount`   DECIMAL(14,2) DEFAULT NULL,
  `reason`                   TEXT DEFAULT NULL,
  `decided_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lap_app` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `loan_adjustments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `loan_id`         BIGINT UNSIGNED NOT NULL,
  `adjustment_type` ENUM('interest_waiver','penalty','refund','correction') NOT NULL,
  `amount`          DECIMAL(14,2) NOT NULL,
  `reason`          TEXT NOT NULL,
  `adjusted_by`     BIGINT UNSIGNED NOT NULL,
  `adjusted_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_adj_loan` (`loan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- 4b. CAMERA SESSIONS (PC → phone QR pairing)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `camera_sessions` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_uuid`          CHAR(36)        NOT NULL,
  `token_hash`            CHAR(64)        NOT NULL,
  `officer_id`            BIGINT UNSIGNED NOT NULL,
  `registration_draft_id` VARCHAR(64)     DEFAULT NULL,
  `capture_purpose`       ENUM('portrait','passport','nida','document') NOT NULL DEFAULT 'portrait',
  `status`                ENUM('pending','uploaded','completed','cancelled','expired') NOT NULL DEFAULT 'pending',
  `expires_at`            DATETIME        NOT NULL,
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`          DATETIME        DEFAULT NULL,
  `cancelled_at`          DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_camera_session_uuid` (`session_uuid`),
  UNIQUE KEY `uq_camera_token_hash` (`token_hash`),
  KEY `idx_camera_officer` (`officer_id`,`status`),
  KEY `idx_camera_expiry` (`expires_at`),
  CONSTRAINT `fk_camera_officer` FOREIGN KEY (`officer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `camera_session_assets` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `camera_session_id`     BIGINT UNSIGNED NOT NULL,
  `temporary_storage_key` VARCHAR(255)    NOT NULL,
  `thumbnail_key`         VARCHAR(255)    DEFAULT NULL,
  `processing_status`     ENUM('uploaded','processed','accepted','discarded','failed') NOT NULL DEFAULT 'processed',
  `mime_type`             VARCHAR(100)    NOT NULL DEFAULT 'image/jpeg',
  `file_size_bytes`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width`                 INT UNSIGNED    DEFAULT NULL,
  `height`                INT UNSIGNED    DEFAULT NULL,
  `checksum`              CHAR(64)        DEFAULT NULL,
  `error_message`         VARCHAR(255)    DEFAULT NULL,
  `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at`           DATETIME        DEFAULT NULL,
  `discarded_at`          DATETIME        DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_camera_asset_session` (`camera_session_id`),
  CONSTRAINT `fk_camera_asset_session` FOREIGN KEY (`camera_session_id`)
    REFERENCES `camera_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4c. DOCUMENT VERSION HISTORY
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_versions` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id`        BIGINT UNSIGNED NOT NULL,
  `version_number`     INT UNSIGNED    NOT NULL,
  `storage_key`        VARCHAR(255)    NOT NULL,
  `thumbnail_key`      VARCHAR(255)    DEFAULT NULL,
  `mime_type`          VARCHAR(100)    NOT NULL DEFAULT 'image/jpeg',
  `file_size_bytes`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `checksum`           CHAR(64)        DEFAULT NULL,
  `replaced_by`        BIGINT UNSIGNED DEFAULT NULL,
  `replacement_reason` VARCHAR(255)    DEFAULT NULL,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_version` (`document_id`,`version_number`),
  KEY `idx_version_document` (`document_id`),
  CONSTRAINT `fk_version_document` FOREIGN KEY (`document_id`)
    REFERENCES `customer_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_version_user` FOREIGN KEY (`replaced_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4d. OCR / MRZ RESULTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_ocr_results` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `document_id`       BIGINT UNSIGNED NOT NULL,
  `engine`            VARCHAR(40)     NOT NULL DEFAULT 'mrz-parser',
  `engine_version`    VARCHAR(40)     DEFAULT NULL,
  `raw_text`          MEDIUMTEXT      DEFAULT NULL,
  `parsed_json`       LONGTEXT        DEFAULT NULL,
  `validation_status` ENUM('pending','valid','invalid','unavailable','manual') NOT NULL DEFAULT 'pending',
  `confidence`        DECIMAL(5,4)    DEFAULT NULL,
  `error_message`     VARCHAR(255)    DEFAULT NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ocr_document` (`document_id`),
  CONSTRAINT `fk_ocr_document` FOREIGN KEY (`document_id`)
    REFERENCES `customer_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  VIEWS
-- ============================================================================

-- Base balance view: one row per loan with money totals.
DROP VIEW IF EXISTS `v_loan_balances`;
CREATE VIEW `v_loan_balances` AS
SELECT
    l.`id`                                          AS loan_id,
    l.`reference`                                   AS loan_reference,
    l.`legacy_reference`                            AS legacy_reference,
    l.`customer_id`                                 AS customer_id,
    c.`reference`                                   AS customer_reference,
    c.`full_name`                                   AS customer_name,
    c.`phone`                                       AS phone_number,
    c.`photo_path`                                  AS photo_path,
    l.`loan_date`                                   AS loan_date,
    l.`principal`                                   AS principal_amount,
    l.`weekly_amount`                               AS weekly_amount,
    l.`number_of_weeks`                             AS number_of_weeks,
    l.`total_payable`                               AS total_amount_payable,
    COALESCE(p.`paid`, 0)                           AS amount_paid,
    COALESCE(p.`payment_count`, 0)                  AS payment_count,
    COALESCE(p.`last_payment_at`, NULL)             AS last_payment_at,
    l.`first_payment_date`                          AS first_payment_date,
    l.`final_payment_date`                          AS final_payment_date,
    l.`is_written_off`                              AS is_written_off,
    l.`deleted_at`                                  AS deleted_at
FROM `loans` l
JOIN `customers` c ON c.`id` = l.`customer_id`
LEFT JOIN (
    SELECT `loan_id`,
           SUM(`amount`)   AS paid,
           COUNT(*)        AS payment_count,
           MAX(`paid_at`)  AS last_payment_at
    FROM `payments`
    WHERE `status` = 'confirmed'
    GROUP BY `loan_id`
) p ON p.`loan_id` = l.`id`;

-- Workbook-compatible status view (matches LOANS sheet formulas).
DROP VIEW IF EXISTS `v_loans_with_status`;
CREATE VIEW `v_loans_with_status` AS
SELECT
    b.*,
    GREATEST(b.`total_amount_payable` - b.`amount_paid`, 0)                       AS remaining_balance,
    GREATEST(b.`total_amount_payable` - b.`amount_paid`, 0)                       AS outstanding_balance,
    LEAST(FLOOR(b.`amount_paid` / NULLIF(b.`weekly_amount`, 0)), b.`number_of_weeks`) AS weeks_paid,
    GREATEST(b.`number_of_weeks`
        - LEAST(FLOOR(b.`amount_paid` / NULLIF(b.`weekly_amount`, 0)), b.`number_of_weeks`), 0) AS weeks_remaining,
    CASE
        WHEN b.`amount_paid` >= b.`total_amount_payable` THEN NULL
        ELSE DATE_ADD(b.`first_payment_date`,
             INTERVAL (LEAST(FLOOR(b.`amount_paid` / NULLIF(b.`weekly_amount`, 0)), b.`number_of_weeks`) * 7) DAY)
    END                                                                            AS next_payment_date,
    CASE
        WHEN b.`is_written_off` = 1 THEN 'WRITTEN OFF'
        WHEN b.`amount_paid` >  b.`total_amount_payable` THEN 'OVERPAID'
        WHEN b.`amount_paid` >= b.`total_amount_payable` THEN 'PAID'
        WHEN CURDATE() > DATE_ADD(b.`first_payment_date`,
             INTERVAL (LEAST(FLOOR(b.`amount_paid` / NULLIF(b.`weekly_amount`, 0)), b.`number_of_weeks`) * 7) DAY)
             THEN 'OVERDUE'
        WHEN CURDATE() = DATE_ADD(b.`first_payment_date`,
             INTERVAL (LEAST(FLOOR(b.`amount_paid` / NULLIF(b.`weekly_amount`, 0)), b.`number_of_weeks`) * 7) DAY)
             THEN 'DUE'
        ELSE 'ACTIVE'
    END                                                                            AS loan_status,
    CASE
        WHEN b.`is_written_off` = 0
         AND b.`amount_paid` < b.`total_amount_payable`
         AND CURDATE() > DATE_ADD(b.`first_payment_date`,
             INTERVAL (LEAST(FLOOR(b.`amount_paid` / NULLIF(b.`weekly_amount`, 0)), b.`number_of_weeks`) * 7) DAY)
        THEN DATEDIFF(CURDATE(), DATE_ADD(b.`first_payment_date`,
             INTERVAL (LEAST(FLOOR(b.`amount_paid` / NULLIF(b.`weekly_amount`, 0)), b.`number_of_weeks`) * 7) DAY))
        ELSE 0
    END                                                                            AS days_overdue
FROM `v_loan_balances` b
WHERE b.`deleted_at` IS NULL;

-- Payment ledger with cumulative-to-date balances (fixes the workbook SUMIFS bug).
DROP VIEW IF EXISTS `v_payments_with_balance`;
CREATE VIEW `v_payments_with_balance` AS
SELECT
    p.`id`                AS payment_id,
    p.`reference`         AS payment_reference,
    p.`legacy_reference`  AS legacy_reference,
    p.`loan_id`           AS loan_id,
    b.`loan_reference`    AS loan_reference,
    p.`customer_id`       AS customer_id,
    b.`customer_name`     AS customer_name,
    b.`phone_number`      AS phone_number,
    b.`loan_date`         AS loan_date,
    p.`paid_at`           AS payment_date,
    b.`weekly_amount`     AS weekly_amount,
    p.`amount`            AS amount_paid,
    b.`total_amount_payable` AS total_amount_payable,
    (SELECT COALESCE(SUM(p2.`amount`), 0)
       FROM `payments` p2
      WHERE p2.`loan_id` = p.`loan_id`
        AND p2.`status`  = 'confirmed'
        AND (p2.`paid_at` < p.`paid_at` OR (p2.`paid_at` = p.`paid_at` AND p2.`id` <= p.`id`))
    )                     AS total_paid_to_date,
    b.`total_amount_payable` - (SELECT COALESCE(SUM(p3.`amount`), 0)
       FROM `payments` p3
      WHERE p3.`loan_id` = p.`loan_id`
        AND p3.`status`  = 'confirmed'
        AND (p3.`paid_at` < p.`paid_at` OR (p3.`paid_at` = p.`paid_at` AND p3.`id` <= p.`id`))
    )                     AS remaining_balance,
    b.`next_payment_date` AS next_payment_date,
    b.`loan_status`       AS loan_status,
    p.`method`            AS method,
    p.`status`            AS status,
    p.`notes`             AS notes
FROM `payments` p
JOIN `v_loans_with_status` b ON b.`loan_id` = p.`loan_id`;

-- Overdue work list (matches the OVERDUE LOANS sheet).
DROP VIEW IF EXISTS `v_overdue_loans`;
CREATE VIEW `v_overdue_loans` AS
SELECT *
FROM `v_loans_with_status`
WHERE `loan_status` = 'OVERDUE'
ORDER BY `days_overdue` DESC, `remaining_balance` DESC;
