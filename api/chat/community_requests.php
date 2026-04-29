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
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'request_join') {
    $conv_id = $_POST['conversation_id'] ?? null;
    
    try {
        $stmt = $pdo->prepare("SELECT type FROM conversations WHERE id = ?");
        $stmt->execute([$conv_id]);
        $conv = $stmt->fetch();
        
        if (!$conv || $conv['type'] !== 'community') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid community"]);
            exit;
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO participants (conversation_id, user_id, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$conv_id, $user_id]);

        echo json_encode(["success" => true, "message" => "Join request sent"]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error"]);
    }
} elseif ($action === 'get_requests') {
    try {
        $stmt = $pdo->prepare("
            SELECT p.conversation_id, p.user_id, u.first_name, u.last_name, u.username, c.name as community_name
            FROM participants p
            JOIN users u ON p.user_id = u.id
            JOIN conversations c ON p.conversation_id = c.id
            WHERE p.status = 'pending' 
            AND c.type = 'community'
            AND EXISTS (
                SELECT 1 FROM participants p2 
                WHERE p2.conversation_id = p.conversation_id 
                AND p2.user_id = ? 
                AND p2.role = 'admin'
            )
        ");
        $stmt->execute([$user_id]);
        $requests = $stmt->fetchAll();

        echo json_encode(["success" => true, "requests" => $requests]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error"]);
    }
} elseif ($action === 'handle_request') {
    $conv_id = $_POST['conversation_id'] ?? null;
    $target_user_id = $_POST['target_user_id'] ?? null;
    $status = $_POST['status'] ?? ''; // 'approved' or 'rejected'

    if (!in_array($status, ['approved', 'rejected'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid status"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT role FROM participants WHERE conversation_id = ? AND user_id = ?");
        $stmt->execute([$conv_id, $user_id]);
        $req_part = $stmt->fetch();

        if (!$req_part || $req_part['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Not authorized"]);
            exit;
        }

        if ($status === 'approved') {
            $stmt = $pdo->prepare("UPDATE participants SET status = 'approved' WHERE conversation_id = ? AND user_id = ? AND status = 'pending'");
            $stmt->execute([$conv_id, $target_user_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM participants WHERE conversation_id = ? AND user_id = ? AND status = 'pending'");
            $stmt->execute([$conv_id, $target_user_id]);
        }

        echo json_encode(["success" => true, "message" => "Request $status"]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error"]);
    }
}
