<?php
// ==============================================
// NexTalk — SSE (Server-Sent Events) Endpoint
// Real-time event stream replacing polling
// ==============================================
session_start();
require_once "../db.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["error" => "Not authorized"]);
    exit;
}

// SSE headers
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("Connection: keep-alive");
header("X-Accel-Buffering: no");

// Disable output buffering
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level()) ob_end_clean();

$user_id = $_SESSION["user_id"];
$conversation_id = $_GET['conversation_id'] ?? null;
$last_message_id = (int)($_GET['last_message_id'] ?? 0);

if ($conversation_id) {
    $chk = $pdo->prepare(
        "SELECT status FROM participants
         WHERE conversation_id = ? AND user_id = ?"
    );
    $chk->execute([$conversation_id, $user_id]);
    $row = $chk->fetch();
    if (!$row || $row['status'] !== 'approved') {
        http_response_code(403);
        header("Content-Type: application/json");
        echo json_encode(["error" => "Not authorized to access this conversation"]);
        exit;
    }
}

// Crucial: Close session to prevent lock
session_write_close();

// Mark user as online
$stmt = $pdo->prepare("UPDATE users SET is_online = 1, last_seen_at = NOW() WHERE id = ?");
$stmt->execute([$user_id]);

$max_execution = 25; // seconds per SSE connection (then client reconnects)
$start = time();
$poll_ms = 1500; // poll database every 1.5 seconds
$last_deletion_id = 0;
$knownStatuses = [];
$lastTypingJson = "";
// High-water mark for poll vote detection — use MySQL clock so it
// matches poll_votes.voted_at regardless of PHP timezone differences.
$lastPollVoteAt = $pdo->query("SELECT NOW()")->fetchColumn();

while ((time() - $start) < $max_execution) {
    // Check if client disconnected
    if (connection_aborted()) break;

    $events = [];

    try {
        // 1. Check for new messages in current conversation
        if ($conversation_id) {
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
                LEFT JOIN message_deletions md ON m.id = md.message_id AND md.user_id = ?
                WHERE m.conversation_id = ? AND m.id > ?
                  AND md.message_id IS NULL
                ORDER BY m.id ASC
            ");
            $stmt->execute([$user_id, $conversation_id, $last_message_id]);
            $new_messages = $stmt->fetchAll();

            if (!empty($new_messages)) {
                foreach ($new_messages as &$msg) {
                    if ($msg['deleted_for_all']) {
                        $msg['content'] = '🚫 This message was deleted';
                        $msg['media_url'] = null;
                        $msg['media_type'] = null;
                        $msg['media_name'] = null;
                    }
                    $last_message_id = max($last_message_id, (int)$msg['id']);
                }
                $events[] = "event: messages\ndata: " . json_encode($new_messages);

                // Mark as delivered
                $stmt = $pdo->prepare("
                    UPDATE message_receipts SET delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP)
                    WHERE user_id = ? AND delivered_at IS NULL
                      AND message_id IN (SELECT id FROM messages WHERE conversation_id = ? AND sender_id != ?)
                ");
                $stmt->execute([$user_id, $conversation_id, $user_id]);
            }

            // 2. Check typing indicators
            $pdo->prepare("DELETE FROM typing_status WHERE started_at < DATE_SUB(NOW(), INTERVAL 5 SECOND)")->execute();
            $stmt = $pdo->prepare("
                SELECT u.first_name FROM typing_status ts
                JOIN users u ON ts.user_id = u.id
                WHERE ts.conversation_id = ? AND ts.user_id != ?
            ");
            $stmt->execute([$conversation_id, $user_id]);
            $typing = $stmt->fetchAll();
            $typingJson = json_encode($typing);
            if ($typingJson !== $lastTypingJson) {
                $events[] = "event: typing\ndata: " . $typingJson;
                $lastTypingJson = $typingJson;
            }

            // 3. Check for status updates on own messages
            $stmt = $pdo->prepare("
                SELECT id, status FROM messages
                WHERE conversation_id = ? AND sender_id = ?
                  AND status != 'read'
                ORDER BY id DESC LIMIT 50
            ");
            $stmt->execute([$conversation_id, $user_id]);
            $status_updates = $stmt->fetchAll();
            $changed = [];
            foreach ($status_updates as $su) {
                $mid = (int)$su['id'];
                if (!isset($knownStatuses[$mid]) || $knownStatuses[$mid] !== $su['status']) {
                    $knownStatuses[$mid] = $su['status'];
                    $changed[] = $su;
                }
            }
            if (!empty($changed)) {
                $events[] = "event: status\ndata: " . json_encode($changed);
            }

            // 4. Check for delete-for-everyone updates
            $stmt = $pdo->prepare("
                SELECT id FROM messages
                WHERE conversation_id = ? AND deleted_for_all = 1
                  AND id > ?
                ORDER BY id ASC
            ");
            $stmt->execute([$conversation_id, $last_deletion_id]);
            $deleted = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($deleted)) {
                $last_deletion_id = max(array_map('intval', $deleted));
                $events[] = "event: deletions\ndata: " . json_encode(array_map('intval', $deleted));
            }

            // 5. Check for new poll votes in this conversation
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.message_id
                FROM poll_votes pv
                JOIN poll_options po ON pv.option_id = po.id
                JOIN polls p ON po.poll_id = p.id
                JOIN messages m ON p.message_id = m.id
                WHERE m.conversation_id = ? AND pv.voted_at > ?
            ");
            $stmt->execute([$conversation_id, $lastPollVoteAt]);
            $updatedPolls = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($updatedPolls)) {
                // Advance the high-water mark using MySQL's clock (same source as voted_at).
                $lastPollVoteAt = $pdo->query("SELECT NOW()")->fetchColumn();
                $events[] = "event: poll_update\ndata: " . json_encode(array_map('intval', $updatedPolls));
            }
        }

        // 4. Update heartbeat
        $stmt = $pdo->prepare("UPDATE users SET is_online = 1, last_seen_at = NOW() WHERE id = ?");
        $stmt->execute([$user_id]);

    } catch (Exception $e) {
        // Silent — don't break the SSE stream
    }

    // Send events
    if (!empty($events)) {
        foreach ($events as $evt) {
            echo $evt . "\n\n";
        }
    } else {
        // Send heartbeat to keep connection alive
        echo ": heartbeat\n\n";
    }

    if (ob_get_level()) ob_flush();
    flush();

    // Sleep before next poll
    usleep($poll_ms * 1000);
}

// Connection ending — mark last seen
$stmt = $pdo->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ?");
$stmt->execute([$user_id]);

echo "event: reconnect\ndata: {}\n\n";
if (ob_get_level()) ob_flush();
flush();