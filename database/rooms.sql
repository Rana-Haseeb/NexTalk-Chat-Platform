-- ==============================================
-- NexTalk — Rooms Table Setup
-- Web Technology SE3003 | Phase 2 (Deliverable 2)
-- ==============================================

USE `nextalk_db`;

-- rooms table
CREATE TABLE IF NOT EXISTS `rooms` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_by`  INT NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_rooms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert dummy rooms (created_by = 1 → Admin user "alihassan")
INSERT INTO `rooms` (`name`, `description`, `created_by`) VALUES
  ('general',        'General discussion for all members.',           1),
  ('web-tech-se3003','Web Technology SE3003 course discussion.',      1),
  ('off-topic',      'Casual chats, memes, and gaming talk.',         2),
  ('project-ideas',  'Brainstorm and share new project concepts.',    2),
  ('announcements',  'Official announcements from admins only.',      1);
