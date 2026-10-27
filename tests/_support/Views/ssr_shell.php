<?php /** @var array<string, mixed> $page */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <?= inertia_head($page, '<title>Fallback head</title>') ?>
</head>
<body>
<?= inertia_app($page) ?>
</body>
</html>
