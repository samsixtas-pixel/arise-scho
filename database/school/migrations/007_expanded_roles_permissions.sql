-- Expanded role catalog for the School ERP: Part A school operations roles
-- plus Part B finance/accounting roles. Permissions remain the single source
-- of truth for authorization; role names alone never grant record access.

INSERT INTO roles (name, label) VALUES
('second_master','Second Master / Deputy Headmaster'),
('discipline_master','Discipline Master'),
('sports_master','Sports Master'),
('patron','Patron'),
('matron','Matron'),
('secretary','Secretary'),
('admissions_officer','Admissions Officer / Registrar'),
('school_administrator','School Administrator'),
('school_nurse','School Nurse'),
('storekeeper','Storekeeper'),
('company_owner','Company Owner'),
('finance_director','Finance Director'),
('chief_accountant','Chief Accountant'),
('senior_accountant','Senior Accountant'),
('general_accountant','General Accountant'),
('accounts_receivable_officer','Accounts Receivable Officer'),
('accounts_payable_officer','Accounts Payable Officer'),
('payroll_officer','Payroll Officer'),
('tax_accountant','Tax Accountant'),
('cashier','Cashier'),
('procurement_officer','Procurement Officer'),
('internal_auditor','Internal Auditor'),
('external_auditor','External Auditor'),
('department_manager','Department Manager'),
('readonly_manager','Read-only Management User')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO permissions (permission_key, label) VALUES
('admissions.view','View admission applications'),
('admissions.manage','Manage admissions and registration'),
('discipline.view','View discipline cases'),
('discipline.manage','Manage discipline cases'),
('sports.view','View sports activities'),
('sports.manage','Manage sports activities'),
('clubs.view','View clubs and societies'),
('clubs.manage','Manage clubs and societies'),
('boarding.view','View boarding records'),
('boarding.manage','Manage boarding records'),
('welfare.view','View student welfare records'),
('welfare.manage','Manage student welfare records'),
('health.view','View restricted health records'),
('health.manage','Manage restricted health records'),
('library.view','View library records'),
('library.manage','Manage library records'),
('inventory.view','View stores and inventory'),
('inventory.manage','Manage stores and inventory'),
('transport.view','View transport records'),
('transport.manage','Manage transport records'),
('procurement.view','View procurement records'),
('procurement.manage','Manage procurement records'),
('payroll.view','View payroll records'),
('payroll.manage','Manage payroll records'),
('tax.view','View tax records'),
('tax.manage','Manage tax records'),
('documents.view','View generated documents'),
('documents.manage','Generate and manage documents'),
('timetable.view','View timetables'),
('timetable.manage','Manage timetables'),
('reports.view','View management reports'),
('reports.export','Export management reports'),
('results.import','Import results from spreadsheets'),
('users.view','View user accounts')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Add newly required permissions to existing roles without removing anything.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'system_admin' AND p.permission_key IN ('reports.view','documents.view','documents.manage','users.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'school_owner' AND p.permission_key IN ('reports.view','reports.export','tax.view','payroll.view','procurement.view','timetable.view','documents.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'school_manager' AND p.permission_key IN ('reports.view','reports.export','discipline.view','discipline.manage','timetable.view','timetable.manage','admissions.view','admissions.manage','documents.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'head_teacher' AND p.permission_key IN ('students.manage','staff.manage','assignments.manage','discipline.view','discipline.manage','timetable.view','timetable.manage','reports.view','reports.export','results.import','documents.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'academic_admin' AND p.permission_key IN ('admissions.view','admissions.manage','timetable.view','timetable.manage','reports.view','reports.export','results.import','discipline.view','documents.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'accountant' AND p.permission_key IN ('reports.view','reports.export','tax.view','payroll.view','documents.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('class_teacher','subject_teacher') AND p.permission_key IN ('results.import','reports.view','timetable.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'librarian' AND p.permission_key IN ('library.view','library.manage','reports.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'transport_officer' AND p.permission_key IN ('transport.view','transport.manage','reports.view','students.view');

-- Seed the expanded role/permission matrix from one mapping table.
CREATE TEMPORARY TABLE IF NOT EXISTS tmp_role_permission_seed (
  role_name VARCHAR(64) NOT NULL,
  permission_key VARCHAR(120) NOT NULL,
  PRIMARY KEY (role_name, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
TRUNCATE TABLE tmp_role_permission_seed;

INSERT INTO tmp_role_permission_seed (role_name, permission_key) VALUES
('second_master','school.view'),('second_master','students.view'),('second_master','students.manage'),
('second_master','students.guardian.verify'),('second_master','staff.manage'),('second_master','assignments.manage'),
('second_master','attendance.view'),('second_master','attendance.manage'),('second_master','homework.manage'),
('second_master','results.review'),('second_master','results.import'),('second_master','analytics.school'),
('second_master','analytics.academic'),('second_master','communications.manage'),('second_master','discipline.view'),
('second_master','discipline.manage'),('second_master','timetable.view'),('second_master','timetable.manage'),
('second_master','reports.view'),('second_master','reports.export'),('second_master','audit.view'),('second_master','documents.view'),

('discipline_master','school.view'),('discipline_master','students.view'),('discipline_master','discipline.view'),
('discipline_master','discipline.manage'),('discipline_master','attendance.view'),('discipline_master','communications.manage'),
('discipline_master','reports.view'),('discipline_master','audit.view'),('discipline_master','documents.view'),

('sports_master','school.view'),('sports_master','students.view'),('sports_master','sports.view'),
('sports_master','sports.manage'),('sports_master','attendance.view'),('sports_master','communications.manage'),
('sports_master','reports.view'),('sports_master','documents.view'),

('patron','school.view'),('patron','students.view'),('patron','clubs.view'),('patron','clubs.manage'),
('patron','attendance.view'),('patron','communications.manage'),('patron','reports.view'),('patron','documents.view'),

('matron','school.view'),('matron','students.view'),('matron','boarding.view'),('matron','boarding.manage'),
('matron','welfare.view'),('matron','welfare.manage'),('matron','attendance.view'),('matron','communications.manage'),
('matron','reports.view'),('matron','documents.view'),

('secretary','school.view'),('secretary','communications.manage'),('secretary','documents.view'),
('secretary','documents.manage'),('secretary','reports.view'),('secretary','audit.view'),

('admissions_officer','school.view'),('admissions_officer','students.view'),('admissions_officer','students.manage'),
('admissions_officer','students.guardian.verify'),('admissions_officer','admissions.view'),('admissions_officer','admissions.manage'),
('admissions_officer','documents.view'),('admissions_officer','documents.manage'),('admissions_officer','communications.manage'),
('admissions_officer','reports.view'),

('school_administrator','school.view'),('school_administrator','school.manage'),('school_administrator','users.manage'),
('school_administrator','users.view'),('school_administrator','staff.manage'),('school_administrator','assignments.manage'),
('school_administrator','students.view'),('school_administrator','students.manage'),('school_administrator','students.guardian.verify'),
('school_administrator','attendance.view'),('school_administrator','timetable.view'),('school_administrator','timetable.manage'),
('school_administrator','communications.manage'),('school_administrator','documents.view'),('school_administrator','documents.manage'),
('school_administrator','reports.view'),('school_administrator','audit.view'),

('school_nurse','school.view'),('school_nurse','students.view'),('school_nurse','health.view'),
('school_nurse','health.manage'),('school_nurse','welfare.view'),('school_nurse','reports.view'),('school_nurse','documents.view'),

('storekeeper','school.view'),('storekeeper','inventory.view'),('storekeeper','inventory.manage'),
('storekeeper','procurement.view'),('storekeeper','reports.view'),('storekeeper','documents.view'),

('company_owner','school.view'),('company_owner','finance.view'),('company_owner','finance.manage'),
('company_owner','finance.refund.approve'),('company_owner','analytics.school'),('company_owner','analytics.finance'),
('company_owner','audit.view'),('company_owner','reports.view'),('company_owner','reports.export'),('company_owner','documents.view'),

('finance_director','school.view'),('finance_director','finance.view'),('finance_director','finance.manage'),
('finance_director','finance.refund.create'),('finance_director','finance.refund.approve'),('finance_director','analytics.finance'),
('finance_director','audit.view'),('finance_director','reports.view'),('finance_director','reports.export'),
('finance_director','payroll.view'),('finance_director','tax.view'),('finance_director','procurement.view'),('finance_director','documents.view'),

('chief_accountant','school.view'),('chief_accountant','finance.view'),('chief_accountant','finance.manage'),
('chief_accountant','finance.refund.create'),('chief_accountant','analytics.finance'),('chief_accountant','audit.view'),
('chief_accountant','reports.view'),('chief_accountant','reports.export'),('chief_accountant','payroll.view'),
('chief_accountant','tax.view'),('chief_accountant','documents.view'),

('senior_accountant','school.view'),('senior_accountant','finance.view'),('senior_accountant','finance.manage'),
('senior_accountant','finance.refund.create'),('senior_accountant','analytics.finance'),('senior_accountant','reports.view'),
('senior_accountant','reports.export'),('senior_accountant','documents.view'),

('general_accountant','school.view'),('general_accountant','finance.view'),('general_accountant','finance.manage'),
('general_accountant','analytics.finance'),('general_accountant','reports.view'),('general_accountant','documents.view'),

('accounts_receivable_officer','school.view'),('accounts_receivable_officer','students.view'),('accounts_receivable_officer','finance.view'),
('accounts_receivable_officer','finance.manage'),('accounts_receivable_officer','analytics.finance'),('accounts_receivable_officer','reports.view'),
('accounts_receivable_officer','documents.view'),

('accounts_payable_officer','school.view'),('accounts_payable_officer','finance.view'),('accounts_payable_officer','finance.manage'),
('accounts_payable_officer','procurement.view'),('accounts_payable_officer','procurement.manage'),('accounts_payable_officer','inventory.view'),
('accounts_payable_officer','reports.view'),('accounts_payable_officer','documents.view'),

('payroll_officer','school.view'),('payroll_officer','staff.manage'),('payroll_officer','payroll.view'),
('payroll_officer','payroll.manage'),('payroll_officer','finance.view'),('payroll_officer','reports.view'),('payroll_officer','documents.view'),

('tax_accountant','school.view'),('tax_accountant','finance.view'),('tax_accountant','tax.view'),
('tax_accountant','tax.manage'),('tax_accountant','analytics.finance'),('tax_accountant','reports.view'),
('tax_accountant','reports.export'),('tax_accountant','documents.view'),

('cashier','school.view'),('cashier','finance.view'),('cashier','finance.manage'),
('cashier','reports.view'),('cashier','documents.view'),

('procurement_officer','school.view'),('procurement_officer','procurement.view'),('procurement_officer','procurement.manage'),
('procurement_officer','inventory.view'),('procurement_officer','inventory.manage'),('procurement_officer','finance.view'),
('procurement_officer','reports.view'),('procurement_officer','documents.view'),

('internal_auditor','school.view'),('internal_auditor','finance.view'),('internal_auditor','audit.view'),
('internal_auditor','analytics.school'),('internal_auditor','analytics.finance'),('internal_auditor','reports.view'),
('internal_auditor','reports.export'),('internal_auditor','documents.view'),

('external_auditor','school.view'),('external_auditor','finance.view'),('external_auditor','audit.view'),
('external_auditor','reports.view'),('external_auditor','reports.export'),('external_auditor','documents.view'),

('department_manager','school.view'),('department_manager','staff.manage'),('department_manager','students.view'),
('department_manager','analytics.academic'),('department_manager','communications.manage'),('department_manager','reports.view'),
('department_manager','timetable.view'),('department_manager','documents.view'),

('readonly_manager','school.view'),('readonly_manager','students.view'),('readonly_manager','finance.view'),
('readonly_manager','analytics.school'),('readonly_manager','analytics.academic'),('readonly_manager','analytics.finance'),
('readonly_manager','reports.view'),('readonly_manager','audit.view'),('readonly_manager','documents.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM tmp_role_permission_seed s
JOIN roles r ON r.name = s.role_name
JOIN permissions p ON p.permission_key = s.permission_key;

DROP TEMPORARY TABLE IF EXISTS tmp_role_permission_seed;
