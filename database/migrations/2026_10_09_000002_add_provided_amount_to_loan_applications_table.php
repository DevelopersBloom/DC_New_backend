<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->decimal('provided_amount', 15, 2)->nullable()->after('final_estimate_id');
            $table->foreignId('provided_currency_id')->nullable()->after('provided_amount')->constrained('currencies');
            $table->text('provided_note')->nullable()->after('provided_currency_id');
            $table->timestamp('estimate_finalized_at')->nullable()->after('provided_note');
            $table->foreignId('estimate_finalized_by')->nullable()->after('estimate_finalized_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estimate_finalized_by');
            $table->dropConstrainedForeignId('provided_currency_id');
            $table->dropColumn(['provided_amount', 'provided_note', 'estimate_finalized_at']);
        });
    }
};
