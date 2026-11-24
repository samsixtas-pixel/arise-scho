-- ============================================================
-- 2026_11_16_identity_capture.sql
-- Smart identity capture & customer document management
-- ------------------------------------------------------------
-- Adds:
--   * customers.gender
--   * customer_documents metadata (keeps legacy path column)
--   * camera_sessions / camera_session_assets
--   * document_versions / document_ocr_results
--
-- Safe to run once against loan_ims. Existing rows keep working:
-- legacy documents only have doc_type + path and are treated as
-- active supporting documents.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Customer gender
-- ------------------------------------------------------------
ALTER TABLE customers
    ADD COLUMN gender ENUM('male','female','other') NULL AFTER last_name;

-- ------------------------------------------------------------
-- 2. Customer documents — extended metadata
-- ------------------------------------------------------------
ALTER TABLE customer_documents
    ADD COLUMN document_uuid    CHAR(36)      NULL AFTER id,
    ADD COLUMN document_category VARCHAR(40)  NOT NULL DEFAULT 'supporting' AFTER doc_type,
    ADD COLUMN storage_key      VARCHAR(255)  NULL AFTER path,
    ADD COLUMN thumbnail_key    VARCHAR(255)  NULL AFTER storage_key,
    ADD COLUMN mime_type        VARCHAR(100)  NULL AFTER thumbnail_key,
    ADD COLUMN file_size_bytes  BIGINT UNSIGNED NULL AFTER mime_type,
    ADD COLUMN width            INT UNSIGNED  NULL AFTER file_size_bytes,
    ADD COLUMN height           INT UNSIGNED  NULL AFTER width,
    ADD COLUMN checksum         CHAR(64)      NULL AFTER height,
    ADD COLUMN original_filename VARCHAR(255) NULL AFTER checksum,
    ADD COLUMN document_status  ENUM('processing','active','rejected','deleted') NOT NULL DEFAULT 'active' AFTER original_filename,
    ADD COLUMN version_number   INT UNSIGNED  NOT NULL DEFAULT 1 AFTER document_status,
    ADD COLUMN is_primary       TINYINT(1)    NOT NULL DEFAULT 0 AFTER version_number,
    ADD COLUMN issue_date       DATE          NULL AFTER is_primary,
    ADD COLUMN expiry_date      DATE          NULL AFTER issue_date,
    ADD COLUMN ocr_status       ENUM('none','pending','done','failed','unavailable') NOT NULL DEFAULT 'none' AFTER expiry_date,
    ADD COLUMN updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER uploaded_at,
    ADD COLUMN deleted_at       DATETIME      NULL AFTER updated_at,
    ADD UNIQUE KEY uq_document_uuid (document_uuid),
    ADD KEY idx_docs_category (customer_id, document_category),
    ADD KEY idx_docs_status (document_status),
    ADD KEY idx_docs_expiry (expiry_date);

-- ------------------------------------------------------------
-- 3. Camera sessions (PC → phone QR pairing)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS camera_sessions (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_uuid         CHAR(36)        NOT NULL,
    token_hash           CHAR(64)        NOT NULL,
    officer_id           BIGINT UNSIGNED NOT NULL,
    registration_draft_id VARCHAR(64)    NULL,
    capture_purpose      ENUM('portrait','passport','nida','document') NOT NULL DEFAULT 'portrait',
    status               ENUM('pending','uploaded','completed','cancelled','expired') NOT NULL DEFAULT 'pending',
    expires_at           DATETIME        NOT NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at         DATETIME        NULL,
    cancelled_at         DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_camera_session_uuid (session_uuid),
    UNIQUE KEY uq_camera_token_hash (token_hash),
    KEY idx_camera_officer (officer_id, status),
    KEY idx_camera_expiry (expires_at),
    CONSTRAINT fk_camera_officer FOREIGN KEY (officer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camera_session_assets (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    camera_session_id     BIGINT UNSIGNED NOT NULL,
    temporary_storage_key VARCHAR(255)    NOT NULL,
    thumbnail_key         VARCHAR(255)    NULL,
    processing_status     ENUM('uploaded','processed','accepted','discarded','failed') NOT NULL DEFAULT 'processed',
    mime_type             VARCHAR(100)    NOT NULL DEFAULT 'image/jpeg',
    file_size_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    width                 INT UNSIGNED    NULL,
    height                INT UNSIGNED    NULL,
    checksum              CHAR(64)        NULL,
    error_message         VARCHAR(255)    NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at           DATETIME        NULL,
    discarded_at          DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_camera_asset_session (camera_session_id),
    CONSTRAINT fk_camera_asset_session FOREIGN KEY (camera_session_id)
        REFERENCES camera_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Document version history
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_versions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id      BIGINT UNSIGNED NOT NULL,
    version_number   INT UNSIGNED    NOT NULL,
    storage_key      VARCHAR(255)    NOT NULL,
    thumbnail_key    VARCHAR(255)    NULL,
    mime_type        VARCHAR(100)    NOT NULL DEFAULT 'image/jpeg',
    file_size_bytes  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum         CHAR(64)        NULL,
    replaced_by      BIGINT UNSIGNED NULL,
    replacement_reason VARCHAR(255)  NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_document_version (document_id, version_number),
    KEY idx_version_document (document_id),
    CONSTRAINT fk_version_document FOREIGN KEY (document_id)
        REFERENCES customer_documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_version_user FOREIGN KEY (replaced_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. OCR / MRZ results
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_ocr_results (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id       BIGINT UNSIGNED NOT NULL,
    engine            VARCHAR(40)     NOT NULL DEFAULT 'mrz-parser',
    engine_version    VARCHAR(40)     NULL,
    raw_text          MEDIUMTEXT      NULL,
    parsed_json       LONGTEXT        NULL,
    validation_status ENUM('pending','valid','invalid','unavailable','manual') NOT NULL DEFAULT 'pending',
    confidence        DECIMAL(5,4)    NULL,
    error_message     VARCHAR(255)    NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ocr_document (document_id),
    CONSTRAINT fk_ocr_document FOREIGN KEY (document_id)
        REFERENCES customer_documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
