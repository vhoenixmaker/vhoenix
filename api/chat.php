<?php
/**
 * Chat endpoint — follow-up Q&A about analyzed tokens
 * 
 * POST /api/chat.php
 *   body: { token_ca, message }
 *   returns: { success, reply }
 * 
 * Context loading:
 * - Fetches most recent analysis for this token + user
 * - Loads last 10 chat messages between user and AI for this token
 * - Sends all as context to Claude so AI has full knowledge
 */

require_once __DIR__ . '/helpers.php';
setup_cors();

require_method('POST');
$user = require_auth();

$body = json_body();
$ca = sanitize_string($body['token_ca'] ?? '', 44);
$message = sanitize_string($body['message'] ?? '', 2000);

if (!validate_wallet_address($ca)) {
    json_error('Invalid contract address', 422);
}
if (!$message || strlen($message) < 2) {
    json_error('Message too short', 422);
}

// Rate limit: 20 chat messages / hour
rate_limit((string)$user['id'], 'chat', 20, 60);

// Load analysis context
$analysis = db_one(
    "SELECT * FROM analyses 
     WHERE user_id = ? AND token_ca = ? 
     ORDER BY created_at DESC LIMIT 1",
    [$user['id'], $ca]
);

if (!$analysis) {
    json_error('No analysis found for this token. Please run an analysis first.', 404);
}

// Load previous chat history (last 10 messages for context)
$history = db_all(
    "SELECT role, content, created_at FROM chat_messages 
     WHERE user_id = ? AND token_ca = ? 
     ORDER BY created_at DESC LIMIT 10",
    [$user['id'], $ca]
);
$history = array_reverse($history); // oldest first

// Build system context
$systemPrompt = build_system_prompt($analysis);

// Build message array for Claude
$messages = [];
foreach ($history as $h) {
    $messages[] = [
        'role' => $h['role'],
        'content' => $h['content'],
    ];
}
// Append current user message
$messages[] = [
    'role' => 'user',
    'content' => $message,
];

// Call Claude API
$reply = call_claude_chat($systemPrompt, $messages);
if (!$reply) {
    json_error('AI chat failed. Please try again.', 503);
}

// Save user message
db_insert(
    "INSERT INTO chat_messages (user_id, token_ca, role, content) VALUES (?, ?, 'user', ?)",
    [$user['id'], $ca, $message]
);
// Save AI reply
db_insert(
    "INSERT INTO chat_messages (user_id, token_ca, role, content) VALUES (?, ?, 'assistant', ?)",
    [$user['id'], $ca, $reply]
);

json_ok(['reply' => $reply]);

// =====================================================
// Build system prompt with analysis context
// =====================================================
function build_system_prompt($a) {
    $flags = [];
    if ($a['risk_flags_json']) {
        $parsedFlags = json_decode($a['risk_flags_json'], true);
        if (is_array($parsedFlags)) {
            foreach ($parsedFlags as $f) {
                $flags[] = "  - [{$f['type']}] {$f['title']}: {$f['description']}";
            }
        }
    }
    $flagsStr = $flags ? implode("\n", $flags) : '  (none)';

    $mc = $a['market_cap'] ?? 0;
    $liq = $a['liquidity'] ?? 0;
    $vol = $a['volume_24h'] ?? 0;
    $price = $a['price'] ?? 0;
    $change24h = $a['price_change_24h'] ?? 0;

    return <<<PROMPT
You are VHOENIX, an AI crypto analyst specialized in Solana memecoins. You've already analyzed this token for the user. Now they're asking follow-up questions.

TOKEN: \${$a['token_symbol']} ({$a['token_name']})
CA: {$a['token_ca']}

YOUR PREVIOUS ANALYSIS:
- Verdict: {$a['verdict']}
- Risk Level: {$a['risk_level']}
- Summary: {$a['summary']}

RISK FLAGS:
{$flagsStr}

MARKET DATA AT TIME OF ANALYSIS:
- Price: \${$price}
- Market Cap: \${$mc}
- Liquidity: \${$liq}
- 24h Volume: \${$vol}
- 24h Change: {$change24h}%

INSTRUCTIONS:
- Answer user questions directly based on the context above.
- Be concise (2-4 sentences typically). Match the vibe of Crypto Twitter — direct, no fluff.
- If user asks for "entry strategy" or "price targets", give general framework but remind them to DYOR.
- Never recommend trades you're not confident about based on the data you have.
- If they ask something you don't have data for (e.g., "what about holders?" when you don't have holder data), say so honestly.
- Use \$ prefix for tickers. Use tight formatting.
- Do NOT include disclaimers unless critically important — user already knows it's not financial advice.
PROMPT;
}

// =====================================================
// Call Claude with system prompt + message history
// =====================================================
function call_claude_chat($systemPrompt, $messages) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => CLAUDE_MODEL,
            'max_tokens' => 512,
            'system' => $systemPrompt,
            'messages' => $messages,
        ]),
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$raw) {
        error_log("Claude chat API failed: HTTP $httpCode");
        return null;
    }
    
    $data = json_decode($raw, true);
    return $data['content'][0]['text'] ?? null;
}
