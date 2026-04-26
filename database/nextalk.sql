-- ==============================================
-- NexTalk — Database Setup
-- Web Technology SE3003 | Phase 1
-- ==============================================

CREATE DATABASE IF NOT EXISTS `nextalk_db`;
USE `nextalk_db`;

-- users table
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(50)  NOT NULL,
  `last_name`  VARCHAR(50)  NOT NULL,
  `username`   VARCHAR(50)  NOT NULL UNIQUE,
  `email`      VARCHAR(100) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,  -- bcrypt hash
  `role`       ENUM('admin', 'moderator', 'member') NOT NULL DEFAULT 'member',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert demo users (password = "password123")
INSERT INTO `users` (`first_name`, `last_name`, `username`, `email`, `password`, `role`)
VALUES
  ('Ali', 'Hassan', 'alihassan', 'ali@example.com', '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'admin'),
  ('Sara', 'Ahmed', 'saraahmed', 'sara@example.com', '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'moderator'),
  ('Usman', 'Khan', 'usmankhan', 'usman@example.com', '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'member');
