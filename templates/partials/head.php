<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title ?? $config['app']['name']) ?></title>
    <link rel="icon" type="image/png" sizes="128x128" href="/assets/khmsm_logo_128.png">
    <link rel="shortcut icon" type="image/png" href="/assets/khmsm_logo_128.png">
    <link rel="apple-touch-icon" sizes="128x128" href="/assets/khmsm_logo_128.png">
    <meta name="msapplication-TileImage" content="/assets/khmsm_logo_128.png">
    <meta name="msapplication-TileColor" content="#1d5fbf">
    <meta name="theme-color" content="#1d5fbf">
    <?php if (($forceSystemTheme ?? false) === true): ?>
    <script>
        (() => {
            const query = window.matchMedia('(prefers-color-scheme: dark)');
            document.documentElement.setAttribute('data-bs-theme', query.matches ? 'dark' : 'light');
        })();
    </script>
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/dashbrd/dist/assets/css/theme.bundle.css">
    <link rel="stylesheet" href="/assets/app.css?v=<?= (int)filemtime(dirname(__DIR__, 2) . '/public/assets/app.css') ?>">
</head>
