<?php
// api/upload.php - API用アップロードエンドポイント（削除キーオプション化版）
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, X-API-Token, X-Filename, X-Extension');

// 削除キー管理用のファイル
define('DELETE_KEYS_FILE', '../admin/delete_keys.json');
define('DEFAULT_DELETE_KEY', '104710477014'); // デフォルト削除キー（削除不可）

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
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('POST method required');
    }

    // User-Agentチェック
    if (empty($userAgent)) {
        http_response_code(400);
        throw new Exception('User-Agentがないみたいですけど？');
    }

    // APIキー検証
    if (empty($token)) {
        http_response_code(401);
        throw new Exception('APIキーがないみたいです、管理者にお問い合わせの上ヘッダーのX-API-Tokenにキーを指定してください。');
    }

    if (!ApiKeyManager::validateApiKey($token)) {
        http_response_code(401);
        throw new Exception('無効なAPIキーです。管理者に新しいAPIキーを発行してもらってください。');
    }

    // ファイル名とコンテンツタイプ取得
    $filename = $_SERVER['HTTP_X_FILENAME'] ?? 'uploaded_file';
    $extension = $_SERVER['HTTP_X_EXTENSION'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';

    // 削除キー取得（オプション）
    $deleteKey = $_SERVER['HTTP_X_DELETE_KEY'] ?? '';
    $deleteKey = trim($deleteKey);
    
    // 削除キーが空の場合はデフォルトキーを使用
    if (empty($deleteKey)) {
        $deleteKey = DEFAULT_DELETE_KEY;
        $isDeletable = false;
    } else {
        // 削除キーが数字のみかチェック
        if (!preg_match('/^[0-9]+$/', $deleteKey)) {
            http_response_code(400);
            throw new Exception('削除キーは数字のみで指定してください。');
        }
        $isDeletable = true;
    }

    // ファイル内容取得
    $fileContent = file_get_contents('php://input');
    if ($fileContent === false || empty($fileContent)) {
        http_response_code(400);
        throw new Exception('ファイルの内容が空です');
    }

    $fileSize = strlen($fileContent);
    if ($fileSize > MAX_FILE_SIZE) {
        http_response_code(413);
        throw new Exception('ファイルサイズが大きすぎます (' . formatFileSize($fileSize) . ' > ' . formatFileSize(MAX_FILE_SIZE) . ')');
    }

    // 安全なファイル名生成
    $originalName = $filename;
    if (!empty($extension)) {
        $originalName .= '.' . $extension;
    }
    
    $safeFilename = generateSecureFilename($originalName);
    $destination = UPLOAD_DIR . $safeFilename;

    // ファイル保存
    if (file_put_contents($destination, $fileContent, LOCK_EX) === false) {
        throw new Exception('ファイルの保存に失敗しました');
    }

    // ファイル検証
    if (!validateUploadedFile($destination)) {
        unlink($destination);
        throw new Exception('アップロードされたファイルが安全性の検証に失敗しました');
    }

    // 削除キーを保存
    storeDeleteKey($safeFilename, $deleteKey);

    // レスポンス生成
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = dirname($_SERVER['PHP_SELF']);
    $downloadLink = $scheme . '://' . $host . $basePath . '/../download.php?file=' . urlencode($safeFilename);

    $responseData = [
        'success' => true,
        'message' => 'ファイルをアップロードしました',
        'file' => [
            'original' => $originalName,
            'stored' => $safeFilename,
            'size' => $fileSize,
            'downloadLink' => $downloadLink,
            'formattedSize' => formatFileSize($fileSize),
            'deletable' => $isDeletable
        ]
    ];

    // 削除可能な場合のみ削除キーを返す
    if ($isDeletable) {
        $responseData['delete_key'] = $deleteKey;
        $responseData['delete_link'] = $scheme . '://' . $host . $basePath . '/../delete.php?file=' . urlencode($safeFilename) . '&key=' . $deleteKey;
    }

    // ログ記録
    $deleteKeyLog = $isDeletable ? $deleteKey : 'DEFAULT(non-deletable)';
    logActivity('API_UPLOAD', $safeFilename, $userAgent, $clientIP, $token, "Original: $originalName, Size: " . formatFileSize($fileSize) . ", DeleteKey: $deleteKeyLog");

    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    logActivity('API_UPLOAD_ERROR', '', $userAgent, $clientIP, $token, $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// ファイルサイズをフォーマットする関数
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>