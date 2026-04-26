<?php
// ==============================================
// NexTalk — Get All Rooms API (GET)
// Requires: any authenticated user
// ==============================================
session_start();
header("Content-Type: application/json");

require_once "db.php";

// ── Auth Check ──
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not logged in."]);
    exit;
}

// ── Fetch All Rooms (JOIN with users to get creator username) ──
try {
    $stmt = $pdo->query(
        "SELECT r.id, r.name, r.description, r.created_by, r.created_at,
                u.username AS creator_username
         FROM rooms r
         LEFT JOIN users u ON r.created_by = u.id
         ORDER BY r.created_at DESC"
    );
    $rooms = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "rooms"   => $rooms,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while fetching rooms."]);
    exit;
}
