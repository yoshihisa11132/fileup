<?php
// config.php - 設定ファイル（APIキー管理機能付き）
define('UPLOAD_DIR', __DIR__ . '/file/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('ALLOWED_EXTENSIONS', []);
define('FORBIDDEN_EXTENSIONS', ['php', 'exe', 'sh', 'bat', 'cmd', 'php', 'cgi']);
define('API_KEYS_FILE', __DIR__ . '/admin/api_keys.json');

// ディレクトリが存在しない場合は作成
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

if (!file_exists(__DIR__ . '/admin')) {
    mkdir(__DIR__ . '/admin', 0755, true);
}

// APIキー管理クラス
class ApiKeyManager {
    private static $keysFile = API_KEYS_FILE;
    
    public static function generateApiKey() {
        return hash('sha256', random_bytes(32) . time() . uniqid());
    }
    
    public static function loadApiKeys() {
        if (!file_exists(self::$keysFile)) {
            return [];
        }
        
        $content = file_get_contents(self::$keysFile);
        return json_decode($content, true) ?: [];
    }
    
    public static function saveApiKeys($keys) {
        $content = json_encode($keys, JSON_PRETTY_PRINT);
        return file_put_contents(self::$keysFile, $content, LOCK_EX) !== false;
    }
    
    public static function createApiKey($name, $description = '', $expiresAt = null) {
        $keys = self::loadApiKeys();
        $apiKey = self::generateApiKey();
        
        $keys[$apiKey] = [
            'name' => $name,
            'description' => $description,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'last_used' => null,
            'usage_count' => 0,
            'active' => true
        ];
        
        if (self::saveApiKeys($keys)) {
            return $apiKey;
        }
        
        return false;
    }
    
    public static function validateApiKey($apiKey) {
        $keys = self::loadApiKeys();
        
        if (!isset($keys[$apiKey])) {
            return false;
        }
        
        $keyData = $keys[$apiKey];
        
        // アクティブかチェック
        if (!$keyData['active']) {
            return false;
        }
        
        // 有効期限チェック
        if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
            return false;
        }
        
        // 使用回数と最終使用日時を更新
        $keys[$apiKey]['last_used'] = date('Y-m-d H:i:s');
        $keys[$apiKey]['usage_count']++;
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
    
    public static function getApiKeyInfo($apiKey) {
        $keys = self::loadApiKeys();
        return $keys[$apiKey] ?? null;
    }
    
    public static function getAllApiKeys() {
        return self::loadApiKeys();
    }
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

function logActivity($action, $filename = '', $userAgent = '', $ip = '', $apiKey = '') {
    $logFile = __DIR__ . '/admin/access.log';
    $timestamp = date('Y-m-d H:i:s');
    $apiKeyShort = $apiKey ? ' | API: ' . substr($apiKey, 0, 8) . '...' : '';
    $logEntry = "[$timestamp] IP: $ip | UA: $userAgent | Action: $action | File: $filename$apiKeyShort" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

function isValidExtension($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_EXTENSIONS);
}

// レガシートークンは完全廃止されました
?>