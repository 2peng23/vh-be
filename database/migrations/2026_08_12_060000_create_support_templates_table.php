<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100)->unique();
            $table->text('message');
            $table->timestamps();
        });
        $now = now();
        DB::table('support_templates')->insert([
            ['title' => 'Greeting', 'message' => 'Hello, how may I help you?', 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'Plan activated', 'message' => 'Your plan has been activated. Please click the link to open your dashboard: {{dashboard_url}}', 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'Renewal under review', 'message' => 'Thank you for contacting us. We are reviewing your plan renewal and will update you shortly.', 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'Request information', 'message' => 'To help us resolve this, please provide the relevant account details or attach a supporting document.', 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'Issue resolved', 'message' => 'Your request has been resolved. Please check your account and let us know if you need further assistance.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_templates');
    }
};
