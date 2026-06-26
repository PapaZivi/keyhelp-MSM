<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fehler</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-screen">
    <main class="login-card">
        <span class="brand-mark">K</span>
        <h1>Fehler</h1>
        <p><?= h($message ?? 'Die Anwendung konnte die Anfrage nicht verarbeiten. Details wurden ins Log geschrieben.') ?></p>
    </main>
</body>
</html>