<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #222; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px 20px; }
        small { color: #777; }
    </style>
</head>
<body>
    <h1><?= e($title) ?></h1>

    <div class="card">
        <p>Hello, <strong><?= e($user['name']) ?></strong> (@<?= e($user['username']) ?>)</p>

        <ul>
            <?php foreach ($items as $item): ?>
                <li><?= e($item) ?></li>
            <?php endforeach; ?>
        </ul>

        <small>Rendered at <?= e($time) ?></small>
    </div>
</body>
</html>
