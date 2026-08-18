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
        Schema::create('ex_groups_members', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('user_id');

            $table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('ex_users')->onDelete('cascade');

            $table->primary(['group_id', 'user_id']); // chave composta
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_groups_members');
    }
};
