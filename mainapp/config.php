<?php
// config.php - セキュリティ強化版設定ファイル（修正版）
define('UPLOAD_DIR', __DIR__ . '/file/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'zip', 'rar', '7z', 'mp3', 'mp4', 'avi', 'mov', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']);
define('FORBIDDEN_EXTENSIONS', ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'exe', 'sh', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar', 'asp', 'aspx', 'jsp', 'py', 'pl', 'cgi', 'htaccess', 'htpasswd']);
define('API_KEYS_FILE', __DIR__ . '/admin/api_keys.json');
define('ADMIN_SESSION_TIMEOUT', 3600); // 1時間
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15分

// セキュリティヘッダーの設定
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'');
}

// ディレクトリ作成（セキュア）
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    // .htaccess を自動生成してPHPファイルの実行を禁止
    file_put_contents(UPLOAD_DIR . '.htaccess', "Options -ExecCGI\nAddHandler cgi-script .php .phtml .php3 .php4 .php5 .phps\nOrder Deny,Allow\nDeny from all\n<Files ~ \"\\.(jpg|jpeg|png|gif|pdf|txt|zip|rar|7z|mp3|mp4|avi|mov|doc|docx|xls|xlsx|ppt|pptx)$\">\n    Order Allow,Deny\n    Allow from all\n</Files>");
}

if (!file_exists(__DIR__ . '/admin')) {
    mkdir(__DIR__ . '/admin', 0755, true);
}

// CSRFトークン生成・検証
class CSRFProtection {
    public static function generateToken() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }
    
    public static function validateToken($token) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// レート制限クラス
class RateLimit {
    private static $lockFile = __DIR__ . '/admin/rate_limit.json';
    
    public static function checkLimit($identifier, $maxAttempts, $timeWindow) {
        $data = self::loadData();
        $currentTime = time();
        $key = hash('sha256', $identifier);
        
        if (!isset($data[$key])) {
            $data[$key] = ['count' => 0, 'last_attempt' => $currentTime];
        }
        
        $entry = $data[$key];
        
        // 時間窓をリセット
        if ($currentTime - $entry['last_attempt'] > $timeWindow) {
            $data[$key] = ['count' => 1, 'last_attempt' => $currentTime];
            self::saveData($data);
            return true;
        }
        
        if ($entry['count'] >= $maxAttempts) {
            return false;
        }
        
        $data[$key]['count']++;
        $data[$key]['last_attempt'] = $currentTime;
        self::saveData($data);
        return true;
    }
    
    private static function loadData() {
        if (file_exists(self::$lockFile)) {
            $content = file_get_contents(self::$lockFile);
            return json_decode($content, true) ?: [];
        }
        return [];
    }
    
    private static function saveData($data) {
        file_put_contents(self::$lockFile, json_encode($data), LOCK_EX);
    }
}

// APIキー管理クラス（セキュリティ強化）
class ApiKeyManager {
    private static $keysFile = API_KEYS_FILE;
    
    public static function generateApiKey() {
        return 'ak_' . bin2hex(random_bytes(32));
    }
    
    public static function loadApiKeys() {
        if (!file_exists(self::$keysFile)) {
            return [];
        }
        
        $content = file_get_contents(self::$keysFile);
        $decoded = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("API Keys JSON decode error: " . json_last_error_msg());
            return [];
        }
        
        return $decoded ?: [];
    }
    
    public static function saveApiKeys($keys) {
        $content = json_encode($keys, JSON_PRETTY_PRINT);
        return file_put_contents(self::$keysFile, $content, LOCK_EX) !== false;
    }
    
    public static function createApiKey($name, $description = '', $expiresAt = null) {
        // 入力検証
        if (empty($name) || strlen($name) > 100) {
            return false;
        }
        
        if (strlen($description) > 500) {
            return false;
        }
        
        $keys = self::loadApiKeys();
        $apiKey = self::generateApiKey();
        
        $keys[$apiKey] = [
            'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars($description, ENT_QUOTES, 'UTF-8'),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'last_used' => null,
            'usage_count' => 0,
            'active' => true,
            'created_ip' => getClientIP()
        ];
        
        if (self::saveApiKeys($keys)) {
            return $apiKey;
        }
        
        return false;
    }
    
    public static function validateApiKey($apiKey) {
        // レート制限チェック
        if (!RateLimit::checkLimit('api_' . $apiKey, 1000, 3600)) { // 1時間に1000回まで
            return false;
        }
        
        $keys = self::loadApiKeys();
        
        if (!isset($keys[$apiKey])) {
            return false;
        }
        
        $keyData = $keys[$apiKey];
        
        if (!$keyData['active']) {
            return false;
        }
        
        if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
            return false;
        }
        
        // 使用統計更新
        $keys[$apiKey]['last_used'] = date('Y-m-d H:i:s');
        $keys[$apiKey]['usage_count']++;
        $keys[$apiKey]['last_ip'] = getClientIP();
        self::saveApiKeys($keys);
        
        return true;
    }
    
    public static function revokeApiKey($apiKey) {
        $keys = self::loadApiKeys();
        
        if (isset($keys[$apiKey])) {
            $keys[$apiKey]['active'] = false;
            $keys[$apiKey]['revoked_at'] = date('Y-m-d H:i:s');
            return self::saveApiKeys($keys);
        }
        
        return false;
    }
    
    public static function deleteApiKey($apiKey) {
        $keys = self::loadApiKeys();
        
        if (isset($keys[$apiKey])) {
            unset($keys[$apiKey]);
            return self::saveApiKeys($keys);
        }
        
        return false;
    }
    
    public static function getAllApiKeys() {
        return self::loadApiKeys();
    }
}

// セキュアなファイル名生成（修正版）
function generateSecureFilename($originalName) {
    // 元のファイル名から情報を抽出
    $pathInfo = pathinfo($originalName);
    $extension = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : '';
    $baseName = isset($pathInfo['filename']) ? $pathInfo['filename'] : 'file';
    
    // 空のベース名の処理
    if (empty(trim($baseName))) {
        $baseName = 'file';
    }
    
    // 拡張子が空の場合の処理
    if (empty($extension)) {
        // MIMEタイプから拡張子を推測（オプション）
        $extension = 'bin'; // デフォルト
    }
    
    // 危険な拡張子チェック
    if (in_array($extension, FORBIDDEN_EXTENSIONS)) {
        throw new Exception('この拡張子のファイルはアップロードできません: ' . $extension);
    }
    
    // 許可された拡張子チェック（設定されている場合）
    if (!empty(ALLOWED_EXTENSIONS) && !in_array($extension, ALLOWED_EXTENSIONS)) {
        throw new Exception('許可されていない拡張子です: ' . $extension);
    }
    
    // 安全なファイル名生成
    $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $baseName);
    $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
    
    // ファイル名が空の場合のフォールバック
    if (empty($safeBaseName) || $safeBaseName === '_') {
        $safeBaseName = 'uploaded_file';
    }
    
    // 長すぎるファイル名を短縮
    if (strlen($safeBaseName) > 50) {
        $safeBaseName = substr($safeBaseName, 0, 50);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $uniqueId = bin2hex(random_bytes(8));
    
    // 最終的なファイル名を生成（拡張子が空でない場合のみ追加）
    $finalFilename = $timestamp . '_' . $uniqueId . '_' . $safeBaseName;
    if (!empty($safeExtension)) {
        $finalFilename .= '.' . $safeExtension;
    }
    
    return $finalFilename;
}

// IPアドレス取得（より安全）
function getClientIP() {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = explode(',', $ip)[0];
            }
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// セキュアログ記録
function logActivity($action, $filename = '', $userAgent = '', $ip = '', $apiKey = '', $additional = '') {
    $logFile = __DIR__ . '/admin/access.log';
    $timestamp = date('Y-m-d H:i:s');
    
    // ログローテーション（10MBを超えたら）
    if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
        rename($logFile, $logFile . '.' . date('Y-m-d-H-i-s'));
    }
    
    $apiKeyShort = $apiKey ? ' | API: ' . substr(hash('sha256', $apiKey), 0, 8) : '';
    $safeUA = htmlspecialchars(substr($userAgent, 0, 200), ENT_QUOTES, 'UTF-8');
    $safeFilename = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
    $safeAdditional = htmlspecialchars($additional, ENT_QUOTES, 'UTF-8');
    
    $logEntry = "[$timestamp] IP: $ip | UA: $safeUA | Action: $action | File: $safeFilename$apiKeyShort | $safeAdditional" . PHP_EOL;
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// ファイル検証（改善版）
function validateUploadedFile($filePath) {
    // ファイルが存在するかチェック
    if (!file_exists($filePath)) {
        return false;
    }
    
    // ファイルサイズチェック
    $fileSize = filesize($filePath);
    if ($fileSize === false || $fileSize > MAX_FILE_SIZE || $fileSize == 0) {
        return false;
    }
    
    // MIMEタイプチェック
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    
    if ($mimeType === false) {
        return false;
    }
    
    $allowedMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
        'application/pdf', 'text/plain',
        'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
        'audio/mpeg', 'audio/mp4', 'video/mp4', 'video/quicktime', 'video/x-msvideo',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/octet-stream' // バイナリファイル用
    ];
    
    if (!in_array($mimeType, $allowedMimeTypes)) {
        error_log("Invalid MIME type: $mimeType for file: $filePath");
        return false;
    }
    
    // ファイル内容の簡易チェック（PHPタグの検出）
    $fileContents = file_get_contents($filePath, false, null, 0, 1024); // 最初の1KBのみ
    if ($fileContents !== false && (strpos($fileContents, '<?php') !== false || strpos($fileContents, '<?=') !== false)) {
        error_log("PHP code detected in uploaded file: $filePath");
        return false;
    }
    
    return true;
}

// セッション管理
class SessionManager {
    public static function start() {
        if (session_status() == PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
            ini_set('session.cookie_samesite', 'Strict');
            session_start();
        }
    }
    
    public static function regenerateId() {
        session_regenerate_id(true);
    }
    
    public static function destroy() {
        session_unset();
        session_destroy();
    }
    
    public static function isExpired() {
        return isset($_SESSION['last_activity']) && 
               (time() - $_SESSION['last_activity']) > ADMIN_SESSION_TIMEOUT;
    }
    
    public static function updateActivity() {
        $_SESSION['last_activity'] = time();
    }
}
?>