<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_support_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email');
            $table->string('access_token_hash', 64)->unique();
            $table->timestamps();
            $table->index('email');
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->foreignId('guest_support_conversation_id')->nullable()->after('business_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->change();
            $table->index(['guest_support_conversation_id', 'created_at'], 'support_guest_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropIndex('support_guest_created_index');
            $table->dropConstrainedForeignId('guest_support_conversation_id');
            $table->foreignId('business_id')->nullable(false)->change();
        });
        Schema::dropIfExists('guest_support_conversations');
    }
};
