<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $headline ? "$headline · Rutero" : 'Invitación de Rutero' }}</title>
    @if ($headline)
        <meta property="og:title" content="{{ $headline }} · Rutero">
        <meta property="og:description" content="Abre el enlace en tu móvil para unirte como conductor.">
    @endif
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: #F2F2F7; color: #1C1C1E; padding: 24px; }
        .card { width: 100%; max-width: 420px; background: #FFFFFF; border-radius: 20px; padding: 28px 24px;
                box-shadow: 0 6px 24px rgba(0, 0, 0, .08); text-align: center; }
        .brand { font-size: 28px; font-weight: 800; letter-spacing: -0.8px; margin: 0 0 18px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: #636366; line-height: 1.5; margin: 0 0 18px; }
        .btn { display: block; width: 100%; padding: 14px; border-radius: 14px; font-size: 16px; font-weight: 600;
               text-decoration: none; margin-bottom: 10px; }
        .primary { background: #0A84FF; color: #FFFFFF; }
        .secondary { background: #E5E5EA; color: #1C1C1E; }
        .code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 26px; font-weight: 700;
                letter-spacing: 4px; padding: 12px; border: 2px dashed #C7C7CC; border-radius: 12px; margin: 6px 0 18px; }
        .small { font-size: 13px; }
        @media (prefers-color-scheme: dark) {
            body { background: #000000; color: #F2F2F7; }
            .card { background: #1C1C1E; box-shadow: none; }
            p { color: #AEAEB2; }
            .secondary { background: #2C2C2E; color: #F2F2F7; }
            .code { border-color: #48484A; }
        }
    </style>
</head>
<body>
<main class="card">
    <p class="brand">Rutero</p>
    @if ($invite)
        <h1>{{ $headline }}</h1>
        <p>Únete como conductor de su equipo y verás en Rutero los viajes que te asigne.</p>
        <a id="open" class="btn primary" href="{{ $appUrl }}" data-intent="{{ $intentUrl }}">Abrir en Rutero</a>
        @if ($downloadUrl)
            <a class="btn secondary" href="{{ $downloadUrl }}">Descargar Rutero</a>
        @endif
        <p class="small">Si ya tienes la app abierta y no se abre sola, escribe este código en «¿Tienes un código de invitación?» (Inicio) o en el campo «Código» de Rutas:</p>
        <div class="code">{{ $code }}</div>
        <p class="small">Si aún no tienes cuenta, al abrir la app y registrarte te unirás al equipo automáticamente.</p>
    @else
        <h1>Invitación no válida</h1>
        <p>Este enlace ya se ha usado o ha caducado. Pide a quien te lo envió una invitación nueva.</p>
    @endif
</main>
@if ($invite)
<script>
    (function () {
        var link = document.getElementById('open');
        // En Android se usa el intent de Chrome, que abre la app si está instalada.
        if (/Android/i.test(navigator.userAgent)) link.href = link.getAttribute('data-intent');
        // Intento automático de abrir la app al cargar la página en el móvil.
        if (/Android|iPhone|iPad/i.test(navigator.userAgent)) {
            setTimeout(function () { window.location.href = link.href; }, 300);
        }
    })();
</script>
@endif
</body>
</html>
