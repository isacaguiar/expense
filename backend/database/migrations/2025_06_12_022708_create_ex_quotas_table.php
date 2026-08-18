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
        Schema::create('ex_quotas', function (Blueprint $table) {
            $table->id();
            $table->date('date_expected');
            $table->integer('number');
            $table->boolean('paid')->default(false);
            $table->decimal('value_quota', 38, 2);

            $table->unsignedBigInteger('expense_id');
            $table->foreign('expense_id')->references('id')->on('ex_expenses')->onDelete('cascade');

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_quotas');
    }
};
