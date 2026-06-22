-- Minimal bootstrap for the E2E CI database.
-- The application assumes a `users` table already exists; ensureLibrarySchema()
-- creates every other table on the first API request. login.php also reads
-- is_active + status, so those columns must exist and be set for the test admin.
CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(255) NOT NULL UNIQUE,
  password       VARCHAR(255) NOT NULL,
  full_name      VARCHAR(255) NULL,
  role           VARCHAR(20)  NOT NULL DEFAULT 'viewer',
  status         VARCHAR(20)  NOT NULL DEFAULT 'approved',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  classification VARCHAR(50)  NOT NULL DEFAULT 'individual',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
