<!doctype html>
<html lang="<?= h(current_locale()) ?>" data-theme-mode="auto" data-bs-theme="light">

    <?php render_partial('head', ['config' => $config, 'title' => $config['app']['name'], 'forceSystemTheme' => true]); ?>

    <body class="login-screen">
        <main class="login-viewport">
            <form class="login-language" aria-label="<?= h(t('common.language')) ?>">
                <label class="visually-hidden" for="login_locale"><?= h(t('common.language')) ?></label>
                <select
                    class="form-select form-select-sm"
                    id="login_locale"
                    onchange="if (this.value) window.location.href = this.value;"
                >
                    <?php foreach ($supportedLocales as $localeCode => $localeLabel): ?>
                    <option value="<?= h(locale_url($localeCode)) ?>" <?= current_locale() === $localeCode ? 'selected' : '' ?>>
                        <?= h(i18n_translate('language.iso2', [], strtoupper(substr($localeCode, 0, 2)), $localeCode)) ?> - <?= h($localeLabel) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="post" class="login-card card">
                <div class="card-body">
                    <input type="hidden" name="_action" value="login">
                    <div class="auth-logo-wrap">
                        <img
                            class="app-logo app-logo-auth"
                            src="/assets/khmsm_fulllogo_512.png"
                            alt="<?= h($config['app']['name']) ?>"
                        >
                    </div>
                    <?php if ($flash): ?>
                    <div class="alert <?= $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' ?>">
                        <?= h($flash['message']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="stack">
                        <input
                            class="form-control"
                            name="user"
                            placeholder="<?= h(t('login.user')) ?>"
                            required
                        >
                        <input
                            class="form-control"
                            name="password"
                            type="password"
                            placeholder="<?= h(t('login.password')) ?>"
                            required
                        >
                        <button class="btn btn-primary">
                            <?= h(t('login.submit')) ?>
                        </button>
                    </div>
                </div>
            </form>
        </main>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/dashbrd/dist/assets/js/theme.bundle.js"></script>
        <script>
            (() => {
                const query = window.matchMedia('(prefers-color-scheme: dark)');
                const apply = () => document.documentElement.setAttribute('data-bs-theme', query.matches ? 'dark' : 'light');
                apply();
                query.addEventListener('change', apply);
            })();
        </script>
    </body>

</html>
