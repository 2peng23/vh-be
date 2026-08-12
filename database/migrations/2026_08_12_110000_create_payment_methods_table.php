<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Create the platform payment methods presented to subscribing businesses. */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('account_number', 150);
            $table->string('qr_path')->nullable();
            $table->string('qr_name')->nullable();
            $table->timestamps();
        });
    }

    /** Remove the platform payment methods table. */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
