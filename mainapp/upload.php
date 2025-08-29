<?php
// upload.php - Web UI用アップロードハンドラー（削除キーオプション化版）
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
setSecurityHeaders();

// 削除キー管理用のファイル
define('DELETE_KEYS_FILE', __DIR__ . '/admin/delete_keys.json');
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST method required');
    }

    if (empty($_FILES['files'])) {
        throw new Exception('ファイルが選択されていません');
    }

    // 削除キーの取得（オプション）
    $deleteKey = $_POST['delete_key'] ?? '';
    $deleteKey = trim($deleteKey);
    
    // 削除キーが空の場合はデフォルトキーを使用
    if (empty($deleteKey)) {
        $deleteKey = DEFAULT_DELETE_KEY;
        $isDeletable = false;
    } else {
        // 削除キーが数字のみかチェック
        if (!preg_match('/^[0-9]+$/', $deleteKey)) {
            http_response_code(400);
            throw new Exception('削除キーは数字で入力してください。');
        }
        $isDeletable = true;
    }

    $uploadedFiles = [];
    $files = $_FILES['files'];

    // 単一ファイルと複数ファイルの両方に対応
    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'tmp_name' => [$files['tmp_name']],
            'size' => [$files['size']],
            'error' => [$files['error']],
            'type' => [$files['type']]
        ];
    }

    // 複数ファイル対応
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errorMessage = getUploadErrorMessage($files['error'][$i]);
            logActivity('WEB_UPLOAD_ERROR', $files['name'][$i], $userAgent, $clientIP, '', $errorMessage);
            continue;
        }

        $originalFilename = $files['name'][$i];
        $tmpName = $files['tmp_name'][$i];
        $fileSize = $files['size'][$i];

        // ファイルサイズチェック
        if ($fileSize > MAX_FILE_SIZE) {
            throw new Exception("ファイルサイズが大きすぎます: $originalFilename (" . formatFileSize($fileSize) . " > " . formatFileSize(MAX_FILE_SIZE) . ")");
        }

        if ($fileSize == 0) {
            throw new Exception("空のファイルはアップロードできません: $originalFilename");
        }

        // ファイル名の安全性チェック
        if (empty($originalFilename) || strlen($originalFilename) > 255) {
            throw new Exception("無効なファイル名です: $originalFilename");
        }

        // 安全なファイル名生成
        $safeFilename = generateSecureFilename($originalFilename);
        $destination = UPLOAD_DIR . $safeFilename;

        // ファイルの移動
        if (move_uploaded_file($tmpName, $destination)) {
            // ファイル検証
            if (!validateUploadedFile($destination)) {
                unlink($destination); // 無効なファイルは削除
                throw new Exception("アップロードされたファイルが無効です: $originalFilename");
            }

            // 削除キーを保存
            storeDeleteKey($safeFilename, $deleteKey);

            // ダウンロードリンクを生成
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['PHP_SELF']);
            
            $downloadLink = $scheme . '://' . $host . $path . '/download.php?file=' . urlencode($safeFilename);
            $dlLink = $scheme . '://' . $host . $path . '/redi.php?file=' . urlencode($safeFilename);

            $fileInfo = [
                'original' => $originalFilename,
                'stored' => $safeFilename,
                'size' => $fileSize,
                'downloadLink' => $downloadLink,
                'dlLink' => $dlLink,
                'formattedSize' => formatFileSize($fileSize),
                'deletable' => $isDeletable
            ];
            
            $uploadedFiles[] = $fileInfo;

            // ログ記録
            $deleteKeyLog = $isDeletable ? $deleteKey : 'DEFAULT(non-deletable)';
            logActivity('WEB_UPLOAD', $safeFilename, $userAgent, $clientIP, '', "Original: $originalFilename, Size: " . formatFileSize($fileSize) . ", DeleteKey: $deleteKeyLog");
        } else {
            throw new Exception("ファイルの保存に失敗しました: $originalFilename");
        }
    }

    if (empty($uploadedFiles)) {
        throw new Exception('アップロードに失敗しました');
    }

    $responseData = [
        'success' => true,
        'message' => count($uploadedFiles) . '個のファイルをアップロードしました',
        'files' => $uploadedFiles
    ];
    
    // 削除可能な場合のみ削除キーを返す
    if ($isDeletable) {
        $responseData['delete_key'] = $deleteKey;
    }

    echo json_encode($responseData, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    logActivity('WEB_UPLOAD_ERROR', '', $userAgent, $clientIP, '', $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// アップロードエラーメッセージを取得
function getUploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'ファイルサイズが大きすぎます';
        case UPLOAD_ERR_PARTIAL:
            return 'ファイルのアップロードが中断されました';
        case UPLOAD_ERR_NO_FILE:
            return 'ファイルが選択されていません';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'サーバーの一時ディレクトリが見つかりません';
        case UPLOAD_ERR_CANT_WRITE:
            return 'ファイルの書き込みに失敗しました';
        case UPLOAD_ERR_EXTENSION:
            return '拡張機能によってアップロードがブロックされました';
        default:
            return '不明なエラー';
    }
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