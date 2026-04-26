<?php
// ==============================================
// NexTalk — Session Auth Check
// Protects pages/APIs — include or call via AJAX
// ==============================================
session_start();
header("Content-Type: application/json");
require_once "roles.php";

$requiredRole = nex_normalize_role($_GET["required_role"] ?? NEX_ROLE_MEMBER);

if (isset($_SESSION["logged_in"]) && $_SESSION["logged_in"] === true) {
    $role = nex_normalize_role($_SESSION["role"] ?? NEX_ROLE_MEMBER);

    if (!nex_has_min_role($role, $requiredRole)) {
        http_response_code(403);
        echo json_encode([
            "authenticated" => true,
            "authorized" => false,
            "message" => "You do not have permission to access this resource.",
            "required_role" => $requiredRole,
            "user_role" => $role,
        ]);
        exit;
    }

    echo json_encode([
        "authenticated" => true,
        "authorized" => true,
        "user" => [
            "id"         => $_SESSION["user_id"],
            "username"   => $_SESSION["username"],
            "first_name" => $_SESSION["first_name"],
            "last_name"  => $_SESSION["last_name"],
            "email"      => $_SESSION["email"],
            "role"       => $role,
            "role_label" => nex_role_label($role),
            "permissions" => nex_role_permissions($role),
        ]
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        "authenticated" => false,
        "message"       => "Not logged in. Access denied."
    ]);
}
