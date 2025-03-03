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
            $table->renameColumn('ticket_limit_per_use', 'product_limit_per_use');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', static function (Blueprint $table) {
            $table->renameColumn('product_limit_per_use', 'ticket_limit_per_use');
        });
    }
};