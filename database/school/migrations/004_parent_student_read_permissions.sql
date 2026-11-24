INSERT INTO permissions (permission_key, label) VALUES
('results.view_published','View published academic results'),
('homework.view','View assigned homework')
ON DUPLICATE KEY UPDATE label = VALUES(label);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'parent_guardian'
  AND p.permission_key IN ('attendance.view','results.view_published','homework.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'student'
  AND p.permission_key IN ('attendance.view','results.view_published','homework.view');
