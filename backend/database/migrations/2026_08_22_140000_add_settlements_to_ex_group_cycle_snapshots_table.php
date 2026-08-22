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
            $table->json('settlements')->nullable()->after('balances');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ex_group_cycle_snapshots', function (Blueprint $table) {
            $table->dropColumn('settlements');
        });
    }
};
