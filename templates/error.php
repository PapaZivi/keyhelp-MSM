<!doctype html>
<html lang="<?= h(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(t('error.title')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="node_modules/dashbrd/dist/assets/css/theme.bundle.css">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-screen bg-light">
    <main class="login-card card">
        <div class="card-body">
            <span class="brand-mark">K</span>
            <h1 class="h4 mt-3"><?= h(t('error.title')) ?></h1>
            <p class="mb-0"><?= h($message ?? t('message.app_failed')) ?></p>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script><script src="node_modules/dashbrd/dist/assets/js/theme.bundle.js"></script>
</body>
</html>
