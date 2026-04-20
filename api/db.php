<?php
/**
 * Database connection helper (PDO with prepared statements)
 * 
 * Usage: $db = db();
 *        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
 *        $stmt->execute([$userId]);
 *        $user = $stmt->fetch();
 */

// Load config (fallback to example if config.php doesn't exist yet)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    http_response_code(500);
    die(json_encode(['error' => 'config.php not found. Copy config.example.php to config.php and fill in credentials.']));
}

function db() {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode([
                'error' => 'Database connection failed',
                'detail' => APP_ENV === 'development' ? $e->getMessage() : null
            ]));
        }
    }
    
    return $pdo;
}

/**
 * Helper: Run query and return all rows
 */
function db_all($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Helper: Run query and return first row (or null)
 */
function db_one($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Helper: Insert and return last insert ID
 */
function db_insert($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return db()->lastInsertId();
}

/**
 * Helper: Execute query (update/delete) and return affected rows
 */
function db_exec($sql, $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
