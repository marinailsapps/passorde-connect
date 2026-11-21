<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passorde_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('status', 20)->default('queued');
            $table->date('window_from')->nullable();
            $table->date('window_to')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('rows_changed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['kind', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passorde_runs');
    }
};
