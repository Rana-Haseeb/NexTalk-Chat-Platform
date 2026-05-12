<?php
// ==============================================
// NexTalk — Polls API
// Handles: GET (fetch poll results), POST (cast/change vote)
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
//  GET — Fetch poll data for a specific message
// ═══════════════════════════════════════
if ($method === 'GET') {
    $message_id = $_GET['message_id'] ?? null;

    if (!$message_id) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Message ID is required"]);
        exit;
    }

    try {
        // Verify user is a participant in the conversation this poll belongs to
        $convChk = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ?");
        $convChk->execute([$message_id]);
        $convRow = $convChk->fetch();

        if (!$convRow) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Message not found"]);
            exit;
        }

        $partChk = $pdo->prepare(
            "SELECT status FROM participants WHERE conversation_id = ? AND user_id = ? AND status = 'approved'"
        );
        $partChk->execute([$convRow['conversation_id'], $user_id]);
        if (!$partChk->fetch()) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Not a participant in this conversation"]);
            exit;
        }

        // Get poll info
        $stmt = $pdo->prepare("
            SELECT p.id AS poll_id, p.question, p.message_id
            FROM polls p
            WHERE p.message_id = ?
        ");
        $stmt->execute([$message_id]);
        $poll = $stmt->fetch();

        if (!$poll) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Poll not found"]);
            exit;
        }

        // Get options with vote counts
        $optStmt = $pdo->prepare("
            SELECT po.id AS option_id, po.option_text,
                   COUNT(pv.user_id) AS vote_count
            FROM poll_options po
            LEFT JOIN poll_votes pv ON po.id = pv.option_id
            WHERE po.poll_id = ?
            GROUP BY po.id, po.option_text
            ORDER BY po.id ASC
        ");
        $optStmt->execute([$poll['poll_id']]);
        $options = $optStmt->fetchAll();

        // Get total votes
        $totalStmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM poll_votes pv
            JOIN poll_options po ON pv.option_id = po.id
            WHERE po.poll_id = ?
        ");
        $totalStmt->execute([$poll['poll_id']]);
        $totalVotes = (int)$totalStmt->fetch()['total'];

        // Get current user's vote
        $myVoteStmt = $pdo->prepare("
            SELECT pv.option_id
            FROM poll_votes pv
            JOIN poll_options po ON pv.option_id = po.id
            WHERE po.poll_id = ? AND pv.user_id = ?
        ");
        $myVoteStmt->execute([$poll['poll_id'], $user_id]);
        $myVote = $myVoteStmt->fetch();
        $my_vote_option_id = $myVote ? (int)$myVote['option_id'] : null;

        echo json_encode([
            "success" => true,
            "poll" => [
                "poll_id" => (int)$poll['poll_id'],
                "message_id" => (int)$poll['message_id'],
                "question" => $poll['question'],
                "options" => array_map(function($o) {
                    return [
                        "option_id" => (int)$o['option_id'],
                        "option_text" => $o['option_text'],
                        "vote_count" => (int)$o['vote_count']
                    ];
                }, $options),
                "total_votes" => $totalVotes,
                "my_vote_option_id" => $my_vote_option_id
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
    exit;

// ═══════════════════════════════════════
//  POST — Cast or change a vote
// ═══════════════════════════════════════
} elseif ($method === 'POST') {
    $option_id = $_POST['option_id'] ?? null;
    $message_id = $_POST['message_id'] ?? null;

    if (!$option_id || !$message_id) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Option ID and Message ID are required"]);
        exit;
    }

    try {
        // Verify option belongs to the poll for this message
        $verifyStmt = $pdo->prepare("
            SELECT po.id, po.poll_id
            FROM poll_options po
            JOIN polls p ON po.poll_id = p.id
            WHERE po.id = ? AND p.message_id = ?
        ");
        $verifyStmt->execute([$option_id, $message_id]);
        $optionData = $verifyStmt->fetch();

        if (!$optionData) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Invalid poll option"]);
            exit;
        }

        $poll_id = $optionData['poll_id'];

        // Verify the user is a participant in the conversation for this message
        $convStmt = $pdo->prepare("
            SELECT m.conversation_id FROM messages m WHERE m.id = ?
        ");
        $convStmt->execute([$message_id]);
        $msgData = $convStmt->fetch();

        if (!$msgData) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Message not found"]);
            exit;
        }

        $partStmt = $pdo->prepare(
            "SELECT status FROM participants WHERE conversation_id = ? AND user_id = ? AND status = 'approved'"
        );
        $partStmt->execute([$msgData['conversation_id'], $user_id]);
        if (!$partStmt->fetch()) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Not a participant in this conversation"]);
            exit;
        }

        $pdo->beginTransaction();

        // Remove any existing vote for this user in this poll
        $delStmt = $pdo->prepare("
            DELETE pv FROM poll_votes pv
            JOIN poll_options po ON pv.option_id = po.id
            WHERE po.poll_id = ? AND pv.user_id = ?
        ");
        $delStmt->execute([$poll_id, $user_id]);

        // Insert new vote
        $insertStmt = $pdo->prepare(
            "INSERT INTO poll_votes (option_id, user_id) VALUES (?, ?)"
        );
        $insertStmt->execute([$option_id, $user_id]);

        $pdo->commit();

        echo json_encode(["success" => true, "message" => "Vote recorded"]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }

} else {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
}
