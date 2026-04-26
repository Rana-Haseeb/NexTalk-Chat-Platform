<?php
// ==============================================
// NexTalk — Update Room API (POST)
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
    echo json_encode(["success" => false, "message" => "You need at least Moderator access to update rooms."]);
    exit;
}

// ── Input Validation ──
$roomId      = intval($_POST["id"] ?? 0);
$name        = trim($_POST["name"] ?? "");
$description = trim($_POST["description"] ?? "");

if ($roomId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid room ID."]);
    exit;
}

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

// ── Check room exists ──
try {
    $check = $pdo->prepare("SELECT id FROM rooms WHERE id = :id LIMIT 1");
    $check->execute([":id" => $roomId]);
    if (!$check->fetch()) {
        echo json_encode(["success" => false, "message" => "Room not found."]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while verifying room."]);
    exit;
}

// ── Check for duplicate name (excluding current room) ──
try {
    $dup = $pdo->prepare("SELECT id FROM rooms WHERE name = :name AND id != :id LIMIT 1");
    $dup->execute([":name" => $name, ":id" => $roomId]);
    if ($dup->fetch()) {
        echo json_encode(["success" => false, "message" => "Another room with this name already exists."]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while checking name uniqueness."]);
    exit;
}

// ── Update Room ──
try {
    $stmt = $pdo->prepare(
        "UPDATE rooms SET name = :name, description = :description WHERE id = :id"
    );
    $stmt->execute([
        ":name"        => $name,
        ":description" => $description,
        ":id"          => $roomId,
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Room updated successfully!",
        "room"    => [
            "id"          => $roomId,
            "name"        => $name,
            "description" => $description,
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while updating the room."]);
    exit;
}
