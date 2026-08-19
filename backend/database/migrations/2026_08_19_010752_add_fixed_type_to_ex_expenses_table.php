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
        Schema::table('ex_expenses', function (Blueprint $table) {
            $table->date('fixed_recurrence_ends_at')->nullable()->after('installments');
        });

        // doctrine/dbal não está instalado — Schema::table()->change() não funciona em coluna enum.
        DB::statement("ALTER TABLE ex_expenses MODIFY expense_type ENUM('IN_CASH','IN_INSTALLMENTS','FIXED') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE ex_expenses MODIFY expense_type ENUM('IN_CASH','IN_INSTALLMENTS') NOT NULL");

        Schema::table('ex_expenses', function (Blueprint $table) {
            $table->dropColumn('fixed_recurrence_ends_at');
        });
    }
};
