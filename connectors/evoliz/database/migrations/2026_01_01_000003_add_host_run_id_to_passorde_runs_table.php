<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passorde_runs', function (Blueprint $table) {
            // The host's own id for the job or run that asked for this one, when it gives one.
            $table->unsignedBigInteger('host_run_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('passorde_runs', function (Blueprint $table) {
            $table->dropIndex(['host_run_id']);
            $table->dropColumn('host_run_id');
        });
    }
};
