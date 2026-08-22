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
        Schema::table('ex_users', function (Blueprint $table) {
            $table->string('whatsapp')->nullable()->after('pix');
            $table->boolean('notify_whatsapp')->default(false)->after('whatsapp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ex_users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'notify_whatsapp']);
        });
    }
};
