<?php
// ==============================================
// NexTalk — Login API (AJAX + Sessions)
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

// Grab form data (sent via FormData from fetch)
$emailOrUsername = trim($_POST["email_or_username"] ?? "");
$password        = $_POST["password"] ?? "";

// ── Validation ──
if (empty($emailOrUsername) || empty($password)) {
    echo json_encode(["success" => false, "message" => "Please fill in all fields."]);
    exit;
}

// ── Find user by email OR username ──
try {
    $stmt = $pdo->prepare(
        "SELECT * FROM users WHERE email = :email OR username = :username LIMIT 1"
    );
    $stmt->execute([
        ":email" => $emailOrUsername,
        ":username" => $emailOrUsername,
    ]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error during sign in."]);
    exit;
}

if (!$user) {
    echo json_encode(["success" => false, "message" => "No account found with that email or username."]);
    exit;
}

// ── Verify password ──
if (!password_verify($password, $user["password"])) {
    echo json_encode(["success" => false, "message" => "Incorrect password. Please try again."]);
    exit;
}

$role = nex_normalize_role($user["role"] ?? NEX_ROLE_MEMBER);
$permissions = nex_role_permissions($role);

// ── Create session ──
$_SESSION["user_id"]    = $user["id"];
$_SESSION["username"]   = $user["username"] ?? "";
$_SESSION["first_name"] = $user["first_name"] ?? "";
$_SESSION["last_name"]  = $user["last_name"] ?? "";
$_SESSION["email"]      = $user["email"] ?? "";
$_SESSION["role"]       = $role;
$_SESSION["logged_in"]  = true;

echo json_encode([
    "success" => true,
    "message" => "Login successful! Redirecting...",
    "user"    => [
        "id"         => $user["id"],
        "username"   => $user["username"] ?? "",
        "first_name" => $user["first_name"] ?? "",
        "last_name"  => $user["last_name"] ?? "",
        "email"      => $user["email"] ?? "",
        "role"       => $role,
        "role_label" => nex_role_label($role),
        "permissions" => $permissions,
    ]
]);
