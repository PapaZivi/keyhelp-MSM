<!doctype html>
<html lang="<?= h(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('error.title')) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-screen">
    <main class="login-card">
        <span class="brand-mark">K</span>
        <h1><?= h(t('error.title')) ?></h1>
        <p><?= h($message ?? t('message.app_failed')) ?></p>
    </main>
</body>
</html>
