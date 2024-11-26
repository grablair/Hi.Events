<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', static function (Blueprint $table) {
            $table->integer('ticket_limit_per_use')->nullable()->default(null);
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', static function (Blueprint $table) {
            $table->dropColumn('ticket_limit_per_use');
        });
    }
};