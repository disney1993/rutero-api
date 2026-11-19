<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $valid ? 'Email confirmado' : 'Enlace no válido' }} · Rutero</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: #F2F2F7; color: #1C1C1E; padding: 24px; box-sizing: border-box; }
        .card { width: 100%; max-width: 420px; background: #FFFFFF; border-radius: 20px; padding: 28px 24px;
                box-shadow: 0 6px 24px rgba(0, 0, 0, .08); text-align: center; }
        .brand { font-size: 28px; font-weight: 800; letter-spacing: -0.8px; margin: 0 0 18px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: #636366; line-height: 1.5; margin: 0; }
        @media (prefers-color-scheme: dark) {
            body { background: #000000; color: #F2F2F7; }
            .card { background: #1C1C1E; box-shadow: none; }
            p { color: #AEAEB2; }
        }
    </style>
</head>
<body>
    <main class="card">
        <p class="brand">Rutero</p>
        @if ($valid)
            <h1>Email confirmado</h1>
            <p>Ya puedes volver a la app. Si alguien te ha invitado a su equipo, verás la invitación al entrar.</p>
        @else
            <h1>Enlace no válido</h1>
            <p>Este enlace ha caducado o ya no corresponde a tu cuenta. Pide uno nuevo desde la app.</p>
        @endif
    </main>
</body>
</html>
