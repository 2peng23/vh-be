<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('email');
            $t->string('phone')->nullable();
            $t->string('industry')->nullable();
            $t->text('address')->nullable();
            $t->string('logo')->nullable();
            $t->string('timezone')->default('Asia/Manila');
            $t->string('currency', 3)->default('PHP');
            $t->string('mileage_unit')->default('km');
            $t->string('subscription_plan')->default('trial');
            $t->string('subscription_status')->default('active');
            $t->timestamp('plan_started_at')->nullable();
            $t->timestamp('plan_ends_at')->nullable();
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('password');
            $t->string('role')->default('driver')->index();
            $t->string('status')->default('active');
            $t->string('profile_photo')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->rememberToken();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['business_id', 'email']);
        });
        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('businesses');
    }
};
