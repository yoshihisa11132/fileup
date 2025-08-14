<?php
// 7. キャッシュクリーンアップ用のcron.php（定期実行用）
// cron.php として別ファイルで作成
require_once 'config.php';

// 古いキャッシュファイルを削除（24時間以上前）
function cleanupOldCache() {
    $cacheDir = __DIR__ . '/cache/';
    if (!is_dir($cacheDir)) return 0;
    
    $deleted = 0;
    $files = glob($cacheDir . '*.cache');
    $cutoff = time() - 86400; // 24時間前
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }
    
    return $deleted;
}

// 古いサムネイルを削除
function cleanupOldThumbnails() {
    $thumbDir = __DIR__ . '/thumbs/';
    if (!is_dir($thumbDir)) return 0;
    
    $deleted = 0;
    $files = glob($thumbDir . '*');
    $cutoff = time() - 604800; // 1週間前
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }
    
    return $deleted;
}

// 古いロックファイルを削除
function cleanupOldLocks() {
    $lockDir = __DIR__ . '/locks/';
    if (!is_dir($lockDir)) return 0;
    
    $deleted = 0;
    $files = glob($lockDir . '*');
    $cutoff = time() - 3600; // 1時間前
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }
    
    return $deleted;
}

// コマンドライン実行時のみ動作
if (php_sapi_name() === 'cli' || (isset($_GET['cron_key']) && $_GET['cron_key'] === 'your_secret_key')) {
    $cacheDeleted = cleanupOldCache();
    $thumbsDeleted = cleanupOldThumbnails();
    $locksDeleted = cleanupOldLocks();
    
    echo "Cleanup completed:\n";
    echo "Cache files deleted: $cacheDeleted\n";
    echo "Thumbnails deleted: $thumbsDeleted\n";
    echo "Lock files deleted: $locksDeleted\n";
}
?>