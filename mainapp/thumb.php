<?php
// thumb.php - サムネイル配信（別ファイルとして作成）
require_once 'config.php';

$file = $_GET['file'] ?? '';
$width = (int)($_GET['w'] ?? 300);
$height = (int)($_GET['h'] ?? 300);

if (empty($file)) {
    http_response_code(400);
    exit('File parameter required');
}

// セキュリティチェック
if (strpos($file, '..') !== false || strpos($file, '/') !== false) {
    http_response_code(403);
    exit('Invalid file path');
}

$originalPath = UPLOAD_DIR . $file;
if (!file_exists($originalPath)) {
    http_response_code(404);
    exit('File not found');
}

// キャッシュヘッダー設定
$lastModified = filemtime($originalPath);
$etag = md5($file . $width . $height . $lastModified);

header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
header('ETag: "' . $etag . '"');
header('Cache-Control: public, max-age=86400'); // 24時間キャッシュ

// クライアントキャッシュチェック
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === '"' . $etag . '"') {
    http_response_code(304);
    exit;
}

// サムネイル生成
$thumbnailPath = ImageProcessor::generateThumbnail($originalPath, $width, $height);

if ($thumbnailPath && file_exists($thumbnailPath)) {
    // 適切なContent-Typeを設定
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $thumbnailPath);
    finfo_close($finfo);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($thumbnailPath));
    
    readfile($thumbnailPath);
} else {
    http_response_code(500);
    exit('Thumbnail generation failed');
}
?>