-- FP-OMS Desktop PNPKI Signing Agent pilot. All existing signing paths remain intact.
-- Apply once after 041/042/044. Disabled by default; no existing rows are modified.
CREATE TABLE IF NOT EXISTS fpoms_desktop_agent_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO fpoms_desktop_agent_settings (id,enabled) VALUES (1,0);

CREATE TABLE IF NOT EXISTS fpoms_desktop_agent_tokens (
  user_id INT NOT NULL PRIMARY KEY,
  token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fpoms_desktop_agent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fpoms_desktop_agent_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT NOT NULL,
  revision INT NOT NULL,
  document_kind VARCHAR(32) NOT NULL,
  user_id INT NOT NULL,
  draft_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY uq_fpoms_desktop_agent_job (application_id,revision,document_kind),
  KEY ix_fpoms_desktop_agent_queue (user_id,status,created_at),
  CONSTRAINT fk_fpoms_desktop_job_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
