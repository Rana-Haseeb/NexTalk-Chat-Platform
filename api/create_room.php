<?php
// ==============================================
// NexTalk — Create Room API (POST)
// Requires: minimum 'moderator' role
// ==============================================
session_start();
header("Content-Type: application/json");

// Only POST allowed
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit;
}

require_once "db.php";
require_once "roles.php";

// ── Auth & Role Check ──
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not logged in."]);
    exit;
}

$userRole = nex_normalize_role($_SESSION["role"] ?? NEX_ROLE_MEMBER);
if (!nex_has_min_role($userRole, NEX_ROLE_MODERATOR)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "You need at least Moderator access to create rooms."]);
    exit;
}

// ── Input Validation ──
$name        = trim($_POST["name"] ?? "");
$description = trim($_POST["description"] ?? "");

if (empty($name)) {
    echo json_encode(["success" => false, "message" => "Room name is required."]);
    exit;
}

if (strlen($name) > 100) {
    echo json_encode(["success" => false, "message" => "Room name must be 100 characters or less."]);
    exit;
}

if (strlen($description) > 255) {
    echo json_encode(["success" => false, "message" => "Description must be 255 characters or less."]);
    exit;
}

// ── Check for duplicate room name ──
try {
    $check = $pdo->prepare("SELECT id FROM rooms WHERE name = :name LIMIT 1");
    $check->execute([":name" => $name]);
    if ($check->fetch()) {
        echo json_encode(["success" => false, "message" => "A room with this name already exists."]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while checking room name."]);
    exit;
}

// ── Insert Room ──
try {
    $stmt = $pdo->prepare(
        "INSERT INTO rooms (name, description, created_by) VALUES (:name, :description, :created_by)"
    );
    $stmt->execute([
        ":name"        => $name,
        ":description" => $description,
        ":created_by"  => $_SESSION["user_id"],
    ]);

    $newRoomId = $pdo->lastInsertId();

    echo json_encode([
        "success" => true,
        "message" => "Room '{$name}' created successfully!",
        "room"    => [
            "id"          => (int)$newRoomId,
            "name"        => $name,
            "description" => $description,
            "created_by"  => $_SESSION["user_id"],
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while creating the room."]);
    exit;
}
