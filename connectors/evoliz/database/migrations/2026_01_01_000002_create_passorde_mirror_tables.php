<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passorde_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_id')->unique();
            $table->string('code')->nullable()->index();
            $table->string('name')->nullable();
            $table->text('address_line')->nullable();
            $table->string('postcode', 32)->nullable();
            $table->string('town')->nullable();
            $table->string('country_iso2', 8)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('mobile', 64)->nullable();
            $table->string('vat_number', 64)->nullable();
            $table->boolean('enabled')->default(true);
            $this->mirrorColumns($table);
        });

        Schema::create('passorde_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_id')->unique();
            $table->unsignedBigInteger('client_evoliz_id')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('favourite')->default(false);
            $table->boolean('enabled')->default(true);
            $this->mirrorColumns($table);
        });

        Schema::create('passorde_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_id')->unique();
            $table->string('reference')->nullable()->index();
            $table->text('designation')->nullable();
            $table->text('designation_raw')->nullable();
            $table->decimal('unit_price_ht', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->unsignedBigInteger('classification_id')->nullable();
            $table->string('classification_code')->nullable();
            $table->string('supplier_name')->nullable();
            $table->boolean('stock_managed')->default(false);
            $table->decimal('stock_qty', 14, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $this->mirrorColumns($table);
        });

        Schema::create('passorde_paytypes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_id')->unique();
            $table->string('label')->nullable();
            $this->mirrorColumns($table);
        });

        Schema::create('passorde_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_id')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $this->mirrorColumns($table);
        });

        Schema::create('passorde_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evoliz_id')->unique();
            $table->string('number')->nullable()->index();
            $table->string('external_number')->nullable();
            $table->unsignedBigInteger('client_evoliz_id')->nullable()->index();
            $table->string('client_name')->nullable();
            $table->unsignedBigInteger('user_evoliz_id')->nullable();
            $table->integer('status_code')->nullable();
            $table->string('status', 64)->nullable();
            $table->boolean('invoiced')->default(false);
            $table->date('document_date')->nullable()->index();
            $table->decimal('total_ht', 14, 2)->default(0);
            $table->decimal('total_vat', 14, 2)->default(0);
            $table->decimal('total_ttc', 14, 2)->default(0);
            $table->decimal('total_quantity', 14, 2)->default(0);
            $table->text('comment')->nullable();
            $table->char('lines_hash', 64)->nullable();
            $this->mirrorColumns($table);
            $table->softDeletes();
        });

        Schema::create('passorde_delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('passorde_deliveries')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('article_evoliz_id')->nullable()->index();
            $table->string('reference')->nullable();
            $table->text('designation')->nullable();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->decimal('unit_price_ht', 14, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('total_ht', 14, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['delivery_id', 'position']);
        });
    }

    public function down(): void
    {
        foreach (['delivery_lines', 'deliveries', 'users', 'paytypes', 'articles', 'contacts', 'clients'] as $table) {
            Schema::dropIfExists('passorde_'.$table);
        }
    }

    private function mirrorColumns(Blueprint $table): void
    {
        $table->char('row_hash', 64);
        $table->timestamp('synced_at')->nullable();
        $table->boolean('is_active')->default(true)->index();
        $table->timestamps();
    }
};
