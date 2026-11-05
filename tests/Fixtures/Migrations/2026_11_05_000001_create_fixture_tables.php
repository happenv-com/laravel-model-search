<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('name')->nullable();
            $table->text('phone')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('signature');
            $table->text('external_number')->nullable();
            $table->text('customer_email')->nullable();
            $table->text('customer_name')->nullable();
            $table->text('state')->default('unshipped');
            $table->uuid('billing_address_id')->nullable();
            $table->uuid('shipping_address_id')->nullable();
            $table->bigInteger('tenant_id')->nullable();
            $table->timestampTz('purchased_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('market_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('market_order_number');
            $table->timestampsTz(6);
            $table->index('order_id');
        });

        Schema::create('parcels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->nullable();
            $table->text('reference')->nullable();
            $table->timestampsTz(6);
            $table->index('order_id');
        });

        Schema::create('carrier_units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('parcel_id');
            $table->text('tracking_number')->nullable();
            $table->timestampsTz(6);
            $table->index('parcel_id');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->text('sku')->nullable();
            $table->text('ean')->nullable();
            $table->jsonb('name')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz(6);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('number');
            $table->text('external_reference')->nullable();
            $table->uuid('order_id')->nullable();
            $table->timestampsTz(6);
            $table->index('order_id');
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->text('name')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->timestampsTz(6);
        });
    }
};
