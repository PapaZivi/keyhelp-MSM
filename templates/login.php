<!doctype html>
<html lang="<?= h(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($config['app']['name']) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-screen">
    <nav class="language-switch login-language" aria-label="<?= h(t('common.language')) ?>">
        <?php foreach ($supportedLocales as $localeCode => $localeLabel): ?>
            <a class="<?= current_locale() === $localeCode ? 'active' : '' ?>" href="<?= h(locale_url($localeCode)) ?>" hreflang="<?= h($localeCode) ?>"><?= h($localeLabel) ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="post" class="login-card">
        <input type="hidden" name="_action" value="login">
        <span class="brand-mark">K</span>
        <h1><?= h($config['app']['name']) ?></h1>
        <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <input name="user" placeholder="<?= h(t('login.user')) ?>" required autofocus>
        <input name="password" type="password" placeholder="<?= h(t('login.password')) ?>" required>
        <button class="primary"><?= h(t('login.submit')) ?></button>
    </form>
</body>
</html>
