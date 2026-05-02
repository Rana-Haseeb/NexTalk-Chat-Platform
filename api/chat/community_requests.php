<?php
// ==============================================
// NexTalk — Community Join Requests API
// Handles: Request to join, list pending requests, approve/reject
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
//  GET — List pending join requests (Admin only)
// ═══════════════════════════════════════
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_requests') {
        try {
            // Retrieve all pending requests for communities where user is admin/moderator
            $stmt = $pdo->prepare("
                SELECT 
                    p.conversation_id, 
                    p.user_id, 
                    u.first_name, 
                    u.last_name, 
                    u.username,
                    u.email,
                    c.id as community_id,
                    c.name as community_name,
                    p.joined_at as request_date
                FROM participants p
                JOIN users u ON p.user_id = u.id
                JOIN conversations c ON p.conversation_id = c.id
                WHERE p.status = 'pending' 
                AND c.type = 'community'
                AND EXISTS (
                    SELECT 1 FROM participants p2 
                    WHERE p2.conversation_id = p.conversation_id 
                    AND p2.user_id = ? 
                    AND p2.role IN ('admin', 'moderator')
                    AND p2.status = 'approved'
                )
                ORDER BY p.joined_at DESC
            ");
            $stmt->execute([$user_id]);
            $requests = $stmt->fetchAll();

            echo json_encode(["success" => true, "requests" => $requests, "count" => count($requests)]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to fetch requests", "error" => $e->getMessage()]);
        }
        exit;
    }

    // Unknown GET action
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid action"]);
    exit;
}

// ═══════════════════════════════════════
//  POST — Request to join or handle requests (Admin)
// ═══════════════════════════════════════
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─── Request to Join Community ───
    if ($action === 'request_join') {
        $conv_id = $_POST['conversation_id'] ?? null;

        if (!$conv_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Community ID is required"]);
            exit;
        }

        try {
            // Verify conversation exists and is a community
            $stmt = $pdo->prepare("SELECT id, type, name FROM conversations WHERE id = ?");
            $stmt->execute([$conv_id]);
            $conv = $stmt->fetch();

            if (!$conv) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Community not found"]);
                exit;
            }

            if ($conv['type'] !== 'community') {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "This is not a community"]);
                exit;
            }

            // Check if already a member or has pending request
            $stmt = $pdo->prepare("SELECT status FROM participants WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conv_id, $user_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ($existing['status'] === 'approved') {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "You are already a member of this community"]);
                } elseif ($existing['status'] === 'rejected') {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "Your previous request was rejected. Please contact an admin."]);
                } else {
                    http_response_code(400);
                    echo json_encode(["success" => false, "message" => "Your join request is pending approval"]);
                }
                exit;
            }

            // Insert pending request
            $stmt = $pdo->prepare("
                INSERT INTO participants (conversation_id, user_id, role, status) 
                VALUES (?, ?, 'member', 'pending')
            ");
            $stmt->execute([$conv_id, $user_id]);

            echo json_encode([
                "success" => true, 
                "message" => "Join request sent to " . $conv['name'] . " admins",
                "community_name" => $conv['name']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to send request", "error" => $e->getMessage()]);
        }
        exit;
    }

    // ─── Handle Request (Approve/Reject) ───
    elseif ($action === 'handle_request') {
        $conv_id = $_POST['conversation_id'] ?? null;
        $target_user_id = $_POST['target_user_id'] ?? null;
        $decision = $_POST['status'] ?? ''; // 'approved' or 'rejected'

        if (!$conv_id || !$target_user_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Community ID and user ID are required"]);
            exit;
        }

        if (!in_array($decision, ['approved', 'rejected'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid decision. Use 'approved' or 'rejected'"]);
            exit;
        }

        try {
            // Verify current user is admin of target community
            $stmt = $pdo->prepare("
                SELECT p.role, c.type, c.name as community_name
                FROM participants p
                JOIN conversations c ON p.conversation_id = c.id
                WHERE p.conversation_id = ? AND p.user_id = ? AND p.status = 'approved'
            ");
            $stmt->execute([$conv_id, $user_id]);
            $admin_part = $stmt->fetch();

            if (!$admin_part) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "You are not a member of this community"]);
                exit;
            }

            if (!in_array($admin_part['role'], ['admin', 'moderator'])) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "You don't have permission to manage join requests"]);
                exit;
            }

            // Verify target user has a pending request
            $stmt = $pdo->prepare("
                SELECT u.first_name, u.last_name
                FROM participants p
                JOIN users u ON p.user_id = u.id
                WHERE p.conversation_id = ? AND p.user_id = ? AND p.status = 'pending'
            ");
            $stmt->execute([$conv_id, $target_user_id]);
            $target = $stmt->fetch();

            if (!$target) {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "No pending request found for this user"]);
                exit;
            }

            // Process decision
            if ($decision === 'approved') {
                $stmt = $pdo->prepare("
                    UPDATE participants 
                    SET status = 'approved' 
                    WHERE conversation_id = ? AND user_id = ? AND status = 'pending'
                ");
                $stmt->execute([$conv_id, $target_user_id]);
                
                echo json_encode([
                    "success" => true,
                    "message" => $target['first_name'] . " " . $target['last_name'] . " has been approved to join " . $admin_part['community_name'],
                    "user_name" => $target['first_name'] . " " . $target['last_name']
                ]);
            } else {
                // Rejected — keep record to prevent repeated requests
                $stmt = $pdo->prepare("
                    UPDATE participants 
                    SET status = 'rejected'
                    WHERE conversation_id = ? AND user_id = ? AND status = 'pending'
                ");
                $stmt->execute([$conv_id, $target_user_id]);
                
                echo json_encode([
                    "success" => true,
                    "message" => $target['first_name'] . " " . $target['last_name'] . "'s request has been rejected",
                    "user_name" => $target['first_name'] . " " . $target['last_name']
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to process request", "error" => $e->getMessage()]);
        }
        exit;
    }

    // Unknown POST action
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid action"]);
    exit;
}

// Invalid method
http_response_code(405);
echo json_encode(["success" => false, "message" => "Method not allowed"]);