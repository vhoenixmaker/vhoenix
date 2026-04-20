<?php
/**
 * Admin endpoints — only accessible to ADMIN_EMAIL users
 * 
 * GET  /api/admin.php?action=users       — List all users
 * GET  /api/admin.php?action=stats       — Quick stats
 */

require_once __DIR__ . '/helpers.php';
setup_cors();

// =====================================================
// Admin authentication gate
// =====================================================
function require_admin() {
    $user = current_user();
    if (!$user) {
        json_error('Not authenticated', 401);
    }
    
    $adminEmails = defined('ADMIN_EMAILS') ? ADMIN_EMAILS : [];
    if (!is_array($adminEmails)) $adminEmails = [$adminEmails];
    
    if (!in_array(strtolower($user['email']), array_map('strtolower', $adminEmails), true)) {
        json_error('Forbidden — admin access only', 403);
    }
    
    return $user;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'users':   handle_users();   break;
    case 'stats':   handle_stats();   break;
    case 'me':      handle_me();      break;
    default: json_error('Unknown action', 404);
}

// =====================================================
// List all users with filters/pagination
// =====================================================
function handle_users() {
    require_method('GET');
    require_admin();
    
    $limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = sanitize_string($_GET['search'] ?? '', 100);
    $tier = sanitize_string($_GET['tier'] ?? '', 20);
    
    $where = [];
    $params = [];
    
    if ($search) {
        $where[] = "(email LIKE ? OR wallet_address LIKE ? OR display_name LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    
    if ($tier && in_array($tier, ['scout', 'apex'], true)) {
        $where[] = "tier = ?";
        $params[] = $tier;
    }
    
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Total count for pagination
    $totalRow = db_one("SELECT COUNT(*) AS c FROM users {$whereSql}", $params);
    $total = (int)$totalRow['c'];
    
    // Fetch users
    $sql = "SELECT id, email, wallet_address, display_name, tier, 
                   analyses_today, analyses_reset_at,
                   email_verified_at, last_login_at, last_login_ip, 
                   created_at, updated_at
            FROM users {$whereSql}
            ORDER BY created_at DESC 
            LIMIT {$limit} OFFSET {$offset}";
    $users = db_all($sql, $params);
    
    // Attach analyses count per user
    foreach ($users as &$u) {
        $cnt = db_one("SELECT COUNT(*) AS c FROM analyses WHERE user_id = ?", [$u['id']]);
        $u['total_analyses'] = (int)($cnt['c'] ?? 0);
        $u['id'] = (int)$u['id'];
        $u['analyses_today'] = (int)$u['analyses_today'];
        // Never leak password hash
    }
    unset($u);
    
    json_ok([
        'users' => $users,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

// =====================================================
// Quick stats for admin overview
// =====================================================
function handle_stats() {
    require_method('GET');
    require_admin();
    
    $totalUsers = db_one("SELECT COUNT(*) AS c FROM users")['c'] ?? 0;
    $verifiedUsers = db_one("SELECT COUNT(*) AS c FROM users WHERE email_verified_at IS NOT NULL")['c'] ?? 0;
    $walletUsers = db_one("SELECT COUNT(*) AS c FROM users WHERE wallet_address IS NOT NULL")['c'] ?? 0;
    $apexUsers = db_one("SELECT COUNT(*) AS c FROM users WHERE tier = 'apex'")['c'] ?? 0;
    $today = db_one("SELECT COUNT(*) AS c FROM users WHERE DATE(created_at) = CURDATE()")['c'] ?? 0;
    $totalAnalyses = db_one("SELECT COUNT(*) AS c FROM analyses")['c'] ?? 0;
    $analysesToday = db_one("SELECT COUNT(*) AS c FROM analyses WHERE DATE(created_at) = CURDATE()")['c'] ?? 0;
    
    json_ok([
        'stats' => [
            'total_users'       => (int)$totalUsers,
            'verified_users'    => (int)$verifiedUsers,
            'wallet_users'      => (int)$walletUsers,
            'apex_users'        => (int)$apexUsers,
            'new_today'         => (int)$today,
            'total_analyses'    => (int)$totalAnalyses,
            'analyses_today'    => (int)$analysesToday,
        ],
    ]);
}

// =====================================================
// Check if current user is admin (for UI gate)
// =====================================================
function handle_me() {
    require_method('GET');
    
    $user = current_user();
    if (!$user) {
        json_error('Not authenticated', 401);
    }
    
    $adminEmails = defined('ADMIN_EMAILS') ? ADMIN_EMAILS : [];
    if (!is_array($adminEmails)) $adminEmails = [$adminEmails];
    $isAdmin = in_array(strtolower($user['email']), array_map('strtolower', $adminEmails), true);
    
    json_ok([
        'is_admin' => $isAdmin,
        'email' => $user['email'],
    ]);
}
