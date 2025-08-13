<?php
// download.php - ファイルダウンロードハンドラー
require_once 'config.php';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();

try {
    if (empty($_GET['file'])) {
        throw new Exception('ファイル名が指定されていません');
    }

    $filename = $_GET['file'];
    $filepath = UPLOAD_DIR . $filename;

    // ファイルの存在確認
    if (!file_exists($filepath)) {
        throw new Exception('ファイルが見つかりません');
    }

    // 元のファイル名を取得（アップロード時のタイムスタンプ等を除去）
    $originalName = preg_replace('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-f0-9]+_/', '', $filename);

    // ファイル情報を取得
    $fileSize = filesize($filepath);
    $mimeType = mime_content_type($filepath) ?: 'application/octet-stream';

    // ダウンロードヘッダーを設定
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $originalName . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    // ログ記録
    logActivity('DOWNLOAD', $filename, $userAgent, $clientIP);

    // ファイルを出力
    readfile($filepath);
    exit;

} catch (Exception $e) {
    logActivity('DOWNLOAD_ERROR', $_GET['file'] ?? '', $userAgent, $clientIP);
    http_response_code(404);
    echo 'Error: ' . $e->getMessage();
}
?>