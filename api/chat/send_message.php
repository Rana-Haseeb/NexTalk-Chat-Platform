<?php
session_start();
header("Content-Type: application/json");
require_once "../db.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not authorized"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$user_id = $_SESSION["user_id"];
$conversation_id = $_POST['conversation_id'] ?? null;
$content = trim($_POST['content'] ?? '');

if (!$conversation_id || !$content) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Conversation ID and content are required"]);
    exit;
}

try {
    // Check if user is an approved participant of this conversation
    $stmt = $pdo->prepare("SELECT status FROM participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversation_id, $user_id]);
    $participant = $stmt->fetch();

    if (!$participant || $participant['status'] !== 'approved') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You are not an active participant in this conversation"]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$conversation_id, $user_id, $content]);
    
    $message_id = $pdo->lastInsertId();

    echo json_encode(["success" => true, "message_id" => $message_id]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
