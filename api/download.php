<?php
// api/download.php - API用ダウンロードエンドポイント（デバッグ版）
require_once '../config.php';

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['token'] ?? '');

try {
    // デバッグモード（?debug=1 を追加）
    if (isset($_GET['debug'])) {
        header('Content-Type: application/json');
        
        $debugInfo = [
            'debug' => true,
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'user_agent' => $userAgent,
            'client_ip' => $clientIP,
            'token_received' => $token ? 'YES (length: ' . strlen($token) . ')' : 'NO',
            'token_preview' => $token ? substr($token, 0, 16) . '...' : 'none',
            'api_keys_file_exists' => file_exists(API_KEYS_FILE),
            'api_keys_file_readable' => file_exists(API_KEYS_FILE) ? is_readable(API_KEYS_FILE) : false,
            'upload_dir_exists' => file_exists(UPLOAD_DIR),
            'upload_dir_readable' => file_exists(UPLOAD_DIR) ? is_readable(UPLOAD_DIR) : false
        ];

        // ヘッダー情報を取得
        $allHeaders = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', substr($key, 5));
                $allHeaders[$header] = $value;
            }
        }
        $debugInfo['headers'] = $allHeaders;

        // APIキーファイルの内容をチェック
        if (file_exists(API_KEYS_FILE)) {
            $apiKeysContent = file_get_contents(API_KEYS_FILE);
            $apiKeys = json_decode($apiKeysContent, true);
            $debugInfo['api_keys_count'] = is_array($apiKeys) ? count($apiKeys) : 0;
            $debugInfo['api_keys_valid_json'] = json_last_error() === JSON_ERROR_NONE;
            $debugInfo['json_error'] = json_last_error_msg();
            
            if (is_array($apiKeys)) {
                $debugInfo['api_keys_sample'] = [];
                $count = 0;
                foreach ($apiKeys as $key => $data) {
                    if ($count >= 3) break; // 最初の3つだけ表示
                    $debugInfo['api_keys_sample'][] = [
                        'key_preview' => substr($key, 0, 16) . '...',
                        'name' => $data['name'] ?? 'no name',
                        'active' => $data['active'] ?? false,
                        'expires_at' => $data['expires_at'] ?? null
                    ];
                    $count++;
                }
                
                // 受信したトークンが存在するかチェック
                if ($token) {
                    $debugInfo['token_exists_in_keys'] = isset($apiKeys[$token]);
                    if (isset($apiKeys[$token])) {
                        $keyData = $apiKeys[$token];
                        $debugInfo['token_details'] = [
                            'name' => $keyData['name'],
                            'active' => $keyData['active'],
                            'expires_at' => $keyData['expires_at'],
                            'is_expired' => $keyData['expires_at'] && strtotime($keyData['expires_at']) < time()
                        ];
                    }
                }
            }
        } else {
            $debugInfo['api_keys_file_error'] = 'File does not exist';
        }

        // ファイル一覧も表示
        $files = [];
        if (is_dir(UPLOAD_DIR)) {
            $items = scandir(UPLOAD_DIR);
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..' && is_file(UPLOAD_DIR . $item)) {
                    $files[] = $item;
                }
            }
        }
        $debugInfo['available_files'] = array_slice($files, 0, 10); // 最初の10ファイルのみ

        echo json_encode($debugInfo, JSON_PRETTY_PRINT);
        exit;
    }

    // User-Agentチェック
    if (empty($userAgent)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'what' => 'useragent', 'message' => 'User-Agentがないみたいですけど？']);
        exit;
    }

    // APIキー検証
    if (empty($token)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'what' => 'token', 'message' => 'APIキーがないみたいです、管理者にお問い合わせの上ヘッダーのX-API-Tokenにキーを指定してください。']);
        exit;
    }

    // APIキー検証の詳細ログ
    $isValidKey = false;
    $validationError = '';
    
    try {
        if (!file_exists(API_KEYS_FILE)) {
            $validationError = 'APIキーファイルが存在しません';
        } else {
            $apiKeysContent = file_get_contents(API_KEYS_FILE);
            $apiKeys = json_decode($apiKeysContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $validationError = 'APIキーファイルのJSONが無効です: ' . json_last_error_msg();
            } elseif (!is_array($apiKeys)) {
                $validationError = 'APIキーファイルの形式が無効です';
            } elseif (!isset($apiKeys[$token])) {
                $validationError = '指定されたAPIキーは存在しません';
            } else {
                $keyData = $apiKeys[$token];
                
                if (!$keyData['active']) {
                    $validationError = 'このAPIキーは無効化されています';
                } elseif ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
                    $validationError = 'このAPIキーは期限切れです';
                } else {
                    $isValidKey = true;
                    
                    // 使用統計を更新
                    $apiKeys[$token]['last_used'] = date('Y-m-d H:i:s');
                    $apiKeys[$token]['usage_count']++;
                    file_put_contents(API_KEYS_FILE, json_encode($apiKeys, JSON_PRETTY_PRINT), LOCK_EX);
                }
            }
        }
    } catch (Exception $e) {
        $validationError = 'APIキー検証中にエラー: ' . $e->getMessage();
    }

    if (!$isValidKey) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'what' => 'token', 'message' => '無効なAPIキーです: ' . $validationError]);
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
    logActivity('API_DOWNLOAD', $filename, $userAgent, $clientIP, $token);

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
    logActivity('API_DOWNLOAD_ERROR', $filename ?? '', $userAgent, $clientIP, $token);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>