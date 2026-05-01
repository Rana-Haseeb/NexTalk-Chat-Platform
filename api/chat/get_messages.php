<?php
// ==============================================
// NexTalk — Get Messages API (WhatsApp-style)
// Returns messages with status ticks, media,
// reply context, and delete-for-me filtering
// ==============================================
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
$last_message_id = (int)($_GET['last_message_id'] ?? 0);

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

    // Fetch messages with sender info, reply context, and delete filtering
    $stmt = $pdo->prepare("
        SELECT m.id, m.content, m.created_at, m.sender_id,
               m.media_url, m.media_type, m.media_name,
               m.reply_to_id, m.forwarded_from, m.status, m.deleted_for_all,
               u.first_name, u.last_name, u.role AS sender_role,
               rm.content AS reply_content, rm.sender_id AS reply_sender_id,
               ru.first_name AS reply_sender_name
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        LEFT JOIN messages rm ON m.reply_to_id = rm.id
        LEFT JOIN users ru ON rm.sender_id = ru.id
        WHERE m.conversation_id = ? AND m.id > ?
          AND m.id NOT IN (SELECT message_id FROM message_deletions WHERE user_id = ?)
        ORDER BY m.id ASC
    ");
    $stmt->execute([$conversation_id, $last_message_id, $user_id]);
    $messages = $stmt->fetchAll();

    // Process messages — handle deleted_for_all
    foreach ($messages as &$msg) {
        if ($msg['deleted_for_all']) {
            $msg['content'] = '🚫 This message was deleted';
            $msg['media_url'] = null;
            $msg['media_type'] = null;
            $msg['media_name'] = null;
            $msg['reply_to_id'] = null;
            $msg['reply_content'] = null;
        }
    }

    // Mark messages as delivered for current user
    $stmt = $pdo->prepare("
        UPDATE message_receipts SET delivered_at = CURRENT_TIMESTAMP
        WHERE user_id = ? AND delivered_at IS NULL
          AND message_id IN (
            SELECT id FROM messages WHERE conversation_id = ? AND sender_id != ?
          )
    ");
    $stmt->execute([$user_id, $conversation_id, $user_id]);

    // Update message status to 'delivered' for messages where all recipients got delivery
    $stmt = $pdo->prepare("
        UPDATE messages m SET m.status = 'delivered'
        WHERE m.conversation_id = ? AND m.status = 'sent'
          AND NOT EXISTS (
            SELECT 1 FROM message_receipts mr
            WHERE mr.message_id = m.id AND mr.delivered_at IS NULL
          )
    ");
    $stmt->execute([$conversation_id]);

    echo json_encode(["success" => true, "messages" => $messages]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
