<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ex_group_cycle_snapshots', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('reopened_at');
        });

        // Toda linha que já existe representa, no modelo anterior, um ciclo já
        // imutável (fotografado por data na 1ª leitura, ou fechado manualmente e
        // não mais recalculado). No modelo novo, "selado" = totalmente quitado e
        // congelado — tratar o que já existe como selado desde já.
        DB::table('ex_group_cycle_snapshots')
            ->whereNull('settled_at')
            ->update(['settled_at' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ex_group_cycle_snapshots', function (Blueprint $table) {
            $table->dropColumn('settled_at');
        });
    }
};
