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
        Schema::create('ex_group_cycle_snapshots', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');

            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->json('totals');
            $table->json('expenses');
            $table->json('balances');

            $table->timestamps();

            $table->unique(['group_id', 'cycle_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_group_cycle_snapshots');
    }
};
