ALTER TABLE users
  ADD COLUMN login_attempts INT NOT NULL DEFAULT 0 AFTER otp_attempts,
  ADD COLUMN login_locked_until DATETIME DEFAULT NULL AFTER login_attempts;
