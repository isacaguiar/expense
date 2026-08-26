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
        Schema::create('ex_settlement_confirmations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');

            $table->date('cycle_start');
            $table->date('cycle_end');

            $table->unsignedBigInteger('from_user_id');
            $table->foreign('from_user_id')->references('id')->on('ex_users')->onDelete('cascade');

            $table->unsignedBigInteger('to_user_id');
            $table->foreign('to_user_id')->references('id')->on('ex_users')->onDelete('cascade');

            $table->decimal('amount', 10, 2);
            $table->string('proof_path');
            $table->timestamp('confirmed_at');

            $table->timestamps();

            $table->unique(['group_id', 'cycle_start', 'from_user_id', 'to_user_id'], 'settlement_confirmation_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_settlement_confirmations');
    }
};
