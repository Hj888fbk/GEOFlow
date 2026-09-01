<?php

$base = require __DIR__.'/../en/hengjia_content.php';

return array_replace_recursive($base, [
    'module' => '恒佳コンテンツセンター',
    'navigation' => [
        'label' => '恒佳コンテンツワークフロー',
        'today' => '今日の作業', 'tasks' => 'コンテンツタスク', 'evidence' => '証拠管理',
        'studio' => 'コンテンツスタジオ', 'previews' => 'チャネルプレビュー', 'publishing' => '公開センター',
        'accounts' => 'アカウントとプラットフォーム', 'governance' => 'プロンプトと資料管理',
    ],
    'pages' => [
        'today' => ['title' => '今日の作業', 'subtitle' => '期限タスク、証拠不足、生成履歴、実際の配信状態を確認します。'],
        'tasks' => ['title' => 'コンテンツタスク', 'subtitle' => '生成前に製品、対象者、購買意図、ページ役割、対象アカウントを定義します。'],
        'evidence' => ['title' => '証拠ワークスペース', 'subtitle' => '追跡可能な資料と共に主張、公開範囲、資格情報を登録します。'],
        'studio' => ['title' => 'コンテンツスタジオ', 'subtitle' => '管理されたパッケージを生成し、阻害要因を審査して Article 下書きを作成します。'],
        'previews' => ['title' => 'チャネルプレビュー', 'subtitle' => '承認前に実際のフィールド、制限、アカウント紐付けを比較します。'],
        'publishing' => ['title' => '公開センター', 'subtitle' => 'チャネルごとに承認し、安全な下書きを準備して実際の受領結果を読み戻します。'],
        'accounts' => ['title' => 'アカウントとプラットフォーム', 'subtitle' => 'ブラウザ Cookie やセッションを保存せず複数アカウントを管理します。'],
        'governance' => ['title' => 'プロンプトと資料管理', 'subtitle' => '本番レシピを変更せず、バージョン、承認資料、生成履歴を確認します。'],
    ],
    'status_guard' => [
        'title' => '状態の境界',
        'body' => '候補、下書き、承認済みは公開済みではありません。公開、クロール、登録、AI言及、問い合わせ帰属を分けて記録します。',
    ],
    'common' => [
        'status' => '状態', 'actions' => '操作', 'source' => '出典', 'scope' => '適用範囲',
        'account' => 'アカウント', 'channel' => 'チャネル', 'blockers' => '阻害要因', 'none' => 'なし',
        'save' => '保存', 'approve' => '承認', 'refresh' => '更新', 'verify' => '確認', 'disable' => '無効化',
        'not_available' => '未接続', 'not_recorded' => '未記録',
    ],
    'status' => [
        'candidate' => '候補', 'blocked' => 'ブロック', 'ready' => '生成可能', 'in_review' => '審査中',
        'approved' => '承認済み', 'draft' => '下書き', 'promoted' => 'Article 下書き作成済み',
        'published' => '読み戻しで公開確認', 'queued' => 'キュー済み',
        'awaiting_manual_confirmation' => '人による最終確認待ち', 'failed' => '失敗',
        'connected' => '接続済み', 'verified' => '確認済み', 'not_verified' => '未確認', 'disabled' => '無効',
        'rejected' => '却下済み',
    ],
]);
