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
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   p.status as my_status, 
                   p.role as my_role
            FROM conversations c
            LEFT JOIN participants p ON c.id = p.conversation_id AND p.user_id = ?
            WHERE c.type = 'community' OR p.user_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$user_id, $user_id]);
        $conversations = $stmt->fetchAll();
        
        foreach ($conversations as &$conv) {
            if ($conv['type'] === 'direct') {
                $stmt2 = $pdo->prepare("
                    SELECT u.first_name, u.last_name 
                    FROM participants p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.conversation_id = ? AND p.user_id != ?
                ");
                $stmt2->execute([$conv['id'], $user_id]);
                $other_user = $stmt2->fetch();
                if ($other_user) {
                    $conv['name'] = $other_user['first_name'] . ' ' . $other_user['last_name'];
                }
            }
        }

        echo json_encode(["success" => true, "conversations" => $conversations]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';

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
    } elseif ($action === 'create_community') {
        if ($_SESSION['role'] !== 'admin') {
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

            $stmt = $pdo->prepare("INSERT IGNORE INTO participants (conversation_id, user_id, status) VALUES (?, ?, 'approved')");
            $stmt->execute([$conv_id, $target_user['id']]);

            echo json_encode(["success" => true, "message" => "User added successfully"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error"]);
        }
    }
}
