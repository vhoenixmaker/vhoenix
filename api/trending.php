<?php
/**
 * Trending endpoint — returns trending Solana tokens
 * 
 * GET /api/trending.php
 *   Returns top 6 Solana tokens with highest activity
 * 
 * Strategy:
 * - Fetch DexScreener "boosted tokens" (they're promoted/trending on DexScreener)
 * - Filter to Solana chain
 * - Sort by 24h volume
 * - Cache for 5 minutes to avoid rate-limiting DexScreener
 * 
 * Note: This is a "nice-to-have" endpoint. Dashboard falls back to dummy
 * data gracefully if this fails, so it's not critical for MVP.
 */

require_once __DIR__ . '/helpers.php';
setup_cors();

require_method('GET');
// Allow unauthenticated access (trending is public-ish)
// But we still rate-limit by IP to prevent abuse
rate_limit(get_client_ip(), 'trending', 30, 15);

$cacheFile = sys_get_temp_dir() . '/vhoenix_trending_cache.json';
$cacheTtl = 300; // 5 minutes

// Try cache first
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = @file_get_contents($cacheFile);
    $data = json_decode($cached, true);
    if (is_array($data) && !empty($data['tokens'])) {
        json_ok(['tokens' => $data['tokens'], 'cached' => true]);
    }
}

// Fetch boosted tokens from DexScreener
$tokens = fetch_trending_solana();

if (empty($tokens)) {
    // Return empty — frontend will fall back to dummy data
    json_ok(['tokens' => []]);
}

// Cache result
@file_put_contents($cacheFile, json_encode(['tokens' => $tokens]));

json_ok(['tokens' => $tokens]);

// =====================================================
// Fetch trending tokens
// =====================================================
function fetch_trending_solana() {
    // Boosted tokens endpoint (no auth required)
    $url = 'https://api.dexscreener.com/token-boosts/top/v1';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: VHOENIX/1.0'],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$raw) {
        return [];
    }
    
    $boosted = json_decode($raw, true);
    if (!is_array($boosted)) return [];
    
    // Filter to Solana + collect CAs
    $solanaCAs = [];
    foreach ($boosted as $b) {
        if (($b['chainId'] ?? '') !== 'solana') continue;
        if (!empty($b['tokenAddress'])) {
            $solanaCAs[] = $b['tokenAddress'];
        }
        if (count($solanaCAs) >= 30) break;
    }
    
    if (empty($solanaCAs)) return [];
    
    // Fetch pair data for these tokens (max 30 per call)
    $casStr = implode(',', array_slice($solanaCAs, 0, 30));
    $pairsUrl = "https://api.dexscreener.com/latest/dex/tokens/{$casStr}";
    
    $ch = curl_init($pairsUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: VHOENIX/1.0'],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw2 = curl_exec($ch);
    $httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode2 !== 200 || !$raw2) return [];
    
    $pairsData = json_decode($raw2, true);
    if (!isset($pairsData['pairs']) || !is_array($pairsData['pairs'])) return [];
    
    // Group by baseToken address (pick best pair per token)
    $byCA = [];
    foreach ($pairsData['pairs'] as $pair) {
        if (($pair['chainId'] ?? '') !== 'solana') continue;
        $ca = $pair['baseToken']['address'] ?? null;
        if (!$ca) continue;
        $liq = $pair['liquidity']['usd'] ?? 0;
        if (!isset($byCA[$ca]) || $liq > ($byCA[$ca]['liquidity']['usd'] ?? 0)) {
            $byCA[$ca] = $pair;
        }
    }
    
    // Sort by 24h volume desc, take top 6
    $pairs = array_values($byCA);
    usort($pairs, function($a, $b) {
        return ($b['volume']['h24'] ?? 0) <=> ($a['volume']['h24'] ?? 0);
    });
    $pairs = array_slice($pairs, 0, 6);
    
    // Emojis for visual flair
    $emojis = ['🔥', '⚡', '🚀', '💎', '🌊', '👑'];
    
    $out = [];
    foreach ($pairs as $idx => $p) {
        $out[] = [
            'ca'         => $p['baseToken']['address'],
            'symbol'     => $p['baseToken']['symbol'] ?? '?',
            'name'       => $p['baseToken']['name'] ?? '',
            'mc'         => (float)($p['marketCap'] ?? $p['fdv'] ?? 0),
            'liquidity'  => (float)($p['liquidity']['usd'] ?? 0),
            'volume_24h' => (float)($p['volume']['h24'] ?? 0),
            'change24h'  => (float)($p['priceChange']['h24'] ?? 0),
            'emoji'      => $emojis[$idx] ?? '🪙',
        ];
    }
    
    return $out;
}
