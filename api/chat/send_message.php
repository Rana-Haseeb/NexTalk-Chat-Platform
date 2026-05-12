<?php
// ==============================================
// NexTalk — Send Message API (WhatsApp-style)
// Supports: text, media upload, reply, forward, polls
// ==============================================
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
$reply_to_id = $_POST['reply_to_id'] ?? null;
$forwarded_from = $_POST['forwarded_from'] ?? null;

// ─── Poll data ───
$poll_question = trim($_POST['poll_question'] ?? '');
$poll_options = $_POST['poll_options'] ?? [];

// Handle media upload
$media_url = null;
$media_type = null;
$media_name = null;

if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['media'];
    $allowed_types = [
        'image/jpeg' => 'image', 'image/png' => 'image', 'image/gif' => 'image', 'image/webp' => 'image',
        'application/pdf' => 'document', 'application/msword' => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
        'application/zip' => 'document', 'text/plain' => 'document',
        'audio/mpeg' => 'audio', 'audio/wav' => 'audio', 'audio/ogg' => 'audio', 'audio/webm' => 'audio',
        'video/mp4' => 'video', 'video/webm' => 'video',
    ];

    $file_mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed_types[$file_mime])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "File type not allowed: $file_mime"]);
        exit;
    }

    // 25MB limit
    if ($file['size'] > 25 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "File too large. Maximum 25MB."]);
        exit;
    }

    $media_type = $allowed_types[$file_mime];
    $media_name = basename($file['name']);

    // Strict MIME-to-extension map — never trust user-supplied extensions
    $mime_to_ext = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/zip' => 'zip', 'text/plain' => 'txt',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/ogg' => 'ogg', 'audio/webm' => 'weba',
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
    ];
    $ext = $mime_to_ext[$file_mime] ?? 'bin';
    $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;

    $upload_dir = __DIR__ . '/../../uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $dest = $upload_dir . $safe_name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to save file"]);
        exit;
    }

    $media_url = 'uploads/' . $safe_name;
}

// ─── Determine if this is a poll message ───
$is_poll = false;
$poll_options_clean = is_array($poll_options)
    ? array_values(array_filter(array_map('trim', $poll_options), fn($o) => $o !== ''))
    : [];
if ($poll_question && count($poll_options_clean) >= 2) {
    $is_poll = true;
    $media_type = 'poll';
    // Set content to the poll question for preview purposes
    if (!$content) {
        $content = "📊 " . $poll_question;
    }
}

// Must have content or media or poll
if (!$content && !$media_url && !$is_poll) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Message content or media is required"]);
    exit;
}

if (!$conversation_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Conversation ID is required"]);
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

    // ─── Block check for DMs ───
    $convStmt = $pdo->prepare("SELECT type FROM conversations WHERE id = ?");
    $convStmt->execute([$conversation_id]);
    $convRow = $convStmt->fetch();

    if ($convRow && $convRow['type'] === 'direct') {
        if ($is_poll) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Polls are not allowed in direct messages"]);
            exit;
        }

        // Get the other user in the DM
        $otherStmt = $pdo->prepare(
            "SELECT user_id FROM participants WHERE conversation_id = ? AND user_id != ?"
        );
        $otherStmt->execute([$conversation_id, $user_id]);
        $otherUser = $otherStmt->fetch();

        if ($otherUser) {
            $other_id = $otherUser['user_id'];
            $blockStmt = $pdo->prepare(
                "SELECT 1 FROM blocked_users
                 WHERE (blocker_id = ? AND blocked_id = ?)
                    OR (blocker_id = ? AND blocked_id = ?)
                 LIMIT 1"
            );
            $blockStmt->execute([$user_id, $other_id, $other_id, $user_id]);
            if ($blockStmt->fetch()) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Cannot send messages. There is a block between you and this user."]);
                exit;
            }
        }
    }

    // Validate reply_to_id belongs to same conversation
    if ($reply_to_id) {
        $stmt = $pdo->prepare("SELECT id FROM messages WHERE id = ? AND conversation_id = ?");
        $stmt->execute([$reply_to_id, $conversation_id]);
        if (!$stmt->fetch()) $reply_to_id = null;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO messages (conversation_id, sender_id, content, media_url, media_type, media_name, reply_to_id, forwarded_from, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent')
    ");
    $stmt->execute([
        $conversation_id, $user_id,
        $content ?: null, $media_url, $media_type, $media_name,
        $reply_to_id ?: null, $forwarded_from ?: null
    ]);

    $message_id = $pdo->lastInsertId();

    // ─── Insert poll data if applicable ───
    if ($is_poll) {
        $pollStmt = $pdo->prepare("INSERT INTO polls (message_id, question) VALUES (?, ?)");
        $pollStmt->execute([$message_id, $poll_question]);
        $poll_id = $pdo->lastInsertId();

        $optStmt = $pdo->prepare("INSERT INTO poll_options (poll_id, option_text) VALUES (?, ?)");
        foreach ($poll_options_clean as $opt) {
            $optStmt->execute([$poll_id, $opt]);
        }
    }

    // Create delivery receipts for all other participants
    $stmt = $pdo->prepare("
        INSERT INTO message_receipts (message_id, user_id)
        SELECT ?, user_id FROM participants
        WHERE conversation_id = ? AND user_id != ? AND status = 'approved'
    ");
    $stmt->execute([$message_id, $conversation_id, $user_id]);

    // Clear typing indicator
    $stmt = $pdo->prepare("DELETE FROM typing_status WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversation_id, $user_id]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message_id" => $message_id,
        "media_url" => $media_url
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}