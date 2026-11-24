CREATE TABLE IF NOT EXISTS school_student_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  category ENUM('admission','identity','previous_report','other') NOT NULL DEFAULT 'other',
  original_name VARCHAR(255) NOT NULL,
  storage_key CHAR(64) NOT NULL,
  mime_type ENUM('application/pdf','image/jpeg','image/png') NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archived_at DATETIME NULL,
  UNIQUE KEY uq_student_document_storage_key (storage_key),
  KEY idx_student_document_scope (student_id, category, archived_at, created_at),
  CONSTRAINT fk_student_document_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_student_document_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
