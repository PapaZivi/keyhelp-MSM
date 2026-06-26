<!doctype html>
<html lang="<?= h(current_locale()) ?>">

    <?php render_partial('head', ['config' => $config, 'title' => $config['app']['name']]); ?>

    <body class="login-screen bg-light">
        <nav class="language-switch login-language btn-group btn-group-sm" aria-label="<?= h(t('common.language')) ?>">
            <?php foreach ($supportedLocales as $localeCode => $localeLabel): ?>
            <a class="btn <?= current_locale() === $localeCode ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h(locale_url($localeCode)) ?>" hreflang="<?= h($localeCode) ?>" title="<?= h($localeLabel) ?>" aria-label="<?= h($localeLabel) ?>"><?= h(i18n_translate('language.iso2', [], strtoupper(substr($localeCode, 0, 2)), $localeCode)) ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="post" class="login-card card">
            <div class="card-body">
                <input type="hidden" name="_action" value="login">
                <div class="auth-logo-wrap"><img class="app-logo app-logo-auth" src="/assets/khmsm_fulllogo_512.png" alt="<?= h($config['app']['name']) ?>"></div>
                <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
                <div class="stack">
                    <input class="form-control" name="user" placeholder="<?= h(t('login.user')) ?>" required autofocus>
                    <input class="form-control" name="password" type="password" placeholder="<?= h(t('login.password')) ?>" required>
                    <button class="btn btn-primary"><?= h(t('login.submit')) ?></button>
                </div>
            </div>
        </form>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/dashbrd/dist/assets/js/theme.bundle.js"></script>
    </body>

</html>