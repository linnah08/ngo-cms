-- Add signature tracking to documents table
ALTER TABLE documents
  ADD COLUMN signed_at DATETIME NULL AFTER generated_at,
  ADD COLUMN signed_by INT NULL AFTER signed_at,
  ADD CONSTRAINT fk_doc_signed_by FOREIGN KEY (signed_by) REFERENCES admin_users(id) ON DELETE SET NULL;

-- Store saved signature images per admin user
CREATE TABLE admin_signatures (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  admin_user_id  INT NOT NULL,
  signature_data LONGTEXT NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_sig (admin_user_id),
  FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
);
