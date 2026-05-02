<?php
// ==============================================
// NexTalk — One-Time Database Setup
// ==============================================

require_once "db.php";

// Ensure role-based access is supported for existing DBs.
$tableCheck = $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'users'"
);

if ((int)$tableCheck->fetchColumn() > 0) {
    $colCheck = $pdo->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'role'"
    );

    if ((int)$colCheck->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN role ENUM('admin','moderator','member') NOT NULL DEFAULT 'member' AFTER password"
        );
    }

    $pdo->exec("UPDATE users SET role = 'member' WHERE role IS NULL OR role = ''");

    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($adminCount === 0) {
        $pdo->exec(
            "UPDATE users SET role = 'admin' ORDER BY id ASC LIMIT 1"
        );
        // Sync session if the promoted user is currently logged in
        if (session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['user_id'])
        ) {
            $promoted = $pdo->query(
                "SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1"
            )->fetch();
            if ($promoted && (int)$promoted['id'] === (int)$_SESSION['user_id']) {
                $_SESSION['role'] = 'admin';
            }
        }
    }
}

echo json_encode(["success" => true, "message" => "Setup complete."]);
