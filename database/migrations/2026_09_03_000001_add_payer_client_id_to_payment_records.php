<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // onto the money-movement records so it can be queried directly from each.
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedBigInteger('payer_client_id')->nullable()->after('client_id');
        });

        Schema::table('payment_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('payer_client_id')->nullable()->after('deal_id');
        });

        Schema::table('documents_journal', function (Blueprint $table) {
            $table->unsignedBigInteger('payer_client_id')->nullable()->after('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('payer_client_id');
        });
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropColumn('payer_client_id');
        });
        Schema::table('documents_journal', function (Blueprint $table) {
            $table->dropColumn('payer_client_id');
        });
    }
};
