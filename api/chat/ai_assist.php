<?php
// ==============================================
// NexTalk — AI Assist (Gemini)
// Actions:
//   POST action=smart_replies   conversation_id=<id>
//        → { success, replies: [str, str, str] }
//   POST action=translate       message_id=<id>  target_lang=<optional, default English>
//        → { success, translated, source_lang, original }
// ==============================================
session_start();
header("Content-Type: application/json");
require_once "../db.php";

// ─── Auth ───
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not authorized"]);
    exit;
}

$user_id = (int)$_SESSION["user_id"];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

// ─── Config ───
// NOTE: Keep API keys in environment variables (server-side only).
$GEMINI_API_KEY = getenv('GEMINI_API_KEY') ?: '';
$GEMINI_MODEL   = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash'; // fast + cheap; good for chips/translation

if ($GEMINI_API_KEY === '') {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server misconfigured: GEMINI_API_KEY is not set"
    ]);
    exit;
}



$action = $_POST['action'] ?? '';

// ─────────────────────────────────────────────
// Helper: call Gemini generateContent
//   Returns the raw text from candidates[0].content.parts[0].text,
//   or throws via Exception on transport/HTTP/parse failure.
// ─────────────────────────────────────────────
function gemini_generate(string $prompt, float $temperature = 0.7): string {
    global $GEMINI_API_KEY, $GEMINI_MODEL;

    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . rawurlencode($GEMINI_MODEL)
         . ":generateContent?key=" . urlencode($GEMINI_API_KEY);

    $payload = [
        "contents" => [[
            "role"  => "user",
            "parts" => [["text" => $prompt]]
        ]],
        "generationConfig" => [
            "temperature"     => $temperature,
            "maxOutputTokens" => 256,
            "responseMimeType"=> "text/plain"
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        // Local dev (XAMPP) often lacks CA bundle; for production replace with proper bundle.
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new Exception("Network error contacting AI: " . $curlErr);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        // Surface a short slice of the upstream error to help debug
        $snippet = mb_substr($resp, 0, 240);
        throw new Exception("AI HTTP $httpCode: $snippet");
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new Exception("AI returned non-JSON response");
    }
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        // Possible safety block or empty completion
        $reason = $data['candidates'][0]['finishReason']
            ?? $data['promptFeedback']['blockReason']
            ?? 'no_text';
        throw new Exception("AI returned no text (reason: $reason)");
    }
    return trim($text);
}

// ─────────────────────────────────────────────
// Helper: confirm the user is an approved participant of $convId
// ─────────────────────────────────────────────
function require_participant(PDO $pdo, int $convId, int $userId): void {
    $s = $pdo->prepare(
        "SELECT status FROM participants WHERE conversation_id = ? AND user_id = ?"
    );
    $s->execute([$convId, $userId]);
    $row = $s->fetch();
    if (!$row || $row['status'] !== 'approved') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Not a participant in this conversation"]);
        exit;
    }
}

// ─────────────────────────────────────────────
//  ACTION: smart_replies
//  Pulls the most recent message in the conversation that was NOT sent by
//  the current user (so we suggest replies to *them*, not to ourselves),
//  asks Gemini for 3 short reply chips, and returns a JSON array.
// ─────────────────────────────────────────────
if ($action === 'smart_replies') {
    $conv_id = (int)($_POST['conversation_id'] ?? 0);
    if ($conv_id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "conversation_id is required"]);
        exit;
    }

    require_participant($pdo, $conv_id, $user_id);

    try {
        // Last incoming text from someone else (skip media-only and own messages)
        $stmt = $pdo->prepare("
            SELECT m.content, u.first_name
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ?
              AND m.sender_id != ?
              AND m.deleted_for_all = 0
              AND m.content IS NOT NULL
              AND m.content <> ''
            ORDER BY m.id DESC
            LIMIT 1
        ");
        $stmt->execute([$conv_id, $user_id]);
        $row = $stmt->fetch();

        if (!$row) {
            // No incoming text → return empty list (frontend just hides the bar)
            echo json_encode(["success" => true, "replies" => []]);
            exit;
        }

        $incoming = mb_substr($row['content'], 0, 600); // cap context
        $sender   = preg_replace('/[^\p{L}\p{N}\s\-\']/u', '', $row['first_name']);

        $prompt = <<<PROMPT
You are a helpful chat-reply assistant inside a messaging app.
The user just received this message from {$sender}:

\"\"\"{$incoming}\"\"\"

Suggest exactly three SHORT, natural reply options the user could send back.
Rules:
- Each reply must be on its own line.
- Maximum 8 words per reply.
- No numbering, no bullets, no quotes, no leading dashes.
- Match the language of the incoming message.
- Vary tone (e.g. one affirmative, one neutral/question, one polite decline) when reasonable.
Return only the three lines, nothing else.
PROMPT;

        $text  = gemini_generate($prompt, 0.6);
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $clean = [];
        foreach ($lines as $l) {
            $l = trim($l);
            // Strip leading bullets / numbering the model sometimes adds anyway
            $l = preg_replace('/^[\-\*\d\.\)]+\s*/', '', $l);
            $l = trim($l, " \"'`");
            if ($l !== '') $clean[] = mb_substr($l, 0, 80);
            if (count($clean) >= 3) break;
        }

        echo json_encode(["success" => true, "replies" => $clean]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────
//  ACTION: translate
//  Translates the content of a single message to target_lang (default English).
//  Participant check uses the message's conversation.
// ─────────────────────────────────────────────
if ($action === 'translate') {
    $message_id  = (int)($_POST['message_id'] ?? 0);
    $target_lang = trim($_POST['target_lang'] ?? 'English');
    if ($target_lang === '') $target_lang = 'English';
    // Keep the language label sane (letters, spaces, dashes only)
    if (!preg_match('/^[\p{L}\s\-]{2,40}$/u', $target_lang)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid target language"]);
        exit;
    }
    if ($message_id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "message_id is required"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT conversation_id, content, deleted_for_all FROM messages WHERE id = ?"
        );
        $stmt->execute([$message_id]);
        $msg = $stmt->fetch();
        if (!$msg) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Message not found"]);
            exit;
        }
        if ((int)$msg['deleted_for_all'] === 1) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Cannot translate a deleted message"]);
            exit;
        }
        if (!$msg['content']) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Message has no text to translate"]);
            exit;
        }

        require_participant($pdo, (int)$msg['conversation_id'], $user_id);

        $original = mb_substr($msg['content'], 0, 2000);

        $prompt = <<<PROMPT
Translate the following message into {$target_lang}.
- Return ONLY the translation — no commentary, no quotes, no prefix.
- Preserve emojis, punctuation, and line breaks.
- If the text is already in {$target_lang}, return it unchanged.

Message:
\"\"\"{$original}\"\"\"
PROMPT;

        $translated = gemini_generate($prompt, 0.2);
        // Strip wrapping quotes the model sometimes adds
        $translated = trim($translated, " \"'`");

        echo json_encode([
            "success"     => true,
            "message_id"  => $message_id,
            "translated"  => $translated,
            "target_lang" => $target_lang,
            "original"    => $original
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
    exit;
}

// ─── Fallthrough ───
http_response_code(400);
echo json_encode(["success" => false, "message" => "Unknown action"]);
