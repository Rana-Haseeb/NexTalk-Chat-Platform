<?php
// ==============================================
// NexTalk — Register API (AJAX + Sessions)
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

// Grab form data
$firstName = trim($_POST["first_name"] ?? "");
$lastName  = trim($_POST["last_name"]  ?? "");
$username  = trim($_POST["username"]   ?? "");
$email     = trim($_POST["email"]      ?? "");
$password  = $_POST["password"]        ?? "";
$confirm   = $_POST["confirm"]         ?? "";

// ── Validation ──
if (empty($firstName) || empty($lastName) || empty($username) || empty($email) || empty($password)) {
    echo json_encode(["success" => false, "message" => "All fields are required."]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Please enter a valid email address."]);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(["success" => false, "message" => "Password must be at least 8 characters."]);
    exit;
}

if ($password !== $confirm) {
    echo json_encode(["success" => false, "message" => "Passwords do not match."]);
    exit;
}

// ── Check if username or email already exists ──
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
$stmt->execute([":u" => $username, ":e" => $email]);

if ($stmt->fetch()) {
    echo json_encode(["success" => false, "message" => "Username or email is already registered."]);
    exit;
}

// ── Insert new user ──
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);
$role = NEX_ROLE_MEMBER;

$stmt = $pdo->prepare(
    "INSERT INTO users (first_name, last_name, username, email, password, role) VALUES (:fn, :ln, :u, :e, :p, :r)"
);
$stmt->execute([
    ":fn" => $firstName,
    ":ln" => $lastName,
    ":u"  => $username,
    ":e"  => $email,
    ":p"  => $hashedPassword,
    ":r"  => $role,
]);

$newUserId = $pdo->lastInsertId();

// ── Auto-login: Create session ──
$_SESSION["user_id"]    = $newUserId;
$_SESSION["username"]   = $username;
$_SESSION["first_name"] = $firstName;
$_SESSION["last_name"]  = $lastName;
$_SESSION["email"]      = $email;
$_SESSION["role"]       = $role;
$_SESSION["logged_in"]  = true;

echo json_encode([
    "success" => true,
    "message" => "Account created successfully! Redirecting...",
    "user"    => [
        "id"         => $newUserId,
        "username"   => $username,
        "first_name" => $firstName,
        "last_name"  => $lastName,
        "email"      => $email,
        "role"       => $role,
        "role_label" => nex_role_label($role),
        "permissions" => nex_role_permissions($role),
    ]
]);
