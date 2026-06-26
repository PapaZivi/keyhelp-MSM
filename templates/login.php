<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($config['app']['name']) ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-screen">
    <form method="post" class="login-card">
        <input type="hidden" name="_action" value="login">
        <span class="brand-mark">K</span>
        <h1><?= h($config['app']['name']) ?></h1>
        <?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <input name="user" placeholder="Benutzer" required autofocus>
        <input name="password" type="password" placeholder="Passwort" required>
        <button class="primary">Einloggen</button>
    </form>
</body>
</html>