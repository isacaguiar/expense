<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ex_users', function (Blueprint $table) {
            $table->string('pix')->nullable()->after('email');
        });
    }

    public function down()
    {
        Schema::table('ex_users', function (Blueprint $table) {
            $table->dropColumn('pix');
        });
    }

};
