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
                <p style="margin:0 0 12px; font-size:17px; font-weight:600;">{{ $ownerName }} te invita a conducir en su equipo</p>
                <p style="margin:0 0 12px; line-height:1.5; color:#3A3A3C;">
                    Si aceptas, verás en Rutero los viajes que te asigne y podrás registrar cómo se han cobrado.
                </p>
                <ol style="margin:0 0 16px; padding-left:20px; line-height:1.6; color:#3A3A3C;">
                    <li>Instala Rutero en tu móvil.</li>
                    <li>Inicia sesión o regístrate con este email: <strong>{{ $email }}</strong></li>
                    <li>En la pantalla de Inicio verás la invitación: pulsa <strong>Aceptar</strong>.</li>
                </ol>
                @if ($downloadUrl)
                    <p style="margin:0 0 20px;"><a href="{{ $downloadUrl }}" style="display:inline-block; background:#007AFF; color:#FFFFFF; text-decoration:none; padding:12px 20px; border-radius:12px; font-weight:600;">Descargar Rutero</a></p>
                @endif
            </td></tr>
            <tr><td style="padding:0 28px 24px; font-size:12px; color:#8E8E93; line-height:1.5;">
                Si no esperabas esta invitación, puedes ignorar este correo: nadie entrará en tu cuenta ni verás nada hasta que la aceptes.
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
