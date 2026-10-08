<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Store AstroPay's documented API base URL in the database so it can be
     * changed from Admin → Settings.
     */
    public function up(): void
    {
        if (DB::table('astropay_settings')->where('key', 'base_url')->exists()) {
            return;
        }

        DB::table('astropay_settings')->insert([
            'key' => 'base_url',
            'value' => json_encode('https://api.gpay.one'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('astropay_settings')->where('key', 'base_url')->where('value', json_encode('https://api.gpay.one'))->delete();
    }
};
