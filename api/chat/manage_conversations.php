<?php
// ==============================================
// NexTalk — Manage Conversations API (WhatsApp-style)
// Handles: CRUD, leave, delete, forward message,
// typing indicators, read receipts, message deletion
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
$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════
//  GET — List conversations with last message preview
// ═══════════════════════════════════════
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_typing') {
        // Get typing indicators for a conversation
        $conv_id = $_GET['conversation_id'] ?? null;
        if (!$conv_id) {
            echo json_encode(["success" => true, "typing" => []]);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT status FROM participants WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conv_id, $user_id]);
            $participant = $stmt->fetch();

            if (!$participant || $participant['status'] !== 'approved') {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Not a participant in this conversation"]);
                exit;
            }

            // Clean up stale typing indicators (older than 5 seconds)
            $pdo->prepare("DELETE FROM typing_status WHERE started_at < DATE_SUB(NOW(), INTERVAL 5 SECOND)")->execute();

            $stmt = $pdo->prepare("
                SELECT u.first_name, u.last_name
                FROM typing_status ts
                JOIN users u ON ts.user_id = u.id
                WHERE ts.conversation_id = ? AND ts.user_id != ?
            ");
            $stmt->execute([$conv_id, $user_id]);
            $typing = $stmt->fetchAll();
            echo json_encode(["success" => true, "typing" => $typing]);
        } catch (Exception $e) {
            echo json_encode(["success" => true, "typing" => []]);
        }
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT c.*,
                   p.status as my_status,
                   p.role as my_role,
                   lm.content as last_message,
                   lm.media_type as last_media_type,
                   lm.created_at as last_message_time,
                   lm.sender_id as last_sender_id,
                   lu.first_name as last_sender_name,
                   (SELECT COUNT(*) FROM messages m2
                    JOIN message_receipts mr ON m2.id = mr.message_id
                    WHERE m2.conversation_id = c.id
                      AND mr.user_id = ? AND mr.read_at IS NULL
                      AND m2.sender_id != ?
                   ) as unread_count
            FROM conversations c
            LEFT JOIN participants p ON c.id = p.conversation_id AND p.user_id = ?
            LEFT JOIN messages lm ON lm.id = (
                SELECT MAX(id) FROM messages WHERE conversation_id = c.id
            )
            LEFT JOIN users lu ON lm.sender_id = lu.id
            WHERE p.user_id = ?
            ORDER BY COALESCE(lm.created_at, c.created_at) DESC
        ");
        $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$conv) {
            if ($conv['type'] === 'direct') {
                $stmt2 = $pdo->prepare("
                    SELECT u.id, u.first_name, u.last_name, u.is_online, u.last_seen_at
                    FROM participants p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.conversation_id = ? AND p.user_id != ?
                ");
                $stmt2->execute([$conv['id'], $user_id]);
                $other_user = $stmt2->fetch();
                if ($other_user) {
                    $conv['name'] = $other_user['first_name'] . ' ' . $other_user['last_name'];
                    $conv['other_user_id'] = $other_user['id'];
                    $conv['is_online'] = (int)$other_user['is_online'];
                    $conv['last_seen_at'] = $other_user['last_seen_at'];
                }
            }

            // Format last message preview
            if ($conv['last_message'] && $conv['last_media_type']) {
                $media_icons = ['image' => '📷 Photo', 'document' => '📄 Document', 'audio' => '🎵 Audio', 'video' => '🎬 Video', 'poll' => '📊 Poll'];
                $conv['last_message_preview'] = ($conv['last_sender_id'] == $user_id ? 'You: ' : '')
                    . ($media_icons[$conv['last_media_type']] ?? 'Attachment');
            } elseif ($conv['last_message']) {
                $preview = mb_substr($conv['last_message'], 0, 40);
                if (mb_strlen($conv['last_message']) > 40) $preview .= '...';
                $conv['last_message_preview'] = ($conv['last_sender_id'] == $user_id ? 'You: ' : '') . $preview;
            } else {
                $conv['last_message_preview'] = '';
            }
        }

        echo json_encode(["success" => true, "conversations" => $conversations]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }

// ═══════════════════════════════════════
//  POST — Actions
// ═══════════════════════════════════════
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    $requireParticipant = function(int $conv_id) use ($pdo, $user_id): void {
        $s = $pdo->prepare(
            "SELECT status FROM participants
             WHERE conversation_id = ? AND user_id = ?"
        );
        $s->execute([$conv_id, $user_id]);
        $row = $s->fetch();
        if (!$row || $row['status'] !== 'approved') {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "message" => "Not a participant in this conversation"
            ]);
            exit;
        }
    };

    // ─── Create Direct Message ───
    if ($action === 'create_direct') {
        $target_username = trim($_POST['target_username'] ?? '');
        if (!$target_username) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Target username/email is required"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$target_username, $target_username]);
            $target_user = $stmt->fetch();

            if (!$target_user) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "User not found"]);
                exit;
            }

            $target_id = $target_user['id'];
            if ($target_id == $user_id) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Cannot create direct message with yourself"]);
                exit;
            }

            // ─── Block check ───
            $blockStmt = $pdo->prepare(
                "SELECT 1 FROM blocked_users
                 WHERE (blocker_id = ? AND blocked_id = ?)
                    OR (blocker_id = ? AND blocked_id = ?)
                 LIMIT 1"
            );
            $blockStmt->execute([$user_id, $target_id, $target_id, $user_id]);
            if ($blockStmt->fetch()) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Cannot create a conversation with this user."]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT c.id FROM conversations c
                JOIN participants p1 ON c.id = p1.conversation_id
                JOIN participants p2 ON c.id = p2.conversation_id
                WHERE c.type = 'direct' AND p1.user_id = ? AND p2.user_id = ?
            ");
            $stmt->execute([$user_id, $target_id]);
            $existing_dm = $stmt->fetch();

            if ($existing_dm) {
                echo json_encode(["success" => true, "conversation_id" => $existing_dm['id'], "message" => "DM already exists"]);
                exit;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO conversations (type, created_by) VALUES ('direct', ?)");
            $stmt->execute([$user_id]);
            $conv_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO participants (conversation_id, user_id, status) VALUES (?, ?, 'approved'), (?, ?, 'approved')");
            $stmt->execute([$conv_id, $user_id, $conv_id, $target_id]);
            $pdo->commit();

            echo json_encode(["success" => true, "conversation_id" => $conv_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Create Group ───
    } elseif ($action === 'create_group') {
        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Group name is required"]);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO conversations (type, name, created_by) VALUES ('group', ?, ?)");
            $stmt->execute([$name, $user_id]);
            $conv_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO participants (conversation_id, user_id, role, status) VALUES (?, ?, 'admin', 'approved')");
            $stmt->execute([$conv_id, $user_id]);
            $pdo->commit();

            echo json_encode(["success" => true, "conversation_id" => $conv_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Create Community ───
    } elseif ($action === 'create_community') {
        if (!in_array($_SESSION['role'] ?? '', ['admin', 'moderator'])) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Only admins can create communities"]);
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        if (!$name) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Community name is required"]);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO conversations (type, name, created_by) VALUES ('community', ?, ?)");
            $stmt->execute([$name, $user_id]);
            $conv_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO participants (conversation_id, user_id, role, status) VALUES (?, ?, 'admin', 'approved')");
            $stmt->execute([$conv_id, $user_id]);
            $pdo->commit();

            echo json_encode(["success" => true, "conversation_id" => $conv_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Add User to Conversation ───
    } elseif ($action === 'add_user') {
        $conv_id = $_POST['conversation_id'] ?? null;
        $target_username = trim($_POST['target_username'] ?? '');

        if (!$conv_id || !$target_username) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Conversation ID and target user are required"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT role, type FROM participants p JOIN conversations c ON p.conversation_id = c.id WHERE p.conversation_id = ? AND p.user_id = ?");
            $stmt->execute([$conv_id, $user_id]);
            $req_part = $stmt->fetch();

            if (!$req_part || $req_part['role'] !== 'admin' || $req_part['type'] === 'direct') {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "You don't have permission to add users to this group"]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$target_username, $target_username]);
            $target_user = $stmt->fetch();

            if (!$target_user) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "User not found"]);
                exit;
            }

            $checkStmt = $pdo->prepare(
                "SELECT status FROM participants
                 WHERE conversation_id = ? AND user_id = ?"
            );
            $checkStmt->execute([$conv_id, $target_user['id']]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                if ($existing['status'] === 'approved') {
                    echo json_encode([
                        "success" => false,
                        "message" => "User is already a member of this conversation"
                    ]);
                } elseif ($existing['status'] === 'rejected') {
                    $upd = $pdo->prepare(
                        "UPDATE participants SET status = 'approved'
                         WHERE conversation_id = ? AND user_id = ?"
                    );
                    $upd->execute([$conv_id, $target_user['id']]);
                    echo json_encode([
                        "success" => true,
                        "message" => "Previously rejected user has been approved and added"
                    ]);
                } else {
                    $upd = $pdo->prepare(
                        "UPDATE participants SET status = 'approved'
                         WHERE conversation_id = ? AND user_id = ?"
                    );
                    $upd->execute([$conv_id, $target_user['id']]);
                    echo json_encode([
                        "success" => true,
                        "message" => "Pending user has been approved and added"
                    ]);
                }
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO participants (conversation_id, user_id, role, status)
                     VALUES (?, ?, 'member', 'approved')"
                );
                $ins->execute([$conv_id, $target_user['id']]);
                echo json_encode(["success" => true, "message" => "User added successfully"]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Leave Conversation ───
    } elseif ($action === 'leave_conversation') {
        $conv_id = $_POST['conversation_id'] ?? null;
        if (!$conv_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Conversation ID is required"]);
            exit;
        }

        try {
            // Check conversation type — cannot leave DMs
            $stmt = $pdo->prepare("SELECT type FROM conversations WHERE id = ?");
            $stmt->execute([$conv_id]);
            $conv = $stmt->fetch();

            if (!$conv || $conv['type'] === 'direct') {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Cannot leave this conversation"]);
                exit;
            }

            $roleStmt = $pdo->prepare(
                "SELECT role FROM participants WHERE conversation_id = ? AND user_id = ?"
            );
            $roleStmt->execute([$conv_id, $user_id]);
            $myRole = $roleStmt->fetch();

            if ($myRole && $myRole['role'] === 'admin') {
                $adminCountStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM participants WHERE conversation_id = ? AND role = 'admin'"
                );
                $adminCountStmt->execute([$conv_id]);
                if ((int)$adminCountStmt->fetchColumn() === 1) {
                    http_response_code(400);
                    echo json_encode([
                        "success" => false,
                        "message" => "You are the only admin. Promote another member before leaving."
                    ]);
                    exit;
                }
            }

            $stmt = $pdo->prepare("DELETE FROM participants WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conv_id, $user_id]);

            echo json_encode(["success" => true, "message" => "You have left the conversation"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Delete Conversation (Admin only) ───
    } elseif ($action === 'delete_conversation') {
        $conv_id = $_POST['conversation_id'] ?? null;
        if (!$conv_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Conversation ID is required"]);
            exit;
        }

        try {
            // Must be conversation admin or system admin
            $stmt = $pdo->prepare("SELECT role FROM participants WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conv_id, $user_id]);
            $part = $stmt->fetch();

            $is_sys_admin = ($_SESSION['role'] === 'admin');
            $is_conv_admin = ($part && $part['role'] === 'admin');

            if (!$is_sys_admin && !$is_conv_admin) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Only admins can delete conversations"]);
                exit;
            }

            $convTypeStmt = $pdo->prepare("SELECT type FROM conversations WHERE id = ?");
            $convTypeStmt->execute([$conv_id]);
            $convType = $convTypeStmt->fetch();
            if (!$convType || $convType['type'] === 'direct') {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Cannot delete direct message conversations"]);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM conversations WHERE id = ?");
            $stmt->execute([$conv_id]);

            echo json_encode(["success" => true, "message" => "Conversation deleted"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Delete Message ───
    } elseif ($action === 'delete_message') {
        $message_id = $_POST['message_id'] ?? null;
        $delete_type = $_POST['delete_type'] ?? 'for_me'; // 'for_me' or 'for_all'

        if (!$message_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Message ID is required"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT sender_id, conversation_id FROM messages WHERE id = ?");
            $stmt->execute([$message_id]);
            $msg = $stmt->fetch();

            if (!$msg) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Message not found"]);
                exit;
            }

            $partStmt = $pdo->prepare(
                "SELECT status FROM participants WHERE conversation_id = ? AND user_id = ? AND status = 'approved'"
            );
            $partStmt->execute([$msg['conversation_id'], $user_id]);
            if (!$partStmt->fetch()) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Not a participant in this conversation"]);
                exit;
            }

            if ($delete_type === 'for_all') {
                // Only sender can delete for everyone
                if ($msg['sender_id'] != $user_id) {
                    http_response_code(403);
                    echo json_encode(["success" => false, "message" => "Only the sender can delete for everyone"]);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE messages SET deleted_for_all = 1, content = NULL, media_url = NULL, media_name = NULL WHERE id = ?");
                $stmt->execute([$message_id]);

                // Clean up poll data if this was a poll message
                // (ON DELETE CASCADE on poll_options and poll_votes handles child rows automatically)
                $cleanPoll = $pdo->prepare("DELETE FROM polls WHERE message_id = ?");
                $cleanPoll->execute([$message_id]);
            } else {
                // Delete for me
                $stmt = $pdo->prepare("INSERT IGNORE INTO message_deletions (message_id, user_id) VALUES (?, ?)");
                $stmt->execute([$message_id, $user_id]);
            }

            echo json_encode(["success" => true, "message" => "Message deleted"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Forward Message ───
    } elseif ($action === 'forward_message') {
        $message_id = $_POST['message_id'] ?? null;
        $target_conv_id = $_POST['target_conversation_id'] ?? null;

        if (!$message_id || !$target_conv_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Message ID and target conversation are required"]);
            exit;
        }

        try {
            // Check user is participant in target conversation
            $stmt = $pdo->prepare("SELECT status FROM participants WHERE conversation_id = ? AND user_id = ? AND status = 'approved'");
            $stmt->execute([$target_conv_id, $user_id]);
            if (!$stmt->fetch()) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Not a participant in target conversation"]);
                exit;
            }

            // Get original message
            $stmt = $pdo->prepare("SELECT content, media_url, media_type, media_name FROM messages WHERE id = ? AND deleted_for_all = 0");
            $stmt->execute([$message_id]);
            $orig = $stmt->fetch();

            if (!$orig) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Original message not found"]);
                exit;
            }

            // ─── Target conversation guards (DM-only rules) ───
            $tgtStmt = $pdo->prepare("SELECT type FROM conversations WHERE id = ?");
            $tgtStmt->execute([$target_conv_id]);
            $targetType = $tgtStmt->fetchColumn();

            if ($targetType === 'direct') {
                // Polls are not allowed in DMs — forwarding must not bypass this.
                if ($orig['media_type'] === 'poll') {
                    http_response_code(400);
                    echo json_encode([
                        "success" => false,
                        "message" => "Polls cannot be forwarded into direct messages"
                    ]);
                    exit;
                }

                // Block check — forwarding must not bypass blocking either.
                $otherStmt = $pdo->prepare(
                    "SELECT user_id FROM participants
                     WHERE conversation_id = ? AND user_id != ?"
                );
                $otherStmt->execute([$target_conv_id, $user_id]);
                $other_id = $otherStmt->fetchColumn();

                if ($other_id) {
                    $blockStmt = $pdo->prepare(
                        "SELECT 1 FROM blocked_users
                         WHERE (blocker_id = ? AND blocked_id = ?)
                            OR (blocker_id = ? AND blocked_id = ?)
                         LIMIT 1"
                    );
                    $blockStmt->execute([$user_id, $other_id, $other_id, $user_id]);
                    if ($blockStmt->fetch()) {
                        http_response_code(403);
                        echo json_encode([
                            "success" => false,
                            "message" => "Cannot forward — there is a block between you and this user."
                        ]);
                        exit;
                    }
                }
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO messages (conversation_id, sender_id, content, media_url, media_type, media_name, forwarded_from, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'sent')
            ");
            $stmt->execute([
                $target_conv_id, $user_id,
                $orig['content'], $orig['media_url'], $orig['media_type'], $orig['media_name'],
                $message_id
            ]);

            $new_id = $pdo->lastInsertId();

            // ─── Copy poll data if forwarding a poll ───
            if ($orig['media_type'] === 'poll') {
                $srcPoll = $pdo->prepare("SELECT question FROM polls WHERE message_id = ?");
                $srcPoll->execute([$message_id]);
                $pollRow = $srcPoll->fetch();
                if ($pollRow) {
                    $newPollStmt = $pdo->prepare("INSERT INTO polls (message_id, question) VALUES (?, ?)");
                    $newPollStmt->execute([$new_id, $pollRow['question']]);
                    $new_poll_id = $pdo->lastInsertId();

                    $srcOpts = $pdo->prepare(
                        "SELECT option_text FROM poll_options WHERE poll_id = (SELECT id FROM polls WHERE message_id = ?)"
                    );
                    $srcOpts->execute([$message_id]);
                    $optCopy = $pdo->prepare("INSERT INTO poll_options (poll_id, option_text) VALUES (?, ?)");
                    foreach ($srcOpts->fetchAll() as $opt) {
                        $optCopy->execute([$new_poll_id, $opt['option_text']]);
                    }
                }
            }

            // Create receipts
            $stmt = $pdo->prepare("
                INSERT INTO message_receipts (message_id, user_id)
                SELECT ?, user_id FROM participants
                WHERE conversation_id = ? AND user_id != ? AND status = 'approved'
            ");
            $stmt->execute([$new_id, $target_conv_id, $user_id]);

            $pdo->commit();

            echo json_encode(["success" => true, "message_id" => $new_id]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Mark Messages as Read ───
    } elseif ($action === 'mark_read') {
        $conv_id = $_POST['conversation_id'] ?? null;
        if (!$conv_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Conversation ID is required"]);
            exit;
        }

        $requireParticipant((int)$conv_id);

        try {
            $stmt = $pdo->prepare("
                UPDATE message_receipts mr
                JOIN messages m ON mr.message_id = m.id
                SET mr.read_at = CURRENT_TIMESTAMP,
                    mr.delivered_at = COALESCE(mr.delivered_at, CURRENT_TIMESTAMP)
                WHERE m.conversation_id = ? AND mr.user_id = ? AND mr.read_at IS NULL
            ");
            $stmt->execute([$conv_id, $user_id]);

            // Update message status to 'read' where all recipients have read
            $stmt = $pdo->prepare("
                UPDATE messages m SET m.status = 'read'
                WHERE m.conversation_id = ? AND m.status IN ('sent', 'delivered')
                  AND NOT EXISTS (
                    SELECT 1 FROM message_receipts mr
                    WHERE mr.message_id = m.id AND mr.read_at IS NULL
                  )
            ");
            $stmt->execute([$conv_id]);

            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Typing Indicator ───
    } elseif ($action === 'typing_start') {
        $conv_id = $_POST['conversation_id'] ?? null;
        if ($conv_id) {
            $requireParticipant((int)$conv_id);
            try {
                $stmt = $pdo->prepare("INSERT INTO typing_status (conversation_id, user_id, started_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE started_at = NOW()");
                $stmt->execute([$conv_id, $user_id]);
            } catch (Exception $e) { /* silent */ }
        }
        echo json_encode(["success" => true]);

    } elseif ($action === 'typing_stop') {
        $conv_id = $_POST['conversation_id'] ?? null;
        if ($conv_id) {
            $requireParticipant((int)$conv_id);
            try {
                $stmt = $pdo->prepare("DELETE FROM typing_status WHERE conversation_id = ? AND user_id = ?");
                $stmt->execute([$conv_id, $user_id]);
            } catch (Exception $e) { /* silent */ }
        }
        echo json_encode(["success" => true]);

    // ─── Heartbeat (Online Status) ───
    } elseif ($action === 'heartbeat') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_online = 1, last_seen_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id]);
            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            echo json_encode(["success" => true]);
        }

    // ─── Remove User from Conversation (Admin only) ───
    } elseif ($action === 'remove_user') {
        $conv_id = $_POST['conversation_id'] ?? null;
        $target_user_id = $_POST['target_user_id'] ?? null;

        if (!$conv_id || !$target_user_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Conversation ID and target user ID are required"]);
            exit;
        }

        if ($target_user_id == $user_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Cannot remove yourself. Use 'Leave' instead"]);
            exit;
        }

        try {
            // Verify requesting user is admin of this conversation
            $stmt = $pdo->prepare("SELECT role FROM participants WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conv_id, $user_id]);
            $requester = $stmt->fetch();

            if (!$requester || $requester['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Only conversation admins can remove users"]);
                exit;
            }

            // Verify conversation is not a DM
            $stmt = $pdo->prepare("SELECT type FROM conversations WHERE id = ?");
            $stmt->execute([$conv_id]);
            $conv = $stmt->fetch();

            if (!$conv || $conv['type'] === 'direct') {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Cannot remove users from direct messages"]);
                exit;
            }

            // Remove the target user
            $stmt = $pdo->prepare("DELETE FROM participants WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conv_id, $target_user_id]);

            if ($stmt->rowCount() === 0) {
                echo json_encode(["success" => false, "message" => "User is not a participant"]);
                exit;
            }

            echo json_encode(["success" => true, "message" => "User removed from conversation"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Block User (DMs only) ───
    } elseif ($action === 'block_user') {
        $target_user_id = $_POST['target_user_id'] ?? null;

        if (!$target_user_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Target user ID is required"]);
            exit;
        }

        if ($target_user_id == $user_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Cannot block yourself"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)"
            );
            $stmt->execute([$user_id, $target_user_id]);
            echo json_encode(["success" => true, "message" => "User blocked successfully"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Unblock User ───
    } elseif ($action === 'unblock_user') {
        $target_user_id = $_POST['target_user_id'] ?? null;

        if (!$target_user_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Target user ID is required"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                "DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?"
            );
            $stmt->execute([$user_id, $target_user_id]);
            echo json_encode(["success" => true, "message" => "User unblocked successfully"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }

    // ─── Check Block Status (GET-style via POST for consistency) ───
    } elseif ($action === 'check_block') {
        $target_user_id = $_POST['target_user_id'] ?? null;

        if (!$target_user_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Target user ID is required"]);
            exit;
        }

        try {
            // Check if current user blocked the target
            $stmt = $pdo->prepare(
                "SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ? LIMIT 1"
            );
            $stmt->execute([$user_id, $target_user_id]);
            $i_blocked = (bool)$stmt->fetch();

            // Check if target blocked the current user
            $stmt2 = $pdo->prepare(
                "SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ? LIMIT 1"
            );
            $stmt2->execute([$target_user_id, $user_id]);
            $they_blocked = (bool)$stmt2->fetch();

            echo json_encode([
                "success" => true,
                "i_blocked" => $i_blocked,
                "they_blocked" => $they_blocked,
                "any_block" => $i_blocked || $they_blocked
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }
    }
}