<?php
// ==============================================
// NexTalk — Get Participants API
// Returns members list for a given conversation
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

if (!$conversation_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Conversation ID is required"]);
    exit;
}

try {
    // Verify the requesting user is an approved participant
    $stmt = $pdo->prepare("SELECT status FROM participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversation_id, $user_id]);
    $self = $stmt->fetch();

    if (!$self || $self['status'] !== 'approved') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You are not a member of this conversation"]);
        exit;
    }

    // Fetch all approved participants with their user info and participant role
    $stmt = $pdo->prepare("
        SELECT u.id AS user_id,
               u.first_name,
               u.last_name,
               u.username,
               u.role        AS system_role,
               p.role        AS chat_role,
               p.joined_at
        FROM participants p
        JOIN users u ON p.user_id = u.id
        WHERE p.conversation_id = ? AND p.status = 'approved'
        ORDER BY
            FIELD(p.role, 'admin', 'member'),
            u.first_name ASC
    ");
    $stmt->execute([$conversation_id]);
    $participants = $stmt->fetchAll();

    // Total count (approved only)
    $count = count($participants);

    echo json_encode([
        "success"      => true,
        "count"        => $count,
        "participants" => $participants
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
