<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('ex_users', function (Blueprint $table) {
            $table->unsignedBigInteger('invited_by')->nullable()->after('id');

            $table->foreign('invited_by')
                  ->references('id')
                  ->on('ex_users')
                  ->nullOnDelete(); // ou cascadeOnDelete() se preferir
        });
    }

    public function down()
    {
        Schema::table('ex_users', function (Blueprint $table) {
            $table->dropForeign(['invited_by']);
            $table->dropColumn('invited_by');
        });
    }
};
