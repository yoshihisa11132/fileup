<?php
// upload.php - Web UI用アップロードハンドラー
require_once 'config.php';
header('Content-Type: application/json');
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST method required');
    }
    if (empty($_FILES['files'])) {
        throw new Exception('ファイルが選択されていません');
    }
    $uploadedFiles = [];
    $files = $_FILES['files'];
    // 複数ファイル対応
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        $filename = $files['name'][$i];
        $tmpName = $files['tmp_name'][$i];
        $fileSize = $files['size'][$i];
        // ファイルサイズチェック
        if ($fileSize > MAX_FILE_SIZE) {
            throw new Exception("ファイルサイズが大きすぎます: $filename");
        }
        // 拡張子チェックは廃止
        
        // 安全なファイル名生成
        $safeFilename = date('Y-m-d_H-i-s_') . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        $destination = UPLOAD_DIR . $safeFilename;
        if (move_uploaded_file($tmpName, $destination)) {
            // ダウンロードリンクを生成
            $downloadLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . 
                           '://' . $_SERVER['HTTP_HOST'] . 
                           dirname($_SERVER['PHP_SELF']) . 
                           '/download.php?file=' . urlencode($safeFilename);
            $dlLink = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . 
                           '://' . $_SERVER['HTTP_HOST'] . 
                           dirname($_SERVER['PHP_SELF']) . 
                           '/redi.php?file=' . urlencode($safeFilename);
            $uploadedFiles[] = [
                'original' => $filename,
                'stored' => $safeFilename,
                'size' => $fileSize,
                'downloadLink' => $downloadLink,
                'formattedSize' => formatFileSize($fileSize)
            ];
            // ログ記録
            logActivity('WEB_UPLOAD', $safeFilename, $userAgent, $clientIP);
        }
    }
    if (empty($uploadedFiles)) {
        throw new Exception('アップロードに失敗しました');
    }
    echo json_encode([
        'success' => true,
        'message' => count($uploadedFiles) . '個のファイルをアップロードしました',
        'files' => $uploadedFiles
    ]);
} catch (Exception $e) {
    logActivity('WEB_UPLOAD_ERROR', '', $userAgent, $clientIP);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
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
