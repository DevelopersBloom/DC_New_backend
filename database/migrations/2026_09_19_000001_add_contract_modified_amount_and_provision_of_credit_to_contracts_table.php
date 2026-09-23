<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('contract_modified_amount', 15, 2)->nullable()->after('contract_amount');
            $table->boolean('provision_of_credit')->default(false)->after('loan_use_purpose');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['contract_modified_amount', 'provision_of_credit']);
        });
    }
};
