<?php
session_start();
header("Content-Type: application/json");
require_once "../db.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not authorized"]);
    exit;
}

$user_id = $_SESSION["user_id"];
$conversation_id = $_GET['conversation_id'] ?? null;
$last_message_id = $_GET['last_message_id'] ?? 0;

if (!$conversation_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Conversation ID is required"]);
    exit;
}

try {
    // Check participation
    $stmt = $pdo->prepare("SELECT status FROM participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversation_id, $user_id]);
    $participant = $stmt->fetch();

    if (!$participant || $participant['status'] !== 'approved') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Not authorized to view these messages"]);
        exit;
    }

    // Now also SELECT u.role (system role from users table)
    $stmt = $pdo->prepare("
        SELECT m.id, m.content, m.created_at, m.sender_id,
               u.first_name, u.last_name, u.role AS sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([$conversation_id, $last_message_id]);
    $messages = $stmt->fetchAll();

    echo json_encode(["success" => true, "messages" => $messages]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
