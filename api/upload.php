<?php
// upload.php - ファイルアップロード処理（削除キー機能付き）
require_once 'config.php';

setSecurityHeaders();

header('Content-Type: application/json');

// 削除キー管理用のファイル
define('DELETE_KEYS_FILE', __DIR__ . '/admin/delete_keys.json');

// 削除キー管理関数
function loadDeleteKeys() {
    if (!file_exists(DELETE_KEYS_FILE)) {
        return [];
    }
    
    $content = file_get_contents(DELETE_KEYS_FILE);
    $decoded = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Delete Keys JSON decode error: " . json_last_error_msg());
        return [];
    }
    
    return $decoded ?: [];
}

function saveDeleteKeys($keys) {
    $content = json_encode($keys, JSON_PRETTY_PRINT);
    return file_put_contents(DELETE_KEYS_FILE, $content, LOCK_EX) !== false;
}

function storeDeleteKey($filename, $deleteKey) {
    $keys = loadDeleteKeys();
    $keys[$filename] = [
        'delete_key' => $deleteKey,
        'created_at' => date('Y-m-d H:i:s'),
        'ip' => getClientIP()
    ];
    saveDeleteKeys($keys);
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('POST以外でどうしろと');
    }
    
    if (empty($userAgent)) {
        http_response_code(400);
        throw new Exception('User-Agentがないみたいですけど？');
    }
    
    // レート制限（1分間に10回まで）
    if (!RateLimit::checkLimit($clientIP, 10, 60)) {
        http_response_code(429);
        throw new Exception('アップロードの頻度が高すぎます。しばらく待ってからお試しください。');
    }
    
    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        http_response_code(400);
        throw new Exception('ファイルが選択されていません');
    }
    
    // 削除キーの取得と検証
    $deleteKey = $_POST['delete_key'] ?? '';
    $deleteKey = trim($deleteKey);
    
    // 削除キーが数字のみかチェック
    if (empty($deleteKey) || !preg_match('/^[0-9]+$/', $deleteKey)) {
        http_response_code(400);
        throw new Exception('削除キーは数字で入力してください。削除キーがないとファイルを削除できません。');
    }
    
    $uploadedFiles = [];
    $totalFiles = count($_FILES['files']['name']);
    
    for ($i = 0; $i < $totalFiles; $i++) {
        $file = [
            'name' => $_FILES['files']['name'][$i],
            'tmp_name' => $_FILES['files']['tmp_name'][$i],
            'size' => $_FILES['files']['size'][$i],
            'type' => $_FILES['files']['type'][$i],
            'error' => $_FILES['files']['error'][$i]
        ];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'ファイルサイズがphp.iniの設定を超えています',
                UPLOAD_ERR_FORM_SIZE => 'ファイルサイズがフォームの設定を超えています',
                UPLOAD_ERR_PARTIAL => 'ファイルが部分的にしかアップロードされていません',
                UPLOAD_ERR_NO_FILE => 'ファイルがアップロードされていません',
                UPLOAD_ERR_NO_TMP_DIR => '一時ディレクトリがありません',
                UPLOAD_ERR_CANT_WRITE => 'ディスクへの書き込みに失敗しました',
                UPLOAD_ERR_EXTENSION => 'PHPの拡張機能によりアップロードが停止されました'
            ];
            
            $errorMsg = $errorMessages[$file['error']] ?? 'Unknown error: ' . $file['error'];
            throw new Exception("ファイル '{$file['name']}': $errorMsg");
        }
        
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception("ファイル '{$file['name']}' のサイズが大きすぎます (" . number_format($file['size'] / 1024 / 1024, 2) . "MB)");
        }
        
        // セキュアなファイル名生成
        $safeFilename = generateSecureFilename($file['name']);
        $destination = UPLOAD_DIR . $safeFilename;
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // ファイル検証
            if (!validateUploadedFile($destination)) {
                unlink($destination);
                throw new Exception("ファイル '{$file['name']}' は安全性の検証に失敗しました");
            }
            
            // 削除キーを保存
            storeDeleteKey($safeFilename, $deleteKey);
            
            $fileInfo = [
                'original' => $file['name'],
                'stored' => $safeFilename,
                'size' => $file['size'],
                'formattedSize' => number_format($file['size'] / 1024, 1) . ' KB',
                'downloadLink' => 'download.php?file=' . urlencode($safeFilename),
                'dlLink' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/download.php?file=' . urlencode($safeFilename),
                'deleteLink' => 'delete.php?file=' . urlencode($safeFilename) . '&key=' . $deleteKey
            ];
            
            $uploadedFiles[] = $fileInfo;
            
            // ログ記録
            logActivity('UPLOAD', $safeFilename, $userAgent, $clientIP, '', "Original: {$file['name']}, DeleteKey: $deleteKey");
            
        } else {
            throw new Exception("ファイル '{$file['name']}' の移動に失敗しました");
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => count($uploadedFiles) . '個のファイルがアップロードされました',
        'files' => $uploadedFiles,
        'delete_key' => $deleteKey
    ]);

} catch (Exception $e) {
    logActivity('UPLOAD_ERROR', '', $userAgent, $clientIP, '', $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>