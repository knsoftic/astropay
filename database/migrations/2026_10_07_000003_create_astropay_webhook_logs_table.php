<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('astropay_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained('astropay_transactions')->nullOnDelete();
            $table->string('type', 16);
            $table->string('currency', 8);
            $table->string('order_id', 64)->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('content_type', 191)->nullable();
            $table->json('payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('astropay_webhook_logs');
    }
};
