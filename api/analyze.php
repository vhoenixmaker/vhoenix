<?php
/**
 * Analyze endpoint — core intelligence engine
 * 
 * POST /api/analyze.php?action=run              — Run analysis (paste CA → full report)
 * GET  /api/analyze.php?action=recent           — List recent analyses by current user
 * GET  /api/analyze.php?action=watchlist        — List watchlist items
 * POST /api/analyze.php?action=watchlist_toggle — Add/remove from watchlist
 * 
 * Flow:
 * 1. Validate CA
 * 2. Check rate limit (5/day free, 100/day apex)
 * 3. Check cache (reuse result if same CA analyzed within 15 min)
 * 4. Fetch data from DexScreener
 * 5. Build prompt → Claude API
 * 6. Parse Claude response → structured data
 * 7. Save to DB
 * 8. Return to frontend
 */

require_once __DIR__ . '/helpers.php';
setup_cors();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'run':
        handle_run();
        break;
    case 'recent':
        handle_recent();
        break;
    case 'watchlist':
        handle_watchlist();
        break;
    case 'watchlist_toggle':
        handle_watchlist_toggle();
        break;
    default:
        json_error('Unknown analyze action', 404);
}

// =====================================================
// Run full analysis
// =====================================================
function handle_run() {
    require_method('POST');
    $user = require_auth();

    $body = json_body();
    $ca = sanitize_string($body['token_ca'] ?? '', 44);

    if (!validate_wallet_address($ca)) {
        json_error('Invalid Solana contract address', 422);
    }

    // Reset daily counter if new day
    reset_daily_counter_if_needed($user);

    // Rate limit check
    $limit = $user['tier'] === 'apex' ? RATE_LIMIT_ANALYSIS_APEX : RATE_LIMIT_ANALYSIS_FREE;
    if ((int)$user['analyses_today'] >= $limit) {
        json_error(
            "Daily analysis limit reached ({$limit}/day). Upgrade to Apex for more.",
            429,
            ['limit_reached' => true]
        );
    }

    // Check cache: same CA, same user, within last 15 minutes
    $cached = db_one(
        "SELECT * FROM analyses 
         WHERE user_id = ? AND token_ca = ? 
         AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         ORDER BY created_at DESC LIMIT 1",
        [$user['id'], $ca]
    );
    if ($cached) {
        $inWatchlist = !!db_one(
            "SELECT id FROM watchlists WHERE user_id = ? AND token_ca = ?",
            [$user['id'], $ca]
        );
        json_ok([
            'analysis' => format_analysis_for_response($cached, $inWatchlist),
            'analyses_today' => (int)$user['analyses_today'],
            'cached' => true,
        ]);
    }

    // Fetch market data from DexScreener
    $marketData = fetch_dexscreener_data($ca);
    if (!$marketData) {
        json_error('Token not found on any Solana DEX. Make sure this is a valid Solana CA with active liquidity.', 404);
    }

    // Run Claude AI analysis
    $aiResult = run_claude_analysis($ca, $marketData);
    if (!$aiResult) {
        json_error('AI analysis failed. Please try again in a moment.', 503);
    }

    // Save to DB
    $analysisId = db_insert(
        "INSERT INTO analyses (
            user_id, token_ca, token_symbol, token_name, image_url,
            market_cap, liquidity, volume_24h, price,
            price_change_1h, price_change_24h, txns_24h, liquidity_locked,
            verdict, risk_level, summary, risk_flags_json, raw_data_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $user['id'], $ca,
            $marketData['symbol'], $marketData['name'], $marketData['image_url'],
            $marketData['market_cap'], $marketData['liquidity'], $marketData['volume_24h'], $marketData['price'],
            $marketData['price_change_1h'], $marketData['price_change_24h'], $marketData['txns_24h'], $marketData['liquidity_locked'] ? 1 : 0,
            $aiResult['verdict'], $aiResult['risk_level'], $aiResult['summary'], json_encode($aiResult['risk_flags']),
            json_encode($marketData),
        ]
    );

    // Increment daily counter
    db_exec("UPDATE users SET analyses_today = analyses_today + 1 WHERE id = ?", [$user['id']]);
    $analysesToday = (int)$user['analyses_today'] + 1;

    // Fetch fresh row
    $row = db_one("SELECT * FROM analyses WHERE id = ?", [$analysisId]);
    $inWatchlist = !!db_one(
        "SELECT id FROM watchlists WHERE user_id = ? AND token_ca = ?",
        [$user['id'], $ca]
    );

    json_ok([
        'analysis' => format_analysis_for_response($row, $inWatchlist),
        'analyses_today' => $analysesToday,
    ]);
}

// =====================================================
// Reset daily counter if date changed
// =====================================================
function reset_daily_counter_if_needed(&$user) {
    $today = date('Y-m-d');
    if ($user['analyses_reset_at'] !== $today) {
        db_exec(
            "UPDATE users SET analyses_today = 0, analyses_reset_at = ? WHERE id = ?",
            [$today, $user['id']]
        );
        $user['analyses_today'] = 0;
        $user['analyses_reset_at'] = $today;
    }
}

// =====================================================
// Fetch market data from DexScreener
// =====================================================
function fetch_dexscreener_data($ca) {
    $url = "https://api.dexscreener.com/latest/dex/tokens/" . urlencode($ca);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: VHOENIX/1.0'],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    if ($curlErr || $httpCode !== 200 || !$raw) {
        error_log("DexScreener fetch failed: HTTP $httpCode, err: $curlErr");
        return null;
    }
    
    $data = json_decode($raw, true);
    if (!isset($data['pairs']) || !is_array($data['pairs']) || count($data['pairs']) === 0) {
        return null;
    }
    
    // Find best pair (highest liquidity)
    $bestPair = null;
    $bestLiq = 0;
    foreach ($data['pairs'] as $pair) {
        if (($pair['chainId'] ?? '') !== 'solana') continue;
        $liq = $pair['liquidity']['usd'] ?? 0;
        if ($liq > $bestLiq) {
            $bestLiq = $liq;
            $bestPair = $pair;
        }
    }
    
    if (!$bestPair) return null;
    
    return [
        'symbol'           => $bestPair['baseToken']['symbol'] ?? 'UNKNOWN',
        'name'             => $bestPair['baseToken']['name'] ?? 'Unknown Token',
        'image_url'        => $bestPair['info']['imageUrl'] ?? null,
        'ca'               => $ca,
        'pair_address'     => $bestPair['pairAddress'] ?? null,
        'dex'              => $bestPair['dexId'] ?? 'unknown',
        'price'            => (float)($bestPair['priceUsd'] ?? 0),
        'price_native'     => (float)($bestPair['priceNative'] ?? 0),
        'market_cap'       => (float)($bestPair['marketCap'] ?? $bestPair['fdv'] ?? 0),
        'fdv'              => (float)($bestPair['fdv'] ?? 0),
        'liquidity'        => (float)($bestPair['liquidity']['usd'] ?? 0),
        'liquidity_base'   => (float)($bestPair['liquidity']['base'] ?? 0),
        'volume_24h'       => (float)($bestPair['volume']['h24'] ?? 0),
        'volume_1h'        => (float)($bestPair['volume']['h1'] ?? 0),
        'price_change_1h'  => (float)($bestPair['priceChange']['h1'] ?? 0),
        'price_change_6h'  => (float)($bestPair['priceChange']['h6'] ?? 0),
        'price_change_24h' => (float)($bestPair['priceChange']['h24'] ?? 0),
        'txns_24h'         => (int)(($bestPair['txns']['h24']['buys'] ?? 0) + ($bestPair['txns']['h24']['sells'] ?? 0)),
        'buys_24h'         => (int)($bestPair['txns']['h24']['buys'] ?? 0),
        'sells_24h'        => (int)($bestPair['txns']['h24']['sells'] ?? 0),
        'pair_created_at'  => $bestPair['pairCreatedAt'] ?? null,
        'liquidity_locked' => false, // DexScreener doesn't expose this; we'll heuristic-check
        'socials'          => $bestPair['info']['socials'] ?? [],
        'websites'         => $bestPair['info']['websites'] ?? [],
    ];
}

// =====================================================
// Run Claude AI analysis
// =====================================================
function run_claude_analysis($ca, $marketData) {
    $symbol = $marketData['symbol'];
    $name = $marketData['name'];
    $price = $marketData['price'];
    $mc = $marketData['market_cap'];
    $liq = $marketData['liquidity'];
    $vol24 = $marketData['volume_24h'];
    $change1h = $marketData['price_change_1h'];
    $change24h = $marketData['price_change_24h'];
    $buys24 = $marketData['buys_24h'];
    $sells24 = $marketData['sells_24h'];
    $txns24 = $marketData['txns_24h'];
    
    $ageStr = 'unknown age';
    if ($marketData['pair_created_at']) {
        $ageSec = time() - ($marketData['pair_created_at'] / 1000);
        if ($ageSec < 3600) $ageStr = round($ageSec / 60) . ' minutes old';
        elseif ($ageSec < 86400) $ageStr = round($ageSec / 3600, 1) . ' hours old';
        else $ageStr = round($ageSec / 86400, 1) . ' days old';
    }
    
    $buySellRatio = $sells24 > 0 ? round($buys24 / $sells24, 2) : ($buys24 > 0 ? 999 : 0);
    $volToMcRatio = $mc > 0 ? round($vol24 / $mc * 100, 1) : 0;
    
    $prompt = <<<PROMPT
You are VHOENIX, an AI crypto analyst specializing in Solana memecoins. Analyze this token and provide a structured verdict.

TOKEN DATA:
- Symbol: \${$symbol}
- Name: {$name}
- Contract: {$ca}
- DEX: {$marketData['dex']}
- Age: {$ageStr}

MARKET DATA:
- Price: \${$price}
- Market Cap: \${$mc}
- Liquidity: \${$liq}
- 24h Volume: \${$vol24}
- 1h Change: {$change1h}%
- 24h Change: {$change24h}%

TRADING ACTIVITY (24h):
- Total txns: {$txns24}
- Buys: {$buys24}
- Sells: {$sells24}
- Buy/Sell ratio: {$buySellRatio}
- Volume/MC ratio: {$volToMcRatio}%

TASK: Provide a trading analysis in STRICT JSON format (no markdown, no commentary, just raw JSON):

{
  "verdict": "ape" | "wait" | "avoid",
  "risk_level": "low" | "medium" | "high",
  "summary": "2-3 sentences plain-English analysis covering price action, liquidity, trading activity, and key concern or opportunity. Be direct, no hedging.",
  "risk_flags": [
    {"type": "good" | "warning" | "bad", "title": "Short title", "description": "One sentence explanation"}
  ]
}

VERDICT GUIDELINES:
- "ape" = Strong buy signal. Liquidity adequate (>\$30k), healthy buy/sell ratio (>1.2), positive momentum, no red flags.
- "wait" = Mixed signals. Decent fundamentals but momentum unclear, OR strong momentum but liquidity thin. Watch for confirmation.
- "avoid" = Clear red flags. Low liquidity (<\$10k), heavy selling, suspicious patterns, high rug risk.

RISK FLAGS (pick 3-5 most relevant):
- Liquidity health (low liquidity = bad, locked = good)
- Buy/sell pressure
- Volume legitimacy (high vol + low MC = wash trading warning)
- Price volatility
- Age vs traction

Respond with ONLY the JSON object. No \`\`\`json wrappers, no intro text.
PROMPT;

    $response = call_claude_api($prompt);
    if (!$response) return null;
    
    // Try to parse JSON from response
    $response = trim($response);
    // Strip markdown code fences if present
    $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
    $response = preg_replace('/\s*```\s*$/', '', $response);
    
    $parsed = json_decode($response, true);
    if (!is_array($parsed) || !isset($parsed['verdict'])) {
        error_log("Claude returned invalid JSON: " . substr($response, 0, 500));
        // Fallback heuristic
        return build_fallback_analysis($marketData);
    }
    
    return [
        'verdict'     => in_array($parsed['verdict'] ?? '', ['ape', 'wait', 'avoid']) ? $parsed['verdict'] : 'wait',
        'risk_level'  => in_array($parsed['risk_level'] ?? '', ['low', 'medium', 'high']) ? $parsed['risk_level'] : 'medium',
        'summary'     => substr($parsed['summary'] ?? 'Analysis unavailable', 0, 1000),
        'risk_flags'  => is_array($parsed['risk_flags'] ?? null) ? array_slice($parsed['risk_flags'], 0, 6) : [],
    ];
}

// =====================================================
// Fallback heuristic analysis (if Claude fails)
// =====================================================
function build_fallback_analysis($m) {
    $liq = $m['liquidity'];
    $vol = $m['volume_24h'];
    $change24h = $m['price_change_24h'];
    $buySellRatio = $m['sells_24h'] > 0 ? $m['buys_24h'] / $m['sells_24h'] : 1;
    
    $verdict = 'wait';
    $risk = 'medium';
    $flags = [];
    
    if ($liq < 10000) {
        $verdict = 'avoid';
        $risk = 'high';
        $flags[] = ['type' => 'bad', 'title' => 'Very Low Liquidity', 'description' => 'Liquidity under $10k — extremely risky'];
    } elseif ($liq > 100000) {
        $flags[] = ['type' => 'good', 'title' => 'Healthy Liquidity', 'description' => 'Liquidity over $100k'];
    }
    
    if ($buySellRatio > 1.5 && $change24h > 20) {
        $verdict = 'ape';
        $flags[] = ['type' => 'good', 'title' => 'Strong Buy Pressure', 'description' => 'Buys outpacing sells significantly'];
    } elseif ($buySellRatio < 0.7) {
        $verdict = 'avoid';
        $flags[] = ['type' => 'bad', 'title' => 'Heavy Sell Pressure', 'description' => 'More sells than buys — bearish'];
    }
    
    return [
        'verdict' => $verdict,
        'risk_level' => $risk,
        'summary' => 'Heuristic analysis (AI unavailable): ' . 
            ($verdict === 'ape' ? 'Strong buy signal based on volume and buy pressure.' :
             ($verdict === 'avoid' ? 'Red flags detected — avoid or exit.' :
              'Mixed signals — wait for clearer confirmation before entering.')),
        'risk_flags' => $flags,
    ];
}

// =====================================================
// Call Claude API
// =====================================================
function call_claude_api($prompt) {
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
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]),
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    if ($curlErr || $httpCode !== 200 || !$raw) {
        error_log("Claude API failed: HTTP $httpCode, err: $curlErr, body: " . substr($raw ?? '', 0, 500));
        return null;
    }
    
    $data = json_decode($raw, true);
    if (!isset($data['content'][0]['text'])) {
        error_log("Claude API unexpected response: " . substr($raw, 0, 500));
        return null;
    }
    
    return $data['content'][0]['text'];
}

// =====================================================
// Format analysis DB row → frontend response
// =====================================================
function format_analysis_for_response($row, $inWatchlist = false) {
    return [
        'id'              => (int)$row['id'],
        'ca'              => $row['token_ca'],
        'symbol'          => $row['token_symbol'],
        'name'            => $row['token_name'],
        'image_url'       => $row['image_url'],
        'verdict'         => $row['verdict'],
        'risk_level'      => $row['risk_level'],
        'summary'         => $row['summary'],
        'risk_flags'      => $row['risk_flags_json'] ? json_decode($row['risk_flags_json'], true) : [],
        'market_cap'      => $row['market_cap'] !== null ? (float)$row['market_cap'] : null,
        'liquidity'       => $row['liquidity'] !== null ? (float)$row['liquidity'] : null,
        'volume_24h'      => $row['volume_24h'] !== null ? (float)$row['volume_24h'] : null,
        'price'           => $row['price'] !== null ? (float)$row['price'] : null,
        'price_change_1h' => $row['price_change_1h'] !== null ? (float)$row['price_change_1h'] : null,
        'price_change_24h'=> $row['price_change_24h'] !== null ? (float)$row['price_change_24h'] : null,
        'txns_24h'        => $row['txns_24h'] !== null ? (int)$row['txns_24h'] : null,
        'liquidity_locked'=> (bool)$row['liquidity_locked'],
        'created_at'      => $row['created_at'],
        'in_watchlist'    => (bool)$inWatchlist,
    ];
}

// =====================================================
// Recent analyses list
// =====================================================
function handle_recent() {
    require_method('GET');
    $user = require_auth();
    
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
    
    $rows = db_all(
        "SELECT * FROM analyses WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit",
        [$user['id']]
    );
    
    $items = array_map(function($row) {
        return [
            'id'           => (int)$row['id'],
            'token_ca'     => $row['token_ca'],
            'token_symbol' => $row['token_symbol'],
            'verdict'      => $row['verdict'],
            'risk_level'   => $row['risk_level'],
            'market_cap'   => $row['market_cap'] !== null ? (float)$row['market_cap'] : null,
            'created_at'   => $row['created_at'],
        ];
    }, $rows);
    
    json_ok(['items' => $items]);
}

// =====================================================
// Watchlist list
// =====================================================
function handle_watchlist() {
    require_method('GET');
    $user = require_auth();
    
    // Join with most recent analysis for each watched token
    $rows = db_all(
        "SELECT w.token_ca, w.token_symbol, w.added_at,
                (SELECT a.verdict FROM analyses a WHERE a.user_id = w.user_id AND a.token_ca = w.token_ca ORDER BY a.created_at DESC LIMIT 1) AS verdict,
                (SELECT a.risk_level FROM analyses a WHERE a.user_id = w.user_id AND a.token_ca = w.token_ca ORDER BY a.created_at DESC LIMIT 1) AS risk_level,
                (SELECT a.market_cap FROM analyses a WHERE a.user_id = w.user_id AND a.token_ca = w.token_ca ORDER BY a.created_at DESC LIMIT 1) AS market_cap,
                (SELECT a.created_at FROM analyses a WHERE a.user_id = w.user_id AND a.token_ca = w.token_ca ORDER BY a.created_at DESC LIMIT 1) AS created_at
         FROM watchlists w 
         WHERE w.user_id = ? 
         ORDER BY w.added_at DESC",
        [$user['id']]
    );
    
    json_ok(['items' => $rows]);
}

// =====================================================
// Toggle watchlist
// =====================================================
function handle_watchlist_toggle() {
    require_method('POST');
    $user = require_auth();
    
    $body = json_body();
    $ca = sanitize_string($body['token_ca'] ?? '', 44);
    
    if (!validate_wallet_address($ca)) {
        json_error('Invalid contract address', 422);
    }
    
    $existing = db_one(
        "SELECT id FROM watchlists WHERE user_id = ? AND token_ca = ?",
        [$user['id'], $ca]
    );
    
    if ($existing) {
        db_exec("DELETE FROM watchlists WHERE id = ?", [$existing['id']]);
        json_ok(['in_watchlist' => false, 'message' => 'Removed from watchlist']);
    } else {
        // Get symbol from most recent analysis if available
        $recent = db_one(
            "SELECT token_symbol FROM analyses WHERE user_id = ? AND token_ca = ? ORDER BY created_at DESC LIMIT 1",
            [$user['id'], $ca]
        );
        db_insert(
            "INSERT INTO watchlists (user_id, token_ca, token_symbol) VALUES (?, ?, ?)",
            [$user['id'], $ca, $recent['token_symbol'] ?? null]
        );
        json_ok(['in_watchlist' => true, 'message' => 'Added to watchlist']);
    }
}
