-- FP-OMS: authenticated account connection and RED signed-PDF upload.
-- Apply AFTER 041 to denr_fp_oms, after backup. No data is dropped.
CREATE TABLE IF NOT EXISTS fpoms_pnpki_authorizations (
  fpoms_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  remote_user_id INT NOT NULL,
  authorized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fpoms_pnpki_authorized_user FOREIGN KEY (fpoms_user_id)
     REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fpoms_pnpki_signed_uploads (
  application_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  original_name VARCHAR(220) NOT NULL,
  stored_path VARCHAR(400) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(application_id,revision),
  CONSTRAINT fk_fpoms_pnpki_upload_app FOREIGN KEY(application_id)
     REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_fpoms_pnpki_upload_user FOREIGN KEY(uploaded_by)
     REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
