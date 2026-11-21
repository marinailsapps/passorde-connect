<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passorde\Licence\DatabaseLicenceStore: the slots current and previous, the key encrypted with APP_KEY.
        Schema::create('passorde_licences', function (Blueprint $table) {
            $table->string('slot', 20)->primary();
            $table->text('payload')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passorde_licences');
    }
};
