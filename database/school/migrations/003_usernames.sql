ALTER TABLE users
  MODIFY COLUMN email VARCHAR(190) NULL,
  ADD COLUMN username VARCHAR(80) NULL AFTER full_name,
  ADD UNIQUE KEY uq_users_username (username);
