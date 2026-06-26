<!doctype html>
<html lang="<?= h(current_locale()) ?>">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($config['app']['name']) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
        <link rel="stylesheet" href="node_modules/dashbrd/dist/assets/css/theme.bundle.css">
        <link rel="stylesheet" href="/assets/app.css">
    </head>

    <body class="login-screen bg-light">
        <nav class="language-switch login-language btn-group btn-group-sm" aria-label="<?= h(t('common.language')) ?>">
            <?php foreach ($supportedLocales as $localeCode => $localeLabel): ?>
            <a class="btn <?= current_locale() === $localeCode ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h(locale_url($localeCode)) ?>" hreflang="<?= h($localeCode) ?>"><?= h($localeLabel) ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="post" class="login-card card">
            <div class="card-body">
                <input type="hidden" name="_action" value="login">
                <span class="brand-mark">K</span>
                <h1 class="h4 mt-3"><?= h($config['app']['name']) ?></h1>
                <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
                <div class="stack">
                    <input class="form-control" name="user" placeholder="<?= h(t('login.user')) ?>" required autofocus>
                    <input class="form-control" name="password" type="password" placeholder="<?= h(t('login.password')) ?>" required>
                    <button class="btn btn-primary"><?= h(t('login.submit')) ?></button>
                </div>
            </div>
        </form>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <script src="node_modules/dashbrd/dist/assets/js/theme.bundle.js"></script>
    </body>

</html>