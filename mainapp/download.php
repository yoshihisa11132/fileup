<?php
// download.php - ファイルダウンロードハンドラー（修正版）
require_once 'config.php';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();

try {
    if (empty($_GET['file'])) {
        throw new Exception('ファイル名が指定されていません');
    }

    $filename = $_GET['file'];
    
    // セキュリティチェック - パストラバーサル攻撃を防ぐ
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        throw new Exception('無効なファイル名です');
    }

    $filepath = UPLOAD_DIR . $filename;

    // ファイルの存在確認
    if (!file_exists($filepath) || !is_file($filepath)) {
        throw new Exception('ファイルが見つかりません');
    }

    // ファイルサイズチェック
    $fileSize = filesize($filepath);
    if ($fileSize === false || $fileSize == 0) {
        throw new Exception('ファイルを読み込めません');
    }

    // 元のファイル名を復元（改善版）
    $originalName = restoreOriginalFilename($filename);

    // MIMEタイプを取得
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filepath);
    finfo_close($finfo);

    if ($mimeType === false) {
        $mimeType = 'application/octet-stream';
    }

    // セキュリティヘッダーを設定
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    // キャッシュヘッダーを設定
    $lastModified = filemtime($filepath);
    $etag = md5($filename . $lastModified);
    
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
    header('ETag: "' . $etag . '"');

    // クライアントキャッシュのチェック
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === '"' . $etag . '"') {
        http_response_code(304);
        exit;
    }

    // ダウンロードヘッダーを設定
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . addslashes($originalName) . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: no-cache');

    // ログ記録
    logActivity('DOWNLOAD', $filename, $userAgent, $clientIP, '', "Original: $originalName, Size: " . formatFileSize($fileSize));

    // ファイルを出力（大きなファイルに対応）
    outputFile($filepath);
    exit;

} catch (Exception $e) {
    logActivity('DOWNLOAD_ERROR', $_GET['file'] ?? '', $userAgent, $clientIP, '', $e->getMessage());
    http_response_code(404);
    
    // エラーページを出力
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title>ファイルが見つかりません</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; margin-top: 50px; }
            .error { color: #d32f2f; margin: 20px 0; }
        </style>
    </head>
    <body>
        <h1>ファイルが見つかりません</h1>
        <div class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>
        <p><a href="/">トップページに戻る</a></p>
    </body>
    </html>';
}

// 元のファイル名を復元する関数（改善版）
function restoreOriginalFilename($storedFilename) {
    // パターン: YYYY-MM-DD_HH-MM-SS_UNIQUEID_ORIGINALNAME.EXT
    // 例: 2024-01-01_12-30-45_abc123def456_document.pdf
    
    $pattern = '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[a-f0-9]+_(.+)$/';
    
    if (preg_match($pattern, $storedFilename, $matches)) {
        return $matches[1]; // オリジナル名を返す
    }
    
    // パターンにマッチしない場合は、そのまま返す
    return $storedFilename;
}

// ファイルを効率的に出力する関数
function outputFile($filepath) {
    $fileSize = filesize($filepath);
    
    // 小さなファイル（1MB未満）は一度に出力
    if ($fileSize < 1024 * 1024) {
        readfile($filepath);
        return;
    }
    
    // 大きなファイルはチャンクに分けて出力
    $handle = fopen($filepath, 'rb');
    if ($handle === false) {
        throw new Exception('ファイルを開けません');
    }
    
    $chunkSize = 8192; // 8KB チャンク
    while (!feof($handle)) {
        $chunk = fread($handle, $chunkSize);
        if ($chunk === false) {
            fclose($handle);
            throw new Exception('ファイルの読み込みに失敗しました');
        }
        echo $chunk;
        
        // 出力バッファをフラッシュ
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    fclose($handle);
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