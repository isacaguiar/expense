<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ex_quotas', function (Blueprint $table) {
            $table->boolean('born_paid')->default(false)->after('paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('ex_quotas', function (Blueprint $table) {
            $table->dropColumn('born_paid');
        });
    }
};
