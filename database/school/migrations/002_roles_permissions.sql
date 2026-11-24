INSERT INTO roles (name, label) VALUES
('system_admin','System Administrator'),('school_owner','School Owner'),('school_manager','School Manager'),
('head_teacher','Head Teacher'),('academic_admin','Academic Administrator'),('accountant','Accountant'),
('class_teacher','Class Teacher'),('subject_teacher','Subject Teacher'),('parent_guardian','Parent / Guardian'),
('student','Student'),('librarian','Librarian'),('transport_officer','Transport Officer')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT INTO permissions (permission_key, label) VALUES
('school.view','View school profile'),('school.manage','Manage school configuration'),
('users.manage','Manage users and roles'),('students.view','View student records'),('students.manage','Manage student records'),
('students.guardian.verify','Verify guardian relationships'),('staff.manage','Manage staff'),('assignments.manage','Manage teacher assignments'),
('attendance.view','View attendance'),('attendance.manage','Manage attendance'),('homework.manage','Manage homework'),
('results.marks.enter','Enter assessment marks'),('results.review','Review academic results'),('results.approve','Approve results'),
('results.publish','Publish results'),('results.correct','Correct published results'),('finance.view','View financial records'),
('finance.manage','Manage fees, invoices and payments'),('finance.refund.create','Request refunds'),('finance.refund.approve','Approve refunds'),
('analytics.school','View school-wide analytics'),('analytics.academic','View academic analytics'),('analytics.finance','View financial analytics'),
('analytics.assigned','View assigned-class analytics'),('portal.child','View linked-child records'),('portal.self','View own records'),
('communications.manage','Manage announcements and messages'),('audit.view','View audit records')
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- Grant role permissions idempotently. Assignment/relationship checks are enforced by policies in application code.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'system_admin' AND p.permission_key IN ('school.view','school.manage','users.manage','audit.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'school_owner' AND p.permission_key IN ('school.view','school.manage','students.view','students.manage','students.guardian.verify','staff.manage','assignments.manage','attendance.view','attendance.manage','homework.manage','results.review','results.approve','results.publish','results.correct','finance.view','finance.manage','finance.refund.approve','analytics.school','analytics.academic','analytics.finance','communications.manage','audit.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'school_manager' AND p.permission_key IN ('school.view','students.view','students.manage','students.guardian.verify','staff.manage','assignments.manage','attendance.view','attendance.manage','homework.manage','results.review','finance.view','analytics.school','analytics.academic','analytics.finance','communications.manage','audit.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'head_teacher' AND p.permission_key IN ('school.view','students.view','attendance.view','attendance.manage','homework.manage','results.review','results.approve','results.publish','results.correct','analytics.academic','communications.manage','audit.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'academic_admin' AND p.permission_key IN ('school.view','school.manage','students.view','students.manage','students.guardian.verify','staff.manage','assignments.manage','attendance.view','attendance.manage','homework.manage','results.marks.enter','results.review','results.approve','results.publish','results.correct','analytics.academic','communications.manage','audit.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'accountant' AND p.permission_key IN ('school.view','students.view','finance.view','finance.manage','finance.refund.create','analytics.finance','communications.manage','audit.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('class_teacher','subject_teacher') AND p.permission_key IN ('school.view','students.view','attendance.view','attendance.manage','homework.manage','results.marks.enter','results.review','analytics.assigned','communications.manage','audit.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'parent_guardian' AND p.permission_key IN ('portal.child','students.view','finance.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'student' AND p.permission_key IN ('portal.self');
