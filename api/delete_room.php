<?php
// ==============================================
// NexTalk — Delete Room API (POST)
// Requires: 'admin' role
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
if (!nex_has_min_role($userRole, NEX_ROLE_ADMIN)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Only Admins can delete rooms."]);
    exit;
}

// ── Input Validation ──
$roomId = intval($_POST["id"] ?? 0);

if ($roomId <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid room ID."]);
    exit;
}

// ── Check room exists ──
try {
    $check = $pdo->prepare("SELECT id, name FROM rooms WHERE id = :id LIMIT 1");
    $check->execute([":id" => $roomId]);
    $room = $check->fetch();

    if (!$room) {
        echo json_encode(["success" => false, "message" => "Room not found."]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while verifying room."]);
    exit;
}

// ── Delete Room ──
try {
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = :id");
    $stmt->execute([":id" => $roomId]);

    echo json_encode([
        "success" => true,
        "message" => "Room '{$room['name']}' deleted successfully.",
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while deleting the room."]);
    exit;
}
