<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ex_expenses_payers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_id');
            $table->unsignedBigInteger('user_id');

            $table->foreign('expense_id')->references('id')->on('ex_expenses')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('ex_users')->onDelete('cascade');

            $table->unique(['expense_id', 'user_id']); // evita duplicidade

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ex_expenses_payers');
    }
};
