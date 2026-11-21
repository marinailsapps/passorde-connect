<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passorde_requests', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('method', 10);
            $table->text('path');
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response')->nullable();
            $table->string('status', 20)->default('running')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->nullableMorphs('subject');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passorde_requests');
    }
};
