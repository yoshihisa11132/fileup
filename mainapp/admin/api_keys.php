<?php
// admin/api_keys.php - APIキー管理画面
require_once '../config.php';

$message = '';
$error = '';

// API キー作成処理
if (isset($_POST['create_key'])) {
    $name = trim($_POST['key_name']);
    $description = trim($_POST['key_description']);
    $expires = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    
    if (empty($name)) {
        $error = 'キー名は必須です。';
    } else {
        $newKey = ApiKeyManager::createApiKey($name, $description, $expires);
        if ($newKey) {
            $message = "新しいAPIキーが作成されました。<br><strong>キー: {$newKey}</strong><br>※このキーを安全に保管してください。再表示はできません。";
        } else {
            $error = 'APIキーの作成に失敗しました。';
        }
    }
}

// API キー無効化処理
if (isset($_POST['revoke_key'])) {
    $keyToRevoke = $_POST['revoke_key'];
    if (ApiKeyManager::revokeApiKey($keyToRevoke)) {
        $message = 'APIキーを無効化しました。';
    } else {
        $error = 'APIキーの無効化に失敗しました。';
    }
}

// API キー削除処理
if (isset($_POST['delete_key'])) {
    $keyToDelete = $_POST['delete_key'];
    if (ApiKeyManager::deleteApiKey($keyToDelete)) {
        $message = 'APIキーを削除しました。';
    } else {
        $error = 'APIキーの削除に失敗しました。';
    }
}

$apiKeys = ApiKeyManager::getAllApiKeys();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>APIキー管理 - ファイルサーバー</title>
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
        .nav {
            margin-bottom: 30px;
            text-align: center;
        }
        .nav a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
            padding: 10px 20px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .nav a:hover {
            background: #667eea;
            color: white;
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
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }
        .form-group textarea {
            resize: vertical;
            height: 80px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 1rem;
            display: inline-block;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-warning {
            background: #ffc107;
            color: black;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .btn-small {
            padding: 5px 10px;
            font-size: 0.9rem;
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
        .key-display {
            font-family: monospace;
            font-size: 0.9rem;
            background: #f8f9fa;
            padding: 5px;
            border-radius: 3px;
            word-break: break-all;
        }
        .status-active {
            color: #28a745;
            font-weight: bold;
        }
        .status-inactive {
            color: #dc3545;
            font-weight: bold;
        }
        .status-expired {
            color: #ffc107;
            font-weight: bold;
        }
        .key-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🔑 APIキー管理</h1>
        
        <div class="nav">
            <a href="index.php">📊 ダッシュボード</a>
            <a href="api_keys.php">🔑 APIキー管理</a>
        </div>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php
        $activeKeys = 0;
        $expiredKeys = 0;
        $totalUsage = 0;
        foreach ($apiKeys as $key => $data) {
            if ($data['active']) {
                if ($data['expires_at'] && strtotime($data['expires_at']) < time()) {
                    $expiredKeys++;
                } else {
                    $activeKeys++;
                }
            }
            $totalUsage += $data['usage_count'];
        }
        ?>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($apiKeys); ?></div>
                <div class="stat-label">総APIキー数</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $activeKeys; ?></div>
                <div class="stat-label">有効なキー</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $expiredKeys; ?></div>
                <div class="stat-label">期限切れキー</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $totalUsage; ?></div>
                <div class="stat-label">総使用回数</div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">➕ 新しいAPIキーの作成</div>
            <div class="section-content">
                <form method="post">
                    <div class="form-group">
                        <label for="key_name">キー名 *</label>
                        <input type="text" id="key_name" name="key_name" required placeholder="例: iOS App API Key">
                    </div>
                    <div class="form-group">
                        <label for="key_description">説明</label>
                        <textarea id="key_description" name="key_description" placeholder="このAPIキーの用途や説明"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="expires_at">有効期限（オプション）</label>
                        <input type="datetime-local" id="expires_at" name="expires_at">
                    </div>
                    <button type="submit" name="create_key" class="btn btn-primary">APIキーを作成</button>
                </form>
            </div>
        </div>

        <div class="section">
            <div class="section-header">🔑 APIキー一覧</div>
            <div class="section-content">
                <?php if (empty($apiKeys)): ?>
                    <p>まだAPIキーが作成されていません。</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>キー名</th>
                                <th>APIキー</th>
                                <th>ステータス</th>
                                <th>作成日時</th>
                                <th>最終使用</th>
                                <th>使用回数</th>
                                <th>有効期限</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiKeys as $key => $data): ?>
                                <?php
                                $isExpired = $data['expires_at'] && strtotime($data['expires_at']) < time();
                                $statusClass = '';
                                $statusText = '';
                                
                                if (!$data['active']) {
                                    $statusClass = 'status-inactive';
                                    $statusText = '無効';
                                } elseif ($isExpired) {
                                    $statusClass = 'status-expired';
                                    $statusText = '期限切れ';
                                } else {
                                    $statusClass = 'status-active';
                                    $statusText = '有効';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($data['name']); ?></strong>
                                        <?php if ($data['description']): ?>
                                            <br><small><?php echo htmlspecialchars($data['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="key-display">
                                            <?php echo substr($key, 0, 16) . '...'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="<?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $data['created_at']; ?></td>
                                    <td><?php echo $data['last_used'] ?? '未使用'; ?></td>
                                    <td><?php echo $data['usage_count']; ?></td>
                                    <td><?php echo $data['expires_at'] ?? '無期限'; ?></td>
                                    <td>
                                        <div class="key-actions">
                                            <?php if ($data['active'] && !$isExpired): ?>
                                                <form method="post" style="display: inline;">
                                                    <button type="submit" name="revoke_key" value="<?php echo htmlspecialchars($key); ?>"
                                                            class="btn btn-warning btn-small"
                                                            onclick="return confirm('このAPIキーを無効化しますか？')">無効化</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" style="display: inline;">
                                                <button type="submit" name="delete_key" value="<?php echo htmlspecialchars($key); ?>"
                                                        class="btn btn-danger btn-small"
                                                        onclick="return confirm('このAPIキーを完全に削除しますか？')">削除</button>
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
    </div>

    <script>
        // メッセージの自動消去
        setTimeout(function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(function(msg) {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(function() {
                    msg.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>