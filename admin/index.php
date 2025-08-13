<?php
// admin/index.php - 管理画面
require_once '../config.php';

// ファイル一覧取得
function getFileList() {
    $files = [];
    if (is_dir(UPLOAD_DIR)) {
        $items = scandir(UPLOAD_DIR);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..' && is_file(UPLOAD_DIR . $item)) {
                $filePath = UPLOAD_DIR . $item;
                $files[] = [
                    'name' => $item,
                    'size' => filesize($filePath),
                    'modified' => filemtime($filePath),
                    'type' => pathinfo($item, PATHINFO_EXTENSION)
                ];
            }
        }
    }
    usort($files, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
    return $files;
}

// アクセスログ取得
function getAccessLog($lines = 100) {
    $logFile = __DIR__ . '/access.log';
    if (!file_exists($logFile)) {
        return [];
    }
    
    $logs = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_slice(array_reverse($logs), 0, $lines);
}

// ファイル削除処理
if (isset($_POST['delete_file'])) {
    $filename = $_POST['delete_file'];
    $filePath = UPLOAD_DIR . $filename;
    if (file_exists($filePath) && unlink($filePath)) {
        $message = "ファイル '{$filename}' を削除しました。";
    } else {
        $error = "ファイルの削除に失敗しました。";
    }
}

$files = getFileList();
$logs = getAccessLog();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理画面 - ファイルサーバー</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
        .section {
            background: white;
            margin-bottom: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            font-size: 1.2rem;
            font-weight: bold;
            color: #333;
        }
        .section-content {
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        .file-actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .btn-download {
            background: #28a745;
            color: white;
        }
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .log-entry {
            font-family: monospace;
            font-size: 0.9rem;
            padding: 5px;
            border-bottom: 1px solid #f0f0f0;
        }
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .file-size {
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 ファイルサーバー管理画面</h1>
        
        <?php if (isset($message)): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($files); ?></div>
                <div class="stat-label">保存ファイル数</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php 
                    $totalSize = array_sum(array_column($files, 'size'));
                    echo number_format($totalSize / 1024 / 1024, 1);
                    ?>MB
                </div>
                <div class="stat-label">総容量</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($logs); ?></div>
                <div class="stat-label">アクセスログ数</div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">📁 ファイル一覧</div>
            <div class="section-content">
                <?php if (empty($files)): ?>
                    <p>ファイルがありません。</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ファイル名</th>
                                <th>サイズ</th>
                                <th>更新日時</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $file): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($file['name']); ?></td>
                                <td class="file-size">
                                    <?php echo number_format($file['size'] / 1024, 1); ?> KB
                                </td>
                                <td><?php echo date('Y-m-d H:i:s', $file['modified']); ?></td>
                                <td>
                                    <div class="file-actions">
                                        <a href="../download.php?file=<?php echo urlencode($file['name']); ?>" 
                                           class="btn btn-download" download>ダウンロード</a>
                                        <a href="../redi.php?file=<?php echo urlencode($file['name']); ?>" 
                                           class="btn btn-download">プレビュー</a>
                                        <form method="post" style="display: inline;">
                                            <button type="submit" name="delete_file" 
                                                    value="<?php echo htmlspecialchars($file['name']); ?>"
                                                    class="btn btn-delete"
                                                    onclick="return confirm('本当に削除しますか？')">削除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="section">
            <div class="section-header">📊 アクセスログ (最新100件)</div>
            <div class="section-content">
                <?php if (empty($logs)): ?>
                    <p>ログがありません。</p>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log-entry"><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // 自動更新（30秒間隔）
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>