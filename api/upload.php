<?php
// api/upload.php - API用アップロードエンドポイント（デバッグ版）
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, Authorization, X-API-Token');

// OPTIONSリクエスト（プリフライト）の処理
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_POST['token'] ?? '');

try {
    // デバッグ情報を追加
    if (isset($_GET['debug'])) {
        echo json_encode([
            'debug' => true,
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'files_received' => $_FILES,
            'post_received' => $_POST,
            'headers' => getallheaders(),
            'php_input' => file_get_contents('php://input') ? 'present' : 'empty'
        ]);
        exit;
    }

    // リクエストメソッドチェック
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('post以外でどうしろと');
    }

    // User-Agentチェック
    if (empty($userAgent)) {
        http_response_code(400);
        throw new Exception('User-Agentがないみたいですけど？');
    }

    // トークンチェック
    if ($token !== API_TOKEN) {
        http_response_code(401);
        throw new Exception('トークンがないみたいです、管理者にお問い合わせの上ヘッダーのX-API-Tokenにトークンを指定してください。現在のトークン: ' . $token);
    }

    // ファイル処理：multipart/form-data または直接バイナリデータ
    $filename = '';
    $fileData = '';
    $fileSize = 0;

    if (!empty($_FILES['file'])) {
        // 通常のmultipart/form-dataの場合
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
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
            throw new Exception($errorMsg);
        }

        $filename = $file['name'];
        $fileData = file_get_contents($file['tmp_name']);
        $fileSize = $file['size'];
        
    } else {
        // 直接バイナリデータとして送信された場合（ARCの場合）
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $fileData = file_get_contents('php://input');
        
        if (empty($fileData)) {
            http_response_code(400);
            throw new Exception('ファイルデータがありません');
        }
        
        $fileSize = strlen($fileData);
        
        // Content-Typeから拡張子を推測
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'application/json' => 'json'
        ];
        
        $extension = $extensionMap[$contentType] ?? 'bin';
        $filename = 'uploaded_file.' . $extension;
    }

    // ファイルサイズチェック
    if ($fileSize > MAX_FILE_SIZE) {
        http_response_code(413);
        throw new Exception('デッカ！！！ (' . $fileSize . ' bytes > ' . MAX_FILE_SIZE . ' bytes)');
    }

    // 安全なファイル名生成
    $safeFilename = date('Y-m-d_H-i-s_') . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    $destination = UPLOAD_DIR . $safeFilename;

    // ファイルを保存
    if (file_put_contents($destination, $fileData) !== false) {
        // ログ記録
        logActivity('API_UPLOAD', $safeFilename, $userAgent, $clientIP);
        
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'data' => [
                'original_filename' => $filename,
                'stored_filename' => $safeFilename,
                'file_size' => $fileSize,
                'upload_time' => date('Y-m-d H:i:s'),
                'upload_method' => !empty($_FILES['file']) ? 'multipart' : 'binary'
            ]
        ]);
    } else {
        http_response_code(500);
        throw new Exception('Failed to save file to: ' . $destination);
    }

} catch (Exception $e) {
    logActivity('API_UPLOAD_ERROR', $e->getMessage(), $userAgent, $clientIP);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>