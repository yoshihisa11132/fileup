<?php
// api/upload.php - セキュリティ強化版
require_once '../config.php';
setSecurityHeaders();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, Authorization, X-API-Token, X-Filename, X-Extension');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_POST['token'] ?? '');

try {
    // レート制限チェック
    if (!RateLimit::checkLimit('upload_' . $clientIP, 10, 300)) { // 5分間に10回まで
        http_response_code(429);
        throw new Exception('アップロード制限に達しました。しばらくお待ちください。');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('POST method required');
    }
    
    if (empty($userAgent)) {
        http_response_code(400);
        throw new Exception('User-Agent header required');
    }
    
    if (empty($token)) {
        http_response_code(401);
        throw new Exception('API token required');
    }
    
    if (!ApiKeyManager::validateApiKey($token)) {
        http_response_code(401);
        throw new Exception('Invalid API token');
    }
    
    // ファイル処理
    $customFilename = $_SERVER['HTTP_X_FILENAME'] ?? '';
    $customExtension = $_SERVER['HTTP_X_EXTENSION'] ?? '';
    
    $originalFilename = '';
    $fileData = '';
    $fileSize = 0;
    
    if (!empty($_FILES['file'])) {
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp directory',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
            ];
            throw new Exception($errorMessages[$file['error']] ?? 'Upload error: ' . $file['error']);
        }
        
        $originalFilename = $file['name'];
        $fileData = file_get_contents($file['tmp_name']);
        $fileSize = $file['size'];
        
        // ファイル検証
        if (!validateUploadedFile($file['tmp_name'])) {
            http_response_code(400);
            throw new Exception('File validation failed');
        }
        
    } else {
        $fileData = file_get_contents('php://input');
        
        if (empty($fileData)) {
            http_response_code(400);
            throw new Exception('No file data received');
        }
        
        $fileSize = strlen($fileData);
        $originalFilename = 'uploaded_file';
    }
    
    // ファイルサイズチェック
    if ($fileSize > MAX_FILE_SIZE) {
        http_response_code(413);
        throw new Exception('File size too large: ' . $fileSize . ' bytes');
    }
    
    // ファイル名決定
    $finalFilename = $originalFilename;
    if (!empty($customFilename)) {
        $finalFilename = $customFilename;
        if (!empty($customExtension)) {
            $finalFilename .= '.' . $customExtension;
        }
    }
    
    // セキュアなファイル名生成
    $safeFilename = generateSecureFilename($finalFilename);
    $destination = UPLOAD_DIR . $safeFilename;
    
    // ファイル保存
    if (file_put_contents($destination, $fileData, LOCK_EX) === false) {
        http_response_code(500);
        throw new Exception('Failed to save file');
    }
    
    // 最終検証
    if (!validateUploadedFile($destination)) {
        unlink($destination);
        http_response_code(400);
        throw new Exception('File failed post-upload validation');
    }
    
    logActivity('API_UPLOAD', $safeFilename, $userAgent, $clientIP, $token, 'Size: ' . $fileSize);
    
    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'data' => [
            'stored_filename' => $safeFilename,
            'original_filename' => $originalFilename,
            'file_size' => $fileSize,
            'upload_time' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    logActivity('API_UPLOAD_ERROR', '', $userAgent, $clientIP, $token, $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
