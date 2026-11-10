@php
    $money = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
    $issuer = $invoice->issuer ?? [];
    $recipient = $invoice->recipient ?? null;
    $rate = rtrim(rtrim(number_format((float) $invoice->vat_rate, 2, ',', ''), '0'), ',');
    $date = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->full_number }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1C1C1E; margin: 0; }
        .band { background: #007AFF; height: 8px; }
        .page { padding: 34px 44px 30px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .issuer-name { font-size: 17px; font-weight: bold; margin-bottom: 4px; }
        .muted { color: #6C6C70; }
        .doc-title { font-size: 22px; font-weight: bold; color: #007AFF; text-transform: uppercase; letter-spacing: 1px; text-align: right; }
        .doc-meta { width: auto; margin: 8px 0 0 auto; }
        .doc-meta td { padding: 2px 0 2px 12px; }
        .doc-meta .k { color: #6C6C70; text-align: right; }
        .doc-meta .v { font-weight: bold; text-align: right; }
        .box { border: 1px solid #D1D1D6; border-radius: 6px; padding: 10px 12px; margin-top: 22px; }
        .box-label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; color: #6C6C70; margin-bottom: 4px; }
        .lines { margin-top: 22px; }
        .lines th { background: #F2F2F7; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: .6px; color: #6C6C70; padding: 8px 10px; border-bottom: 1px solid #D1D1D6; }
        .lines td { padding: 10px; border-bottom: 1px solid #E5E5EA; vertical-align: top; }
        .right { text-align: right; }
        .totals { width: 46%; margin-left: 54%; margin-top: 14px; }
        .totals td { padding: 5px 10px; }
        .totals .grand td { border-top: 2px solid #007AFF; font-size: 14px; font-weight: bold; padding-top: 8px; }
        .notes { margin-top: 26px; font-size: 9px; color: #6C6C70; line-height: 1.5; }
        .draft { position: fixed; top: 38%; left: 0; width: 100%; text-align: center; font-size: 90px; color: rgba(220, 38, 38, .12); font-weight: bold; transform: rotate(-24deg); }
        .footer { position: fixed; bottom: 18px; left: 44px; right: 44px; font-size: 8.5px; color: #8E8E93; border-top: 1px solid #E5E5EA; padding-top: 6px; }
    </style>
</head>
<body>
<div class="band"></div>
@if ($draft)
    <div class="draft">BORRADOR</div>
@endif
<div class="page">
    <table class="head">
        <tr>
            <td style="width: 55%;">
                <div class="issuer-name">{{ $issuer['legal_name'] ?? '' }}</div>
                <div>NIF: {{ $issuer['tax_id'] ?? '' }}</div>
                <div>{{ $issuer['address_line'] ?? '' }}</div>
                <div>{{ $issuer['postal_code'] ?? '' }} {{ $issuer['city'] ?? '' }} ({{ $issuer['province'] ?? '' }})</div>
                @if (! empty($issuer['phone']) || ! empty($issuer['email']))
                    <div class="muted">{{ collect([$issuer['phone'] ?? null, $issuer['email'] ?? null])->filter()->join(' · ') }}</div>
                @endif
                @if (! empty($issuer['licence']))
                    <div class="muted">Licencia: {{ $issuer['licence'] }}</div>
                @endif
            </td>
            <td>
                <div class="doc-title">{{ $title }}</div>
                <table class="doc-meta">
                    <tr><td class="k">N.º de factura</td><td class="v">{{ $invoice->full_number }}</td></tr>
                    <tr><td class="k">Fecha de expedición</td><td class="v">{{ $date($invoice->issue_date) }}</td></tr>
                    @if ($invoice->operation_date && $date($invoice->operation_date) !== $date($invoice->issue_date))
                        <tr><td class="k">Fecha del servicio</td><td class="v">{{ $date($invoice->operation_date) }}</td></tr>
                    @endif
                    @if ($invoice->type === 'corrective' && $invoice->corrects)
                        <tr><td class="k">Rectifica a</td><td class="v">{{ $invoice->corrects->full_number }} ({{ $date($invoice->corrects->issue_date) }})</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if ($recipient)
        <div class="box">
            <div class="box-label">Cliente</div>
            <div style="font-weight: bold;">{{ $recipient['name'] ?? '' }}</div>
            @if (! empty($recipient['tax_id']))<div>NIF: {{ $recipient['tax_id'] }}</div>@endif
            @if (! empty($recipient['address_line']))<div>{{ $recipient['address_line'] }}</div>@endif
            @if (! empty($recipient['postal_code']) || ! empty($recipient['city']))
                <div>{{ $recipient['postal_code'] ?? '' }} {{ $recipient['city'] ?? '' }}@if (! empty($recipient['province'])) ({{ $recipient['province'] }})@endif</div>
            @endif
        </div>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th>Concepto</th>
                <th style="width: 18%;">Fecha del servicio</th>
                <th class="right" style="width: 18%;">Importe</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ $invoice->concept }}
                    @if ($ruta && $ruta->estimated_distance_km)
                        <div class="muted" style="margin-top: 3px;">Distancia aproximada: {{ number_format((float) $ruta->estimated_distance_km, 1, ',', '.') }} km</div>
                    @endif
                </td>
                <td>{{ $date($invoice->operation_date ?? $invoice->issue_date) }}</td>
                <td class="right">{{ $money($invoice->base) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Base imponible</td><td class="right">{{ $money($invoice->base) }}</td></tr>
        <tr><td>IVA ({{ $rate }} %)</td><td class="right">{{ $money($invoice->vat_amount) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="right">{{ $money($invoice->total) }}</td></tr>
    </table>

    <div class="notes">
        @if ($paymentLabel)
            <div><strong>Forma de pago:</strong> {{ $paymentLabel }}@if ($invoice->payment_method === 'no_pagado' && ! empty($issuer['iban'])) · Transferencia a {{ $issuer['iban'] }}@endif</div>
        @endif
        @if ($invoice->exemption_reason)
            <div><strong>Operación exenta de IVA:</strong> {{ $invoice->exemption_reason }}</div>
        @endif
        @if ($invoice->type === 'simplified')
            <div>Factura simplificada (art. 4 y 7 del Reglamento de facturación, RD 1619/2012). IVA incluido en el importe.</div>
        @endif
        @if ($invoice->type === 'corrective' && $invoice->correction_reason)
            <div><strong>Motivo de la rectificación:</strong> {{ $invoice->correction_reason }}</div>
        @endif
    </div>
</div>
<div class="footer">
    {{ $issuer['legal_name'] ?? '' }} · NIF {{ $issuer['tax_id'] ?? '' }} · Factura {{ $invoice->full_number }}
    <span style="float: right;">Emitida con Rutero</span>
</div>
</body>
</html>
