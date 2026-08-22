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
        Schema::table('ex_quotas', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('paid');
            $table->unsignedBigInteger('paid_by')->nullable()->after('paid_at');
            $table->foreign('paid_by')->references('id')->on('ex_users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ex_quotas', function (Blueprint $table) {
            $table->dropForeign(['paid_by']);
            $table->dropColumn(['paid_at', 'paid_by']);
        });
    }
};
