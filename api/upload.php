<?php
// api/upload.php - API用アップロードエンドポイント（改良版）
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, Authorization, X-API-Token, X-Filename, X-Extension');

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
            'php_input' => file_get_contents('php://input') ? 'present' : 'empty',
            'custom_headers' => [
                'X-Filename' => $_SERVER['HTTP_X_FILENAME'] ?? 'not set',
                'X-Extension' => $_SERVER['HTTP_X_EXTENSION'] ?? 'not set'
            ]
        ]);
        exit;
    }

    // リクエストメソッドチェック
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('POST以外でどうしろと');
    }

    // User-Agentチェック
    if (empty($userAgent)) {
        http_response_code(400);
        throw new Exception('User-Agentがないみたいですけど？');
    }

    // APIキー検証（新システムのみ）
    if (empty($token)) {
        http_response_code(401);
        throw new Exception('APIキーがないみたいです、管理者にお問い合わせの上ヘッダーのX-API-Tokenにキーを指定してください。');
    }

    if (!ApiKeyManager::validateApiKey($token)) {
        http_response_code(401);
        throw new Exception('無効なAPIキーです。管理者に新しいAPIキーを発行してもらってください。');
    }

    // カスタムヘッダーから情報取得
    $customFilename = $_SERVER['HTTP_X_FILENAME'] ?? '';
    $customExtension = $_SERVER['HTTP_X_EXTENSION'] ?? '';

    // ファイル処理：multipart/form-data または直接バイナリデータ
    $originalFilename = '';
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

        $originalFilename = $file['name'];
        $fileData = file_get_contents($file['tmp_name']);
        $fileSize = $file['size'];
        
    } else {
        // 直接バイナリデータとして送信された場合
        $fileData = file_get_contents('php://input');
        
        if (empty($fileData)) {
            http_response_code(400);
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            throw new Exception('$_FILESが空です。Content-Type: ' . $contentType);
        }
        
        $fileSize = strlen($fileData);
        $originalFilename = 'uploaded_file'; // デフォルトのファイル名
    }

    // ファイル名の決定（優先順位: X-Filename > multipart filename > デフォルト）
    $baseFilename = 'uploaded_file';
    if (!empty($customFilename)) {
        // X-Filenameヘッダーが指定されている場合（最優先）
        $baseFilename = $customFilename;
    } elseif (!empty($originalFilename) && $originalFilename !== 'uploaded_file') {
        // multipartで送信されたファイル名がある場合
        $baseFilename = pathinfo($originalFilename, PATHINFO_FILENAME);
    }

    // 拡張子の決定（優先順位: X-Extension > 元ファイル拡張子 > Content-Type推測）
    $extension = '';
    if (!empty($customExtension)) {
        // X-Extensionヘッダーが指定されている場合（最優先）
        $extension = $customExtension;
    } elseif (!empty($originalFilename)) {
        // 元のファイル名から拡張子を取得
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
    }
    
    // まだ拡張子が決まらない場合はContent-Typeから推測
    if (empty($extension)) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'text/css' => 'css',
            'text/javascript' => 'js',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'application/x-7z-compressed' => '7z',
            'application/json' => 'json',
            'application/xml' => 'xml',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/webm' => 'webm',
            'application/octet-stream' => 'bin'
        ];
        
        $extension = $extensionMap[$contentType] ?? 'bin';
    }

    // 危険なファイル拡張子チェック
    $lowerExtension = strtolower($extension);
    if (in_array($lowerExtension, FORBIDDEN_EXTENSIONS)) {
        http_response_code(400);
        throw new Exception('注意読め (ファイル: ' . $baseFilename . '.' . $extension . ')');
    }

    // ファイルサイズチェック
    if ($fileSize > MAX_FILE_SIZE) {
        http_response_code(413);
        throw new Exception('デッカ！！！ (' . $fileSize . ' bytes > ' . MAX_FILE_SIZE . ' bytes)');
    }

    // 安全なファイル名生成
    $safeBaseFilename = preg_replace('/[^a-zA-Z0-9._-]/', '', $baseFilename);
    $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
    $safeFilename = date('Y-m-d_H-i-s_') . uniqid() . '_' . $safeBaseFilename . '.' . $safeExtension;
    $destination = UPLOAD_DIR . $safeFilename;

    // ファイルを保存
    if (file_put_contents($destination, $fileData) !== false) {
        // ログ記録
        logActivity('API_UPLOAD', $safeFilename, $userAgent, $clientIP, $token);
        
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'data' => [
                'original_filename' => $originalFilename,
                'custom_filename' => $customFilename,
                'custom_extension' => $customExtension,
                'stored_filename' => $safeFilename,
                'base_filename' => $baseFilename,
                'extension' => $extension,
                'file_size' => $fileSize,
                'upload_time' => date('Y-m-d H:i:s'),
                'upload_method' => !empty($_FILES['file']) ? 'multipart' : 'binary',
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
            ]
        ]);
    } else {
        http_response_code(500);
        throw new Exception('Failed to save file to: ' . $destination);
    }

} catch (Exception $e) {
    logActivity('API_UPLOAD_ERROR', $e->getMessage(), $userAgent, $clientIP, $token);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>