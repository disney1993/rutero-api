<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación (Reglamento de facturación, RD 1619/2012):
 * - billing_profiles: datos fiscales del emisor, guardados una vez.
 * - invoice_clients: destinatarios reutilizables.
 * - invoices: facturas emitidas. Son inalterables: los datos del emisor y
 *   del destinatario se copian al emitir, la numeración es correlativa por
 *   serie y cada factura guarda la huella (hash) de la anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('issuer_type', 12)->default('autonomo'); // autonomo|sociedad
            $table->string('legal_name', 120)->nullable();
            $table->string('tax_id', 12)->nullable();
            $table->string('address_line', 150)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 40)->nullable();
            // passengers: transporte de viajeros (IVA 10 %, simplificada hasta 3.000 €)
            // goods: mercancías (IVA 21 %, simplificada hasta 400 €)
            $table->string('activity', 12)->default('passengers');
            $table->decimal('vat_rate', 4, 2)->default(10);
            $table->string('exemption_reason', 200)->nullable();
            $table->string('series', 10)->nullable();
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedInteger('next_corrective_number')->default(1);
            $table->string('licence', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('iban', 34)->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('tax_id', 12)->nullable();
            $table->string('address_line', 150)->nullable();
            $table->string('postal_code', 5)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'name']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ruta_id')->nullable()->constrained('rutas')->nullOnDelete();
            $table->string('series', 12);
            $table->unsignedInteger('number');
            $table->string('full_number', 24);
            $table->string('type', 12); // simplified|full|corrective
            $table->date('issue_date');
            $table->date('operation_date')->nullable();
            $table->json('issuer');
            $table->json('recipient')->nullable();
            $table->text('concept');
            $table->decimal('base', 10, 2);
            $table->decimal('vat_rate', 4, 2);
            $table->decimal('vat_amount', 10, 2);
            $table->decimal('total', 10, 2);
            $table->string('exemption_reason', 200)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('corrects_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('correction_reason', 200)->nullable();
            $table->char('hash', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'series', 'number']);
            $table->index('ruta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_clients');
        Schema::dropIfExists('billing_profiles');
    }
};
