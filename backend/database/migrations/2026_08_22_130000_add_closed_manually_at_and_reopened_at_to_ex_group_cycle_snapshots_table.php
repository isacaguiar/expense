<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ex_group_cycle_snapshots', function (Blueprint $table) {
            $table->timestamp('closed_manually_at')->nullable()->after('balances');
            $table->timestamp('reopened_at')->nullable()->after('closed_manually_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ex_group_cycle_snapshots', function (Blueprint $table) {
            $table->dropColumn(['closed_manually_at', 'reopened_at']);
        });
    }
};
