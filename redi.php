<?php
// vパラメーターを取得
$vParam = isset($_GET['file']) ? $_GET['file'] : '';

// vパラメーターが存在しない場合はエラー
if (empty($vParam)) {
    die('エラー: ファイルが指定されていません。');
}

// ダウンロードURLを構築
$downloadUrl = './download.php?file=' . urlencode($vParam);

// ファイル拡張子を取得してメディアタイプを判定
$fileExtension = strtolower(pathinfo($vParam, PATHINFO_EXTENSION));
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
$audioExtensions = ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac'];
$videoExtensions = ['mp4', 'webm', 'avi', 'mov', 'wmv', 'flv', 'm4v'];

$isImage = in_array($fileExtension, $imageExtensions);
$isAudio = in_array($fileExtension, $audioExtensions);
$isVideo = in_array($fileExtension, $videoExtensions);

// プレビュー用のURL（ダウンロードリンクと同じ形式）
$previewUrl = '';
if ($isImage || $isAudio || $isVideo) {
    $previewUrl = './download.php?file=' . urlencode($vParam);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ファイルダウンロード</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            text-align: center;
            background-color: #f5f5f5;
        }
        
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filename {
            font-weight: bold;
            color: #333;
            margin: 20px 0;
            word-break: break-all;
        }
        
        .countdown {
            font-size: 18px;
            color: #666;
            margin: 20px 0;
        }
        
        .download-link {
            display: inline-block;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 5px;
            margin-top: 20px;
            transition: background-color 0.3s;
        }
        
        .download-link:hover {
            background-color: #0056b3;
        }
        
        .timer {
            font-weight: bold;
            color: #dc3545;
        }
        
        .preview-container {
            margin: 20px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            background-color: #fafafa;
        }
        
        .preview-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .preview-audio, .preview-video {
            width: 100%;
            max-width: 500px;
            border-radius: 5px;
        }
        
        .preview-video {
            max-height: 300px;
        }
        
        .preview-title {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>ファイルダウンロード</h2>
        
        <div class="filename">
            <?php echo htmlspecialchars($vParam, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        
        <?php if ($isImage || $isAudio || $isVideo): ?>
        <div class="preview-container">
            <div class="preview-title">プレビュー:</div>
            
            <?php if ($isImage): ?>
                <img src="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                     alt="画像プレビュー" 
                     class="preview-image"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <div style="display:none; color: #999; font-size: 14px;">画像を読み込めませんでした</div>
            
            <?php elseif ($isAudio): ?>
                <audio controls class="preview-audio" preload="metadata">
                    <source src="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>" type="audio/<?php echo $fileExtension === 'mp3' ? 'mpeg' : $fileExtension; ?>">
                    <p style="color: #999; font-size: 14px;">お使いのブラウザは音楽ファイルの再生をサポートしていません。</p>
                </audio>
            
            <?php elseif ($isVideo): ?>
                <video controls class="preview-video" preload="metadata">
                    <source src="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8'); ?>" type="video/<?php echo $fileExtension; ?>">
                    <p style="color: #999; font-size: 14px;">お使いのブラウザは動画ファイルの再生をサポートしていません。</p>
                </video>
            <?php endif; ?>
            
        </div>
        <?php endif; ?>
        
        <p>をダウンロードしますか？</p>
        <p class="countdown">なお<span class="timer" id="countdown">10</span>秒後に自動的にジャンプします。</p>
        
        <a href="<?php echo htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>" class="download-link">
            ダウンロードする
        </a>
    </div>

    <script>
        // カウントダウンと自動リダイレクト
        let countdown = 10;
        const countdownElement = document.getElementById('countdown');
        const downloadUrl = '<?php echo addslashes($downloadUrl); ?>';
        
        const timer = setInterval(function() {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.href = downloadUrl;
            }
        }, 1000);
    </script>
</body>
</html>