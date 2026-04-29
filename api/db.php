<?php
// ==============================================
// NexTalk — Database Connection
// ==============================================

$host     = "localhost";
$dbname   = "nextalk_db";
$username = "root";
$password = "";          // default XAMPP/MAMP password



//api/db.php
// $host     = "sql210.infinityfree.com"; // <-- Your live MySQL Hostname
// $dbname   = "if0_41762639_nextalk";    // <-- Your live Database Name
// $username = "if0_41762639";            // <-- Your live MySQL Username
// $password = "X5gBKMP8v6lLFd";    // <-- Put your actual InfinityFree account password here


try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

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
                "UPDATE users
                 SET role = 'admin'
                 ORDER BY id ASC
                 LIMIT 1"
            );
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit;
}
