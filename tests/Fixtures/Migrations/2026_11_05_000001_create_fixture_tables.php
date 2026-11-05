<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->text('name')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('name')->nullable();
            $table->text('phone')->nullable();
            $table->text('code')->nullable();
            $table->bigInteger('country_id')->nullable();
            $table->timestampsTz(6);
            $table->index('country_id');
            $table->index('code');
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
            $table->text('billing_code')->nullable();
            $table->bigInteger('tenant_id')->nullable();
            $table->timestampTz('purchased_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('market_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->text('market_order_number');
            $table->text('order_signature')->nullable();
            $table->timestampsTz(6);
            $table->index('order_id');
            $table->index('order_signature');
        });

        Schema::create('parcels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->nullable();
            $table->text('reference')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz('deleted_at', 6);
            $table->index('order_id');
        });

        Schema::create('carrier_units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('parcel_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->text('tracking_number')->nullable();
            $table->timestampsTz(6);
            $table->index('parcel_id');
            $table->index('order_id');
        });

        Schema::create('ulid_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('code')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->text('name')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('order_tag', function (Blueprint $table): void {
            $table->uuid('order_id');
            $table->bigInteger('tag_id');
            $table->index('order_id');
            $table->index('tag_id');
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->text('noteable_type');
            $table->uuid('noteable_id');
            $table->text('body')->nullable();
            $table->timestampsTz(6);
            $table->index(['noteable_type', 'noteable_id']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->text('sku')->nullable();
            $table->text('ean')->nullable();
            $table->jsonb('name')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz('deleted_at', 6);
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
