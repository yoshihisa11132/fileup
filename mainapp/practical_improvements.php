<?php
// practical_improvements.php - 実装可能な改善版

// 1. ファイルベースキャッシュシステム
class FileCache {
    private static $cacheDir = __DIR__ . '/cache/';
    
    public static function init() {
        if (!file_exists(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    public static function get($key, $maxAge = 3600) {
        self::init();
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        
        if (file_exists($cacheFile)) {
            $cacheTime = filemtime($cacheFile);
            if ((time() - $cacheTime) < $maxAge) {
                return unserialize(file_get_contents($cacheFile));
            } else {
                unlink($cacheFile); // 期限切れキャッシュを削除
            }
        }
        
        return false;
    }
    
    public static function set($key, $data, $maxAge = 3600) {
        self::init();
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        return file_put_contents($cacheFile, serialize($data), LOCK_EX) !== false;
    }
    
    public static function delete($key) {
        self::init();
        $cacheFile = self::$cacheDir . md5($key) . '.cache';
        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }
        return true;
    }
    
    public static function clear() {
        self::init();
        $files = glob(self::$cacheDir . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
    }
}

// 2. 画像リサイズ・最適化システム
class ImageProcessor {
    private static $thumbDir = __DIR__ . '/thumbs/';
    
    public static function init() {
        if (!file_exists(self::$thumbDir)) {
            mkdir(self::$thumbDir, 0755, true);
        }
    }
    
    public static function isImage($filename) {
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $imageTypes);
    }
    
    public static function generateThumbnail($originalPath, $width = 300, $height = 300, $quality = 85) {
        if (!self::isImage($originalPath)) {
            return false;
        }
        
        self::init();
        $filename = basename($originalPath);
        $thumbPath = self::$thumbDir . $width . 'x' . $height . '_' . $filename;
        
        // サムネイルが既に存在し、元ファイルより新しい場合はそれを返す
        if (file_exists($thumbPath) && filemtime($thumbPath) >= filemtime($originalPath)) {
            return $thumbPath;
        }
        
        // 画像情報を取得
        $imageInfo = getimagesize($originalPath);
        if (!$imageInfo) {
            return false;
        }
        
        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $imageType = $imageInfo[2];
        
        // アスペクト比を保持してリサイズ
        $aspectRatio = $originalWidth / $originalHeight;
        if ($width / $height > $aspectRatio) {
            $newWidth = $height * $aspectRatio;
            $newHeight = $height;
        } else {
            $newWidth = $width;
            $newHeight = $width / $aspectRatio;
        }
        
        // 元画像を読み込み
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($originalPath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($originalPath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($originalPath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($originalPath);
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        // リサイズした画像を作成
        $thumbnail = imagecreatetruecolor($newWidth, $newHeight);
        
        // 透明度を保持（PNG・GIF用）
        if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
            imagefill($thumbnail, 0, 0, $transparent);
        }
        
        // リサイズ実行
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        
        // 保存
        $success = false;
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $success = imagejpeg($thumbnail, $thumbPath, $quality);
                break;
            case IMAGETYPE_PNG:
                $success = imagepng($thumbnail, $thumbPath, 9 - ($quality / 10));
                break;
            case IMAGETYPE_GIF:
                $success = imagegif($thumbnail, $thumbPath);
                break;
            case IMAGETYPE_WEBP:
                $success = imagewebp($thumbnail, $thumbPath, $quality);
                break;
        }
        
        // メモリ解放
        imagedestroy($source);
        imagedestroy($thumbnail);
        
        return $success ? $thumbPath : false;
    }
    
    // WebP変換（軽量化）
    public static function convertToWebP($originalPath, $quality = 85) {
        if (!function_exists('imagewebp')) {
            return false;
        }
        
        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $originalPath);
        
        $imageInfo = getimagesize($originalPath);
        if (!$imageInfo) return false;
        
        switch ($imageInfo[2]) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($originalPath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($originalPath);
                break;
            default:
                return false;
        }
        
        if ($image && imagewebp($image, $webpPath, $quality)) {
            imagedestroy($image);
            return $webpPath;
        }
        
        return false;
    }
}

// 3. 並行アクセス制御（ファイルロック）
class ConcurrencyControl {
    private static $lockDir = __DIR__ . '/locks/';
    
    public static function init() {
        if (!file_exists(self::$lockDir)) {
            mkdir(self::$lockDir, 0755, true);
        }
    }
    
    // ファイル操作のロック取得
    public static function acquireLock($resource, $timeout = 10) {
        self::init();
        $lockFile = self::$lockDir . md5($resource) . '.lock';
        $startTime = time();
        
        while (true) {
            $handle = fopen($lockFile, 'c+');
            if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
                // ロック取得成功
                fwrite($handle, getmypid() . '|' . time());
                return $handle;
            }
            
            if ($handle) {
                fclose($handle);
            }
            
            // タイムアウトチェック
            if ((time() - $startTime) >= $timeout) {
                return false;
            }
            
            usleep(100000); // 100ms待機
        }
    }
    
    public static function releaseLock($handle) {
        if ($handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    
    // 使用例: APIレスポンス制御
    public static function rateLimitCheck($identifier, $maxRequests = 100, $timeWindow = 3600) {
        self::init();
        $rateLimitFile = self::$lockDir . 'rate_' . md5($identifier) . '.txt';
        
        $lock = self::acquireLock('rate_limit_' . $identifier, 5);
        if (!$lock) {
            return false; // ロック取得失敗
        }
        
        $currentTime = time();
        $requests = [];
        
        if (file_exists($rateLimitFile)) {
            $data = file_get_contents($rateLimitFile);
            $requests = $data ? json_decode($data, true) : [];
        }
        
        // 古いリクエストを削除
        $requests = array_filter($requests, function($timestamp) use ($currentTime, $timeWindow) {
            return ($currentTime - $timestamp) < $timeWindow;
        });
        
        // リクエスト数チェック
        if (count($requests) >= $maxRequests) {
            self::releaseLock($lock);
            return false;
        }
        
        // 新しいリクエストを記録
        $requests[] = $currentTime;
        file_put_contents($rateLimitFile, json_encode($requests));
        
        self::releaseLock($lock);
        return true;
    }
}

// 4. 改善されたファイル一覧取得（キャッシュ付き）
function getCachedFileList($forceRefresh = false) {
    $cacheKey = 'file_list_' . md5(UPLOAD_DIR);
    
    if (!$forceRefresh) {
        $cached = FileCache::get($cacheKey, 300); // 5分キャッシュ
        if ($cached !== false) {
            return $cached;
        }
    }
    
    $files = [];
    if (is_dir(UPLOAD_DIR)) {
        $items = scandir(UPLOAD_DIR);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..' && is_file(UPLOAD_DIR . $item)) {
                $filePath = UPLOAD_DIR . $item;
                $fileInfo = [
                    'name' => $item,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath),
                    'type' => pathinfo($item, PATHINFO_EXTENSION),
                    'is_image' => ImageProcessor::isImage($item)
                ];
                
                // 画像の場合はサムネイル生成
                if ($fileInfo['is_image']) {
                    $thumbnail = ImageProcessor::generateThumbnail($filePath, 150, 150);
                    $fileInfo['thumbnail'] = $thumbnail ? basename($thumbnail) : null;
                }
                
                $files[] = $fileInfo;
            }
        }
    }
    
    // ファイルを更新日時順でソート
    usort($files, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
    
    // キャッシュに保存
    FileCache::set($cacheKey, $files, 300);
    
    return $files;
}

// 5. サムネイル配信用エンドポイント
// thumb.php として別ファイルで作成
?>