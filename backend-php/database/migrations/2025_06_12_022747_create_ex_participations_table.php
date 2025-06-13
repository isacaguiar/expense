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
        Schema::create('ex_participations', function (Blueprint $table) {
            $table->id();
            $table->enum('state', ['PAID', 'PENDING']);

            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('quota_id');

            $table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');
            $table->foreign('quota_id')->references('id')->on('ex_quotas')->onDelete('cascade');

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_participations');
    }
};
