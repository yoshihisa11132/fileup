<?php
// 6. 改善されたAPI upload.php（並行制御・キャッシュ対応）
require_once '../config.php';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';

try {
    // 並行アクセス制御
    if (!ConcurrencyControl::rateLimitCheck($clientIP, 10, 300)) { // 5分間に10回
        http_response_code(429);
        throw new Exception('Rate limit exceeded');
    }
    
    // 既存の検証処理...
    
    if (!ApiKeyManager::validateApiKey($token)) {
        http_response_code(401);
        throw new Exception('Invalid API token');
    }
    
    // ファイル保存時の排他制御
    $uploadLock = ConcurrencyControl::acquireLock('file_upload', 30);
    if (!$uploadLock) {
        http_response_code(503);
        throw new Exception('Server busy, please try again later');
    }
    
    try {
        // ファイル処理...
        $safeFilename = generateSecureFilename($finalFilename);
        $destination = UPLOAD_DIR . $safeFilename;
        
        if (file_put_contents($destination, $fileData, LOCK_EX) === false) {
            throw new Exception('Failed to save file');
        }
        
        // 画像の場合は自動でサムネイル生成
        if (ImageProcessor::isImage($destination)) {
            ImageProcessor::generateThumbnail($destination, 300, 300);
            
            // WebP変換も実行（容量削減）
            ImageProcessor::convertToWebP($destination, 85);
        }
        
        // ファイル一覧キャッシュを無効化
        FileCache::delete('file_list_' . md5(UPLOAD_DIR));
        
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'data' => [
                'stored_filename' => $safeFilename,
                'has_thumbnail' => ImageProcessor::isImage($destination),
                'thumbnail_url' => ImageProcessor::isImage($destination) ? 
                    'thumb.php?file=' . urlencode($safeFilename) . '&w=300&h=300' : null
            ]
        ]);
        
    } finally {
        ConcurrencyControl::releaseLock($uploadLock);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>