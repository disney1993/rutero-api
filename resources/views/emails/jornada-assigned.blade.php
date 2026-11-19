<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0; background:#F2F2F7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; color:#1C1C1E;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background:#FFFFFF; border-radius:16px; overflow:hidden;">
            <tr><td style="background:#007AFF; height:6px;"></td></tr>
            <tr><td style="padding:28px 28px 8px;">
                <p style="margin:0 0 16px; font-size:24px; font-weight:800; letter-spacing:-0.6px;">Rutero</p>
                <p style="margin:0 0 12px; font-size:17px; font-weight:600;">{{ $ownerName }} te ha asignado una jornada</p>
                <p style="margin:0 0 12px; line-height:1.5; color:#3A3A3C;">
                    <strong>{{ $label }}</strong> · {{ $date }}
                </p>
                <p style="margin:0 0 20px; line-height:1.5; color:#3A3A3C;">
                    Abre Rutero para ver sus viajes. En la pantalla de Inicio puedes marcar el aviso como visto.
                </p>
            </td></tr>
            <tr><td style="padding:0 28px 24px; font-size:12px; color:#8E8E93; line-height:1.5;">
                Recibes este correo porque formas parte del equipo de {{ $ownerName }} en Rutero.
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
