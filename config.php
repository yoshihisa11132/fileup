<?php
// config.php - 設定ファイル
define('API_TOKEN', '18f1b113c070a20891d956d2710195771c8c0a7dc8a3340b5357f2058fb0518a7cd6b5a8783dea70a3373e2bdfb6a61aaff149f663e01b029e7e0e1449472c8f'); // ←ここを変更してください
define('UPLOAD_DIR', __DIR__ . '/file/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 50MB
define('ALLOWED_EXTENSIONS', []);
define('FORBIDDEN_EXTENSIONS', ['php', 'exe', 'sh', 'bat', 'cmd', 'php', 'cgi']);
// ディレクトリが存在しない場合は作成
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
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

function logActivity($action, $filename = '', $userAgent = '', $ip = '') {
    $logFile = __DIR__ . '/admin/access.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] IP: $ip | UA: $userAgent | Action: $action | File: $filename" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

function isValidExtension($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_EXTENSIONS);
}
?>