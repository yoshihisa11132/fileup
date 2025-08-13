<?php
// api/list.php - ファイル一覧取得API（APIキー管理対応版）
require_once '../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, Authorization, X-API-Token');

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$clientIP = getClientIP();
$token = $_SERVER['HTTP_X_API_TOKEN'] ?? ($_GET['token'] ?? '');

try {
    // User-Agentチェック
    if (empty($userAgent)) {
        http_response_code(400);
        throw new Exception('User-Agentがないみたいですけど？');
    }

    // APIキー検証
    if (empty($token)) {
        http_response_code(401);
        throw new Exception('APIキーがないみたいです、管理者にお問い合わせの上ヘッダーのX-API-Tokenにキーを指定してください。');
    }

    // APIキー検証（新システムのみ）
    if (!ApiKeyManager::validateApiKey($token)) {
        http_response_code(401);
        throw new Exception('無効なAPIキーです。管理者に新しいAPIキーを発行してもらってください。');
    }

    $files = [];
    if (is_dir(UPLOAD_DIR)) {
        $items = scandir(UPLOAD_DIR);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..' && is_file(UPLOAD_DIR . $item)) {
                $filePath = UPLOAD_DIR . $item;
                $files[] = [
                    'filename' => $item,
                    'size' => filesize($filePath),
                    'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                    'extension' => pathinfo($item, PATHINFO_EXTENSION)
                ];
            }
        }
    }

    // ファイルを更新日時順でソート
    usort($files, function($a, $b) {
        return strtotime($b['modified']) - strtotime($a['modified']);
    });

    // ログ記録（APIキー情報も含める）
    logActivity('API_LIST', count($files) . ' files', $userAgent, $clientIP, $token);

    echo json_encode([
        'success' => true,
        'count' => count($files),
        'files' => $files
    ]);

} catch (Exception $e) {
    logActivity('API_LIST_ERROR', $e->getMessage(), $userAgent, $clientIP, $token);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>