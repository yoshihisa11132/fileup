<?php
// api/download.php - API用ダウンロードエンドポイント
require_once '../config.php';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['token'] ?? '');
try {
    // User-Agentチェック
    if (empty($userAgent)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'what' => 'useragent', 'message' => 'User-Agentがないみたいですけど？']);
        exit;
    }
    // トークンチェック
    if ($token !== API_TOKEN) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'what' => 'token', 'message' => 'トークンがないみたいです、管理者にお問い合わせの上ヘッダーのX-API-Tokenにトークンを指定してください。']);
        exit;
    }
    
    // ファイル名をヘッダーから取得（フォールバックとしてGETパラメータも対応）
    $filename = $_SERVER['HTTP_X_FILENAME'] ?? ($_GET['file'] ?? '');
    
    if (empty($filename)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'what' => 'filenamenai', 'message' => 'ファイル名が必要です、ヘッダーでX-Filenameに指定するかfileパラメーターでも可能ですよ！']);
        exit;
    }
    
    $filePath = UPLOAD_DIR . $filename;
    if (!file_exists($filePath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'what' => "filenai", 'message' => '指定されたファイルは存在しません。']);
        exit;
    }
    // ログ記録
    logActivity('API_DOWNLOAD', $filename, $userAgent, $clientIP);
    // ファイル送信
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    readfile($filePath);
} catch (Exception $e) {
    logActivity('API_DOWNLOAD_ERROR', $filename ?? '', $userAgent, $clientIP);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>