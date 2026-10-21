-- DENR FP-OMS — one-time initial RICTU Super Administrator seed
-- Data seed only. Requires database/001_core.sql tables to already exist.
-- The password hash below corresponds to a one-time temporary password shared out-of-band.
-- First login is forced to change the password.

START TRANSACTION;

INSERT INTO roles (code,label,description)
VALUES (
  'super_admin',
  'Super Administrator (RICTU)',
  'System configuration and DENR internal-user provisioning'
)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description);

SET @fpoms_super_admin_role_id := (
  SELECT id FROM roles WHERE code='super_admin' LIMIT 1
);

INSERT INTO users
  (name,email,password_hash,user_kind,status,must_change_password,activated_at)
SELECT
  'ANTHONIE FENY V. CATALAN',
  'venzonanthonie@gmail.com',
  '$2y$12$FpLloReZx.nchPBn6eohH.4aZV6s.mzp7DFVNwO4iu6s8qFmjfhVm',
  'internal',
  'active',
  1,
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE email='venzonanthonie@gmail.com'
);

SET @fpoms_super_admin_created := ROW_COUNT();

SET @fpoms_super_admin_user_id := (
  SELECT id FROM users WHERE email='venzonanthonie@gmail.com' LIMIT 1
);

INSERT INTO user_roles (user_id,role_id)
SELECT @fpoms_super_admin_user_id, @fpoms_super_admin_role_id
WHERE @fpoms_super_admin_user_id IS NOT NULL
  AND @fpoms_super_admin_role_id IS NOT NULL
ON DUPLICATE KEY UPDATE assigned_at = assigned_at;

INSERT INTO audit_logs
  (actor_user_id,action,target_type,target_id,metadata_json,ip_address)
SELECT
  @fpoms_super_admin_user_id,
  'admin.seeded_migration',
  'user',
  @fpoms_super_admin_user_id,
  JSON_OBJECT('role','super_admin','source','003_seed_initial_super_admin.sql'),
  NULL
WHERE @fpoms_super_admin_created = 1;

COMMIT;
