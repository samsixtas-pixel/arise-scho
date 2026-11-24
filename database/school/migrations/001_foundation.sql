CREATE TABLE IF NOT EXISTS school_profile (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  motto VARCHAR(255) NULL,
  logo_path VARCHAR(500) NULL,
  address VARCHAR(500) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  website VARCHAR(255) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Dar_es_Salaam',
  currency CHAR(3) NOT NULL DEFAULT 'TZS',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(180) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','suspended','invited') NOT NULL DEFAULT 'active',
  demo_account TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS school_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  identity_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_identity_time (identity_hash, attempted_at),
  KEY idx_login_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  label VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(120) NOT NULL,
  label VARCHAR(180) NOT NULL,
  UNIQUE KEY uq_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_years (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(32) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_academic_year_name (name),
  CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS terms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status ENUM('planned','active','closed') NOT NULL DEFAULT 'planned',
  UNIQUE KEY uq_term_year_name (academic_year_id, name),
  CONSTRAINT fk_terms_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
  CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS class_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  level ENUM('primary','secondary') NOT NULL,
  name VARCHAR(80) NOT NULL,
  capacity SMALLINT UNSIGNED NULL,
  UNIQUE KEY uq_class_year_name (academic_year_id, name),
  CONSTRAINT fk_classes_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS streams (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  class_group_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_stream_class_name (class_group_id, name),
  CONSTRAINT fk_streams_class FOREIGN KEY (class_group_id) REFERENCES class_groups(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS subjects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  level ENUM('primary','secondary','both') NOT NULL DEFAULT 'both',
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_subject_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  admission_number VARCHAR(40) NOT NULL,
  full_name VARCHAR(180) NOT NULL,
  date_of_birth DATE NULL,
  gender ENUM('female','male','other','undisclosed') NOT NULL DEFAULT 'undisclosed',
  admission_date DATE NOT NULL,
  status ENUM('active','withdrawn','graduated','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_students_admission (admission_number),
  UNIQUE KEY uq_students_user (user_id),
  KEY idx_students_status (status),
  CONSTRAINT fk_student_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS guardians (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  relationship_label VARCHAR(60) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_guardian_user (user_id),
  CONSTRAINT fk_guardian_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS student_guardians (
  student_id BIGINT UNSIGNED NOT NULL,
  guardian_id BIGINT UNSIGNED NOT NULL,
  relationship VARCHAR(60) NOT NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  ended_at DATETIME NULL,
  PRIMARY KEY (student_id, guardian_id),
  KEY idx_student_guardian_scope (guardian_id, verification_status, ended_at),
  CONSTRAINT fk_sg_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sg_guardian FOREIGN KEY (guardian_id) REFERENCES guardians(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sg_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_group_id BIGINT UNSIGNED NOT NULL,
  stream_id BIGINT UNSIGNED NULL,
  status ENUM('active','transferred','withdrawn','completed') NOT NULL DEFAULT 'active',
  started_at DATE NOT NULL,
  ended_at DATE NULL,
  UNIQUE KEY uq_enrollment_student_year (student_id, academic_year_id),
  CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrollment_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrollment_class FOREIGN KEY (class_group_id) REFERENCES class_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_enrollment_stream FOREIGN KEY (stream_id) REFERENCES streams(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS staff (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  employee_number VARCHAR(40) NOT NULL,
  department VARCHAR(100) NULL,
  employment_status ENUM('active','on_leave','ended') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_staff_user (user_id), UNIQUE KEY uq_staff_employee (employee_number),
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS teacher_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  staff_id BIGINT UNSIGNED NOT NULL,
  academic_year_id BIGINT UNSIGNED NOT NULL,
  class_group_id BIGINT UNSIGNED NOT NULL,
  stream_id BIGINT UNSIGNED NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  ended_at DATETIME NULL,
  UNIQUE KEY uq_teacher_assignment (staff_id, academic_year_id, class_group_id, subject_id),
  CONSTRAINT fk_ta_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ta_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ta_class FOREIGN KEY (class_group_id) REFERENCES class_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ta_stream FOREIGN KEY (stream_id) REFERENCES streams(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ta_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS examinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  term_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  type VARCHAR(40) NOT NULL,
  workflow_status ENUM('draft','open','under_review','approved','published','closed') NOT NULL DEFAULT 'draft',
  starts_on DATE NULL,
  ends_on DATE NULL,
  UNIQUE KEY uq_exam_term_name (term_id, name),
  CONSTRAINT fk_exam_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS assessments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  examination_id BIGINT UNSIGNED NOT NULL,
  subject_id BIGINT UNSIGNED NOT NULL,
  class_group_id BIGINT UNSIGNED NOT NULL,
  max_mark DECIMAL(7,2) NOT NULL,
  weight DECIMAL(6,3) NOT NULL DEFAULT 1.000,
  UNIQUE KEY uq_assessment_scope (examination_id, subject_id, class_group_id),
  CONSTRAINT fk_assess_exam FOREIGN KEY (examination_id) REFERENCES examinations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_assess_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT fk_assess_class FOREIGN KEY (class_group_id) REFERENCES class_groups(id) ON DELETE RESTRICT,
  CHECK (max_mark > 0), CHECK (weight > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  assessment_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  mark DECIMAL(7,2) NULL,
  status ENUM('draft','submitted','under_review','approved','published') NOT NULL DEFAULT 'draft',
  current_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_result_assessment_student (assessment_id, student_id),
  CONSTRAINT fk_result_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_result_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS result_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  result_id BIGINT UNSIGNED NOT NULL,
  version SMALLINT UNSIGNED NOT NULL,
  mark DECIMAL(7,2) NULL,
  grade VARCHAR(12) NULL,
  comments TEXT NULL,
  changed_by BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_result_version (result_id, version),
  CONSTRAINT fk_result_version_result FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE RESTRICT,
  CONSTRAINT fk_result_version_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS attendance_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  class_group_id BIGINT UNSIGNED NOT NULL,
  stream_id BIGINT UNSIGNED NULL,
  stream_scope BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(stream_id, 0)) STORED,
  session_date DATE NOT NULL,
  period_label VARCHAR(50) NOT NULL DEFAULT 'Daily',
  taken_by BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_attendance_session (class_group_id, stream_scope, session_date, period_label),
  CONSTRAINT fk_att_session_class FOREIGN KEY (class_group_id) REFERENCES class_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_session_stream FOREIGN KEY (stream_id) REFERENCES streams(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_session_user FOREIGN KEY (taken_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS attendance_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  state ENUM('present','absent','late','excused','early_departure') NOT NULL,
  reason VARCHAR(500) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  correction_of BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_attendance_student_session (session_id, student_id),
  CONSTRAINT fk_att_record_session FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_record_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_record_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_att_record_correction FOREIGN KEY (correction_of) REFERENCES attendance_records(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_structures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  term_id BIGINT UNSIGNED NOT NULL,
  class_group_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(140) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  due_date DATE NULL,
  UNIQUE KEY uq_fee_structure (term_id, class_group_id, code),
  CONSTRAINT fk_fee_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fee_class FOREIGN KEY (class_group_id) REFERENCES class_groups(id) ON DELETE RESTRICT,
  CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  term_id BIGINT UNSIGNED NOT NULL,
  invoice_number VARCHAR(50) NOT NULL,
  status ENUM('draft','issued','part_paid','paid','void') NOT NULL DEFAULT 'draft',
  issued_at DATETIME NULL,
  due_at DATE NULL,
  UNIQUE KEY uq_invoice_number (invoice_number),
  CONSTRAINT fk_invoice_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoice_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  fee_structure_id BIGINT UNSIGNED NULL,
  description VARCHAR(180) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invoice_item_fee FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE RESTRICT,
  CHECK (amount >= 0), CHECK (discount >= 0), CHECK (discount <= amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payer_user_id BIGINT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'TZS',
  method VARCHAR(40) NOT NULL,
  reference VARCHAR(120) NULL,
  status ENUM('pending','succeeded','failed','reversed','refunded') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_reference (reference), UNIQUE KEY uq_payment_idempotency (idempotency_key),
  CONSTRAINT fk_payment_payer FOREIGN KEY (payer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payment_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_invoice (payment_id, invoice_id),
  CONSTRAINT fk_allocation_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_allocation_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_assignment_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  instructions TEXT NULL,
  due_at DATETIME NULL,
  status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_assignment_teacher FOREIGN KEY (teacher_assignment_id) REFERENCES teacher_assignments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  assignment_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  submitted_at DATETIME NULL,
  status ENUM('missing','submitted','marked') NOT NULL DEFAULT 'missing',
  feedback TEXT NULL,
  mark DECIMAL(7,2) NULL,
  UNIQUE KEY uq_submission_assignment_student (assignment_id, student_id),
  CONSTRAINT fk_submission_assignment FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_submission_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  status ENUM('requested','approved','rejected','processed') NOT NULL DEFAULT 'requested',
  requested_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_refund_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS financial_adjustments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_adjustment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_adjustment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_adjustment_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payment_callbacks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(60) NOT NULL,
  provider_event_id VARCHAR(160) NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  payment_id BIGINT UNSIGNED NULL,
  payload_hash CHAR(64) NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provider_event (provider, provider_event_id),
  CONSTRAINT fk_callback_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('in_app','email','sms','whatsapp','push') NOT NULL DEFAULT 'in_app',
  notification_type VARCHAR(80) NOT NULL,
  status ENUM('queued','attempted','delivered','failed') NOT NULL DEFAULT 'queued',
  payload_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notification_user_status (user_id, status, created_at),
  CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_cards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  term_id BIGINT UNSIGNED NOT NULL,
  examination_id BIGINT UNSIGNED NOT NULL,
  report_number VARCHAR(60) NOT NULL,
  verification_token_hash CHAR(64) NOT NULL,
  published_at DATETIME NULL,
  UNIQUE KEY uq_report_number (report_number), UNIQUE KEY uq_report_token_hash (verification_token_hash),
  CONSTRAINT fk_report_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_term FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE RESTRICT,
  CONSTRAINT fk_report_exam FOREIGN KEY (examination_id) REFERENCES examinations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS report_card_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_card_id BIGINT UNSIGNED NOT NULL,
  payload_json JSON NOT NULL,
  branding_snapshot_json JSON NOT NULL,
  pdf_path VARCHAR(500) NULL,
  checksum CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_snapshot (report_card_id),
  CONSTRAINT fk_snapshot_report FOREIGN KEY (report_card_id) REFERENCES report_cards(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  subject_type VARCHAR(80) NOT NULL,
  subject_id BIGINT UNSIGNED NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_subject (subject_type, subject_id, created_at),
  KEY idx_audit_actor (actor_user_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
