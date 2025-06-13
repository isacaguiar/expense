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
        Schema::create('ex_expenses', function (Blueprint $table) {
            $table->id();
            $table->timestamp('create_date')->nullable();
            $table->date('date_payment');
            $table->string('description', 255);

            $table->enum('expense_type', ['IN_CASH', 'IN_INSTALLMENTS']);
            $table->integer('installments')->default(1);
            $table->decimal('total_value', 38, 2);

            

            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('user_creator_id');
            $table->unsignedBigInteger('user_payer_id');

            $table->foreign('group_id')->references('id')->on('ex_groups');
            $table->foreign('user_creator_id')->references('id')->on('ex_users');
            $table->foreign('user_payer_id')->references('id')->on('ex_users');
            
            $table->boolean('deleted')->default(false);
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_expenses');
    }
};
