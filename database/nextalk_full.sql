-- ==============================================
-- NexTalk — Full Database Setup
-- Web Technology SE3003 | Phase 3
-- Single master file: all tables + dummy data
-- ==============================================

DROP DATABASE IF EXISTS `nextalk_db`;
CREATE DATABASE `nextalk_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `nextalk_db`;

-- ==============================================
-- 1. Users Table
-- ==============================================
CREATE TABLE `users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `first_name` VARCHAR(50)  NOT NULL,
  `last_name`  VARCHAR(50)  NOT NULL,
  `username`   VARCHAR(50)  NOT NULL UNIQUE,
  `email`      VARCHAR(100) NOT NULL UNIQUE,
  `password`   VARCHAR(255) NOT NULL,  -- bcrypt hash
  `role`       ENUM('admin', 'moderator', 'member') NOT NULL DEFAULT 'member',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================================
-- 2. Conversations Table
-- ==============================================
CREATE TABLE `conversations` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `type`       ENUM('direct', 'group', 'community') NOT NULL,
  `name`       VARCHAR(100) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_conversations_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================================
-- 3. Participants Table
-- ==============================================
CREATE TABLE `participants` (
  `conversation_id` INT NOT NULL,
  `user_id`         INT NOT NULL,
  `role`            ENUM('admin', 'member') NOT NULL DEFAULT 'member',
  `status`          ENUM('pending', 'approved') NOT NULL DEFAULT 'approved',
  `joined_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`conversation_id`, `user_id`),
  CONSTRAINT `fk_participants_conv`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_participants_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==============================================
-- 4. Messages Table
-- ==============================================
CREATE TABLE `messages` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT NOT NULL,
  `sender_id`       INT NOT NULL,
  `content`         TEXT NOT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_messages_conv`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_messages_sender`
    FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ==============================================================
-- DUMMY DATA
-- ==============================================================

-- Users (password for all = "password123")
INSERT INTO `users` (`first_name`, `last_name`, `username`, `email`, `password`, `role`) VALUES
  ('Ali',    'Hassan', 'alihassan',  'ali@example.com',    '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'admin'),
  ('Sara',   'Ahmed',  'saraahmed',  'sara@example.com',   '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'moderator'),
  ('Usman',  'Khan',   'usmankhan',  'usman@example.com',  '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'member'),
  ('Fatima', 'Malik',  'fatimamalik','fatima@example.com', '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'member'),
  ('Ahmed',  'Raza',   'ahmedraza',  'ahmed@example.com',  '$2y$10$2rk5q3RnWm5Akd3MnGeIUudJL8YbQU9afLyddOAcdDhq3QXweo3tS', 'member');

-- ──────────────────────────────────────────────
-- A) Community: "General"  (created by Ali)
-- ──────────────────────────────────────────────
INSERT INTO `conversations` (`id`, `type`, `name`, `created_by`) VALUES (1, 'community', 'General', 1);
INSERT INTO `participants` (`conversation_id`, `user_id`, `role`, `status`) VALUES
  (1, 1, 'admin',  'approved'),
  (1, 2, 'member', 'approved'),
  (1, 3, 'member', 'approved'),
  (1, 4, 'member', 'approved'),
  (1, 5, 'member', 'approved');

-- ──────────────────────────────────────────────
-- B) Community: "Announcements" (created by Ali, Ahmed pending)
-- ──────────────────────────────────────────────
INSERT INTO `conversations` (`id`, `type`, `name`, `created_by`) VALUES (2, 'community', 'Announcements', 1);
INSERT INTO `participants` (`conversation_id`, `user_id`, `role`, `status`) VALUES
  (2, 1, 'admin',  'approved'),
  (2, 2, 'member', 'approved'),
  (2, 3, 'member', 'pending'),
  (2, 4, 'member', 'approved');

-- ──────────────────────────────────────────────
-- C) Group: "Project Team"  (created by Sara)
-- ──────────────────────────────────────────────
INSERT INTO `conversations` (`id`, `type`, `name`, `created_by`) VALUES (3, 'group', 'Project Team', 2);
INSERT INTO `participants` (`conversation_id`, `user_id`, `role`, `status`) VALUES
  (3, 2, 'admin',  'approved'),
  (3, 3, 'member', 'approved'),
  (3, 4, 'member', 'approved');

-- ──────────────────────────────────────────────
-- D) Group: "Study Circle" (created by Usman)
-- ──────────────────────────────────────────────
INSERT INTO `conversations` (`id`, `type`, `name`, `created_by`) VALUES (4, 'group', 'Study Circle', 3);
INSERT INTO `participants` (`conversation_id`, `user_id`, `role`, `status`) VALUES
  (4, 3, 'admin',  'approved'),
  (4, 1, 'member', 'approved'),
  (4, 5, 'member', 'approved');

-- ──────────────────────────────────────────────
-- E) Direct Message: Ali & Sara
-- ──────────────────────────────────────────────
INSERT INTO `conversations` (`id`, `type`, `name`, `created_by`) VALUES (5, 'direct', NULL, 1);
INSERT INTO `participants` (`conversation_id`, `user_id`, `role`, `status`) VALUES
  (5, 1, 'member', 'approved'),
  (5, 2, 'member', 'approved');

-- ──────────────────────────────────────────────
-- F) Direct Message: Usman & Fatima
-- ──────────────────────────────────────────────
INSERT INTO `conversations` (`id`, `type`, `name`, `created_by`) VALUES (6, 'direct', NULL, 3);
INSERT INTO `participants` (`conversation_id`, `user_id`, `role`, `status`) VALUES
  (6, 3, 'member', 'approved'),
  (6, 4, 'member', 'approved');


-- ──────────────────────────────────────────────
-- Dummy Messages
-- ──────────────────────────────────────────────
INSERT INTO `messages` (`conversation_id`, `sender_id`, `content`) VALUES
  -- General community
  (1, 1, 'Welcome to the General community!'),
  (1, 2, 'Hello everyone! Glad to be here.'),
  (1, 4, 'Hey all, Fatima here 👋'),
  (1, 5, 'Nice to meet you all!'),
  (1, 1, 'Feel free to discuss anything here.'),
  -- Announcements community
  (2, 1, 'Announcements channel is now live.'),
  (2, 1, 'Phase 3 deadline is next Friday.'),
  (2, 2, 'Got it, thanks Ali!'),
  -- Project Team group
  (3, 2, 'Let us start working on Phase 3.'),
  (3, 3, 'Sure, I will handle the frontend.'),
  (3, 4, 'I can work on the API layer.'),
  -- Study Circle group
  (4, 3, 'Welcome to Study Circle!'),
  (4, 1, 'Thanks for adding me.'),
  (4, 5, 'Lets study for the midterm!'),
  -- DM: Ali & Sara
  (5, 1, 'Hey Sara, can you check the server logs?'),
  (5, 2, 'Checking them now.'),
  (5, 1, 'Thanks, let me know what you find.'),
  -- DM: Usman & Fatima
  (6, 3, 'Hey Fatima, are you free to discuss the UI?'),
  (6, 4, 'Sure! Lets do it.');
