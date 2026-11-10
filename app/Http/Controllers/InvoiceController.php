<?php

namespace App\Http\Controllers;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\InvoiceClient;
use App\Models\Ruta;
use App\Models\User;
use App\Support\SpanishTaxId;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Facturas de los viajes (RD 1619/2012):
 * - Simplificada (art. 7) por defecto hasta el límite del art. 4 (3.000 € en
 *   transporte de viajeros, 400 € en el resto); completa (art. 6) por encima
 *   o si el cliente necesita sus datos para deducirse el IVA.
 * - Numeración correlativa por serie, sin huecos, asignada en transacción.
 * - Inalterables: los errores se corrigen con una rectificativa (art. 15).
 * El PDF se genera a partir de los datos guardados, así que no se almacenan
 * ficheros (el disco de Laravel Cloud no es persistente).
 */
class InvoiceController extends Controller
{
    private const PAYMENT_LABELS = [
        'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'bizum' => 'Bizum', 'no_pagado' => 'Pendiente de pago',
    ];

    /** Factura vigente de un viaje (o null). */
    public function forRuta(Request $request, Ruta $ruta): JsonResponse
    {
        if (! $this->ownsRuta($request->user(), $ruta)) {
            return response()->json([], 403);
        }

        return response()->json(['invoice' => Invoice::where('ruta_id', $ruta->id)->current()->latest('id')->first()]);
    }

    public function store(Request $request, Ruta $ruta)
    {
        $owner = $request->user();
        if (! $this->ownsRuta($owner, $ruta)) {
            return response()->json([], 403);
        }

        $profile = BillingProfile::where('user_id', $owner->id)->first();
        if (! $profile || ! $profile->isReady()) {
            return response()->json([
                'message' => 'Completa tus datos de facturación antes de emitir facturas.',
                'missing' => $profile ? $profile->missing() : BillingProfile::REQUIRED,
            ], 422);
        }
        if ($ruta->trashed() || $ruta->status !== 'completed') {
            return response()->json(['message' => 'Solo se pueden facturar viajes completados.'], 422);
        }
        $existing = Invoice::where('ruta_id', $ruta->id)->current()->first();
        if ($existing) {
            return response()->json(['message' => 'Este viaje ya tiene una factura.', 'invoice' => $existing], 409);
        }

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['simplified', 'full'])],
            'concept' => ['required', 'string', 'min:3', 'max:500'],
            'total' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'recipient' => ['sometimes', 'nullable', 'array'],
            'recipient.name' => ['nullable', 'string', 'max:120'],
            'recipient.tax_id' => ['nullable', 'string', 'max:15'],
            'recipient.address_line' => ['nullable', 'string', 'max:150'],
            'recipient.postal_code' => ['nullable', 'string', 'max:5'],
            'recipient.city' => ['nullable', 'string', 'max:80'],
            'recipient.province' => ['nullable', 'string', 'max:40'],
            'recipient.email' => ['nullable', 'email:rfc', 'max:120'],
            'save_client' => ['sometimes', 'boolean'],
            'preview' => ['sometimes', 'boolean'],
        ], ['total.gt' => 'El importe debe ser mayor que 0.']);

        $total = round((float) $data['total'], 2);
        $limit = $profile->simplifiedLimit();
        $type = $data['type'] ?? ($total <= $limit ? 'simplified' : 'full');
        if ($type === 'simplified' && $total > $limit) {
            throw ValidationException::withMessages(['type' => [
                "Por encima de {$limit} € hay que emitir factura completa, con los datos del cliente.",
            ]]);
        }
        $recipient = $this->validatedRecipient($data['recipient'] ?? null, $type);

        $amounts = $this->amounts($total, (float) $profile->vat_rate);
        $attrs = [
            'owner_id' => $owner->id,
            'ruta_id' => $ruta->id,
            'type' => $type,
            'issue_date' => now(config('rutero.auto_complete.timezone'))->toDateString(),
            'operation_date' => $ruta->trip_date?->toDateString(),
            'issuer' => $profile->snapshot(),
            'recipient' => $recipient,
            'concept' => trim($data['concept']),
            'vat_rate' => (float) $profile->vat_rate,
            'exemption_reason' => (float) $profile->vat_rate === 0.0 ? $profile->exemption_reason : null,
            'payment_method' => $ruta->payment_method,
        ] + $amounts;

        // Vista previa: mismo PDF, sin número ni registro.
        if ($request->boolean('preview')) {
            $draft = new Invoice($attrs + ['series' => $profile->series, 'number' => 0, 'full_number' => 'BORRADOR']);
            $draft->setRelation('ruta', $ruta);

            return $this->pdfResponse($draft, true);
        }

        $invoice = DB::transaction(function () use ($owner, $attrs, $recipient, $data) {
            // Bloqueo del perfil: dos emisiones a la vez no pueden repetir número.
            $profile = BillingProfile::where('user_id', $owner->id)->lockForUpdate()->first();

            return $this->issue($owner, $profile, $attrs + [
                'series' => $profile->series,
                'number' => $profile->next_number,
                'full_number' => $profile->series.str_pad((string) $profile->next_number, 4, '0', STR_PAD_LEFT),
            ], function () use ($profile) {
                $profile->increment('next_number');
            }, $recipient && ! empty($data['save_client']) ? $recipient : null);
        });

        return response()->json($invoice, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::where('owner_id', $request->user()->id)
            ->with('correctedBy:id,corrects_invoice_id,full_number')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'ruta_id', 'full_number', 'type', 'issue_date', 'total', 'recipient', 'corrects_invoice_id']);

        return response()->json($invoices);
    }

    public function pdf(Request $request, Invoice $invoice)
    {
        if ($invoice->owner_id !== $request->user()->id) {
            return response()->json([], 403);
        }

        return $this->pdfResponse($invoice->load('ruta', 'corrects'));
    }

    /** Rectificativa por el importe total (anula la factura): serie propia "R". */
    public function corrective(Request $request, Invoice $invoice): JsonResponse
    {
        $owner = $request->user();
        if ($invoice->owner_id !== $owner->id) {
            return response()->json([], 403);
        }
        if ($invoice->type === 'corrective' || $invoice->correctedBy()->exists()) {
            return response()->json(['message' => 'Esta factura ya está rectificada o es una rectificativa.'], 422);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        $corrective = DB::transaction(function () use ($owner, $invoice, $data) {
            $profile = BillingProfile::where('user_id', $owner->id)->lockForUpdate()->firstOrFail();
            $series = 'R'.$profile->series;

            return $this->issue($owner, $profile, [
                'owner_id' => $owner->id,
                'ruta_id' => $invoice->ruta_id,
                'series' => $series,
                'number' => $profile->next_corrective_number,
                'full_number' => $series.str_pad((string) $profile->next_corrective_number, 4, '0', STR_PAD_LEFT),
                'type' => 'corrective',
                'issue_date' => now(config('rutero.auto_complete.timezone'))->toDateString(),
                'operation_date' => $invoice->operation_date?->toDateString(),
                'issuer' => $profile->snapshot(),
                'recipient' => $invoice->recipient,
                'concept' => "Rectificación de la factura {$invoice->full_number}: ".trim($data['reason']),
                'base' => -$invoice->base,
                'vat_rate' => $invoice->vat_rate,
                'vat_amount' => -$invoice->vat_amount,
                'total' => -$invoice->total,
                'exemption_reason' => $invoice->exemption_reason,
                'payment_method' => $invoice->payment_method,
                'corrects_invoice_id' => $invoice->id,
                'correction_reason' => trim($data['reason']),
            ], function () use ($profile) {
                $profile->increment('next_corrective_number');
            });
        });

        return response()->json($corrective, 201);
    }

    /** Destinatarios guardados, para autocompletar. */
    public function clients(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        return response()->json(
            InvoiceClient::where('owner_id', $request->user()->id)
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', '%'.SpanishTaxId::normalize($search).'%')))
                ->orderBy('name')
                ->limit(10)
                ->get()
        );
    }

    // ----------------------------------------------------------------- helpers

    private function ownsRuta(?User $user, Ruta $ruta): bool
    {
        return $user && ($ruta->owner_id === $user->id);
    }

    /** Base imponible y cuota a partir del total cobrado (IVA incluido), cuadrando al céntimo. */
    private function amounts(float $total, float $rate): array
    {
        $base = round($total / (1 + $rate / 100), 2);

        return ['base' => $base, 'vat_amount' => round($total - $base, 2), 'total' => $total];
    }

    private function validatedRecipient(?array $r, string $type): ?array
    {
        $r = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $r ?? []);
        $r = array_filter($r, fn ($v) => $v !== null && $v !== '');
        if ($type === 'simplified' && $r === []) {
            return null;
        }

        $errors = [];
        $needsFull = $type === 'full' || ! empty($r['tax_id']);
        if ($needsFull) {
            // Art. 6 (completa) o art. 7.2 (simplificada con datos para deducir el IVA).
            foreach (['name' => 'el nombre o razón social', 'tax_id' => 'el NIF', 'address_line' => 'la dirección', 'postal_code' => 'el código postal', 'city' => 'la población'] as $field => $label) {
                if (empty($r[$field])) {
                    $errors["recipient.$field"] = "Falta {$label} del cliente.";
                }
            }
        }
        if (! empty($r['tax_id']) && ! SpanishTaxId::isValid($r['tax_id'])) {
            $errors['recipient.tax_id'] = 'El NIF del cliente no es válido.';
        }
        if (! empty($r['postal_code']) && ! SpanishTaxId::isValidPostalCode($r['postal_code'])) {
            $errors['recipient.postal_code'] = 'El código postal del cliente no es válido.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (! empty($r['tax_id'])) {
            $r['tax_id'] = SpanishTaxId::normalize($r['tax_id']);
        }

        return $r ?: null;
    }

    /** Crea la factura encadenando su huella con la anterior del propietario. */
    private function issue(User $owner, BillingProfile $profile, array $attrs, callable $advanceNumber, ?array $saveClient = null): Invoice
    {
        $previous = Invoice::where('owner_id', $owner->id)->orderByDesc('id')->lockForUpdate()->value('hash');
        $attrs['previous_hash'] = $previous;
        $attrs['hash'] = Invoice::computeHash($attrs['issuer']['tax_id'], $attrs['full_number'], $attrs['issue_date'], (float) $attrs['total'], $previous);

        $invoice = Invoice::create($attrs);
        $advanceNumber();

        if ($saveClient && ! empty($saveClient['name'])) {
            InvoiceClient::updateOrCreate(
                ['owner_id' => $owner->id, 'tax_id' => $saveClient['tax_id'] ?? null, 'name' => $saveClient['name']],
                array_intersect_key($saveClient, array_flip(['address_line', 'postal_code', 'city', 'province', 'email']))
            );
        }

        return $invoice;
    }

    private function pdfResponse(Invoice $invoice, bool $draft = false)
    {
        $titles = ['simplified' => 'Factura simplificada', 'full' => 'Factura', 'corrective' => 'Factura rectificativa'];
        $pdf = Pdf::loadView('invoices.invoice', [
            'invoice' => $invoice,
            'title' => $titles[$invoice->type] ?? 'Factura',
            'draft' => $draft,
            'paymentLabel' => self::PAYMENT_LABELS[$invoice->payment_method] ?? null,
            'ruta' => $invoice->ruta,
        ])->setPaper('a4');
        $name = $draft ? 'factura-borrador.pdf' : 'factura-'.preg_replace('/[^A-Za-z0-9\-]/', '-', $invoice->full_number).'.pdf';

        return $pdf->download($name);
    }
}
