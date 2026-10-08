<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('astropay_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 16);
            $table->string('currency', 8);
            $table->string('payment_method', 16)->nullable();

            // Merchant-generated orderId sent to AstroPay.
            $table->string('order_id', 64)->unique();
            // Client-supplied key that stops a double-submitted form creating two orders.
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->decimal('amount', 20, 4);
            $table->decimal('commission', 20, 4)->nullable();
            $table->decimal('net_amount', 20, 4)->nullable();
            $table->decimal('settled_amount', 20, 4)->nullable();

            $table->string('status', 24)->index();
            $table->unsignedTinyInteger('gateway_status')->nullable();
            $table->boolean('is_test')->default(false);

            // Deposit
            $table->text('pay_url')->nullable();
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_email', 191)->nullable();

            // Payout beneficiary (account and phone are encrypted at rest)
            $table->text('beneficiary_account')->nullable();
            $table->string('beneficiary_bank_code', 20)->nullable();
            $table->text('beneficiary_phone')->nullable();
            $table->string('beneficiary_name', 100)->nullable();

            $table->string('utr', 64)->nullable()->index();
            $table->string('supplemented_utr', 64)->nullable();
            $table->text('remark')->nullable();

            $table->integer('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->boolean('needs_review')->default(false)->index();
            $table->text('review_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            // createTime/updateTime as reported by AstroPay (account's regional time zone).
            $table->string('gateway_create_time', 32)->nullable();
            $table->string('gateway_update_time', 32)->nullable();

            $table->json('last_callback')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('callback_received_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'created_at']);
            $table->index(['type', 'status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('astropay_transactions');
    }
};
