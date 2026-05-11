<?php
// ==============================================
// NexTalk — Logout API (session_destroy)
// ==============================================
session_start();
header("Content-Type: application/json");

// Set offline status before destroying session
if (isset($_SESSION["user_id"])) {
    require_once "db.php";
    $pdo->prepare("UPDATE users SET is_online = 0, last_seen_at = NOW() WHERE id = ?")->execute([$_SESSION["user_id"]]);
}

// Clear all session variables
$_SESSION = [];

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy session
session_destroy();

echo json_encode([
    "success" => true,
    "message" => "Logged out successfully."
]);