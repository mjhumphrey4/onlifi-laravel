-- Fix admin password in onlifi_central database
-- New password: admin123

USE onlifi_central;

-- Update admin password hash
UPDATE users 
SET password_hash = '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5NU0k0VqKqZ.G'
WHERE username = 'admin';

-- Verify the update
SELECT id, username, email, role, status, email_verified, created_at 
FROM users 
WHERE username = 'admin';
