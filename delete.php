<?php
// delete.php - ファイル削除機能
require_once 'config.php';

setSecurityHeaders();

// 削除キー管理用のファイル
define('DELETE_KEYS_FILE', __DIR__ . '/admin/delete_keys.json');

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
    $content = json_encode($keys, JSON_PRETTY_print);
    return file_put_contents(DELETE_KEYS_FILE, $content, LOCK_EX) !== false;
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
$message = '';
$error = '';
$deleted = false;

// パラメータ取得
$filename = $_GET['file'] ?? '';
$deleteKey = $_GET['key'] ?? '';
$confirm = $_GET['confirm'] ?? '';

if (!empty($filename) && !empty($deleteKey) && $confirm === '1') {
    // レート制限チェック（1分間に5回まで）
    if (!RateLimit::checkLimit($clientIP . '_delete', 5, 60)) {
        $error = '削除の頻度が高すぎます。しばらく待ってからお試しください。';
    } else {
        // 削除キーが数字のみかチェック
        if (!preg_match('/^[0-9]+$/', $deleteKey)) {
            $error = '無効な削除キーです。';
        } else {
            $filePath = UPLOAD_DIR . $filename;
            
            if (!file_exists($filePath)) {
                $error = 'ファイルが見つかりません。';
            } else {
                // 削除キーの検証
                $deleteKeys = loadDeleteKeys();
                
                if (!isset($deleteKeys[$filename])) {
                    $error = 'このファイルの削除キー情報が見つかりません。';
                } elseif ($deleteKeys[$filename]['delete_key'] !== $deleteKey) {
                    $error = '削除キーが正しくありません。';
                } else {
                    // ファイル削除実行
                    if (unlink($filePath)) {
                        // 削除キー情報も削除
                        unset($deleteKeys[$filename]);
                        saveDeleteKeys($deleteKeys);
                        
                        $message = "ファイル '{$filename}' を正常に削除しました。";
                        $deleted = true;
                        
                        // ログ記録
                        logActivity('USER_DELETE', $filename, $userAgent, $clientIP, '', "DeleteKey: $deleteKey");
                    } else {
                        $error = 'ファイルの削除に失敗しました。';
                        logActivity('USER_DELETE_ERROR', $filename, $userAgent, $clientIP, '', "DeleteKey: $deleteKey, Error: Failed to delete file");
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ファイル削除 - fileup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #59d0ff;
            background-image: linear-gradient(135deg, #2a9bff, #00f2fe);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
            font-size: 2rem;
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        .success-icon { color: #28a745; }
        .error-icon { color: #dc3545; }
        .question-icon { color: #667eea; }
        .message {
            font-size: 1.2rem;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .success-message { color: #155724; }
        .error-message { color: #721c24; }
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group input {
            width: 100%;
            max-width: 300px;
            padding: 10px;
            border: 2px solid #667eea;
            border-radius: 10px;
            font-size: 1rem;
            text-align: center;
        }
        .btn {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 1.1rem;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
            transition: transform 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
        }
        .btn-danger {
            background: linear-gradient(45deg, #dc3545, #c82333);
        }
        .btn-home {
            background: linear-gradient(45deg, #6c757d, #495057);
        }
        .file-info {
            background: #e9ecef;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-family: monospace;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗑️ ファイル削除</h1>
        
        <?php if ($deleted): ?>
            <div class="icon success-icon">✅</div>
            <div class="message success-message">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <a href="index.html" class="btn btn-home">🏠 ホームに戻る</a>
            
        <?php elseif (!empty($error)): ?>
            <div class="icon error-icon">❌</div>
            <div class="message error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <a href="index.html" class="btn btn-home">🏠 ホームに戻る</a>
            
        <?php elseif (!empty($filename) && !empty($deleteKey)): ?>
            <!-- 削除確認画面 -->
            <div class="icon question-icon">❓</div>
            <div class="message">
                以下のファイルを削除しますか？
            </div>
            <div class="file-info">
                📄 <?php echo htmlspecialchars($filename); ?>
            </div>
            <form method="get">
                <input type="hidden" name="file" value="<?php echo htmlspecialchars($filename); ?>">
                <input type="hidden" name="key" value="<?php echo htmlspecialchars($deleteKey); ?>">
                <input type="hidden" name="confirm" value="1">
                <button type="submit" class="btn btn-danger">🗑️ 削除を実行</button>
                <a href="javascript:history.back()" class="btn">🔙 戻る</a>
            </form>
            
        <?php else: ?>
            <!-- 削除フォーム -->
            <div class="icon question-icon">🔑</div>
            <div class="message">
                削除するファイルの情報を入力してください
            </div>
            <form method="get" class="form-section">
                <div class="form-group">
                    <label for="file">ファイル名</label>
                    <input type="text" id="file" name="file" required placeholder="ファイル名を入力">
                </div>
                <div class="form-group">
                    <label for="key">削除キー</label>
                    <input type="text" id="key" name="key" pattern="[0-9]*" required placeholder="削除キー（数字のみ）">
                </div>
                <button type="submit" class="btn">🔍 削除確認</button>
            </form>
            <a href="index.html" class="btn btn-home">🏠 ホームに戻る</a>
        <?php endif; ?>
    </div>

    <script>
        // 削除キーの入力制限（数字のみ）
        const keyInput = document.getElementById('key');
        if (keyInput) {
            keyInput.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
            });
        }
    </script>
</body>
</html>