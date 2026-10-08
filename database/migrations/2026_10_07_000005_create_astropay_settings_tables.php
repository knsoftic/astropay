<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One merchant account per currency, managed in Admin → Settings.
        Schema::create('astropay_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 8)->unique();
            // Encrypted with APP_KEY.
            $table->text('merchant_key')->nullable();
            $table->text('secret_key')->nullable();
            $table->boolean('enabled')->default(false);
            $table->decimal('deposit_min', 20, 2)->nullable();
            $table->decimal('deposit_max', 20, 2)->nullable();
            $table->decimal('payout_min', 20, 2)->nullable();
            $table->decimal('payout_max', 20, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // API connection settings (base URL, callback base URL, webhook IPs).
        Schema::create('astropay_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('astropay_settings');
        Schema::dropIfExists('astropay_accounts');
    }
};
