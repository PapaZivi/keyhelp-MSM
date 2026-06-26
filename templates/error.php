<!doctype html>
<html lang="<?= h(current_locale()) ?>">

    <?php render_partial('head', ['config' => $config, 'title' => t('error.title')]); ?>

    <body class="login-screen bg-light">
        <main class="login-card card">
            <div class="card-body">
                <div class="auth-logo-wrap"><img class="app-logo app-logo-auth" src="/assets/khmsm_fulllogo_512.png" alt="<?= h($config['app']['name']) ?>"></div>
                <h1 class="h4 text-center mt-3"><?= h(t('error.title')) ?></h1>
                <p class="text-center text-secondary mb-0"><?= h($message ?? t('message.app_failed')) ?></p>

            </div>
        </main>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
        <script src="https://cdn.jsdelivr.net/npm/dashbrd/dist/assets/js/theme.bundle.js"></script>
    </body>

</html>