<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_journal', function (Blueprint $table) {
            // credit_partner_id
            if (!Schema::hasColumn('documents_journal', 'credit_partner_id')) {
                $table->foreignId('credit_partner_id')
                    ->nullable()
                    ->constrained('clients')
                    ->nullOnDelete()
                    ->after('partner_id'); // կամ ըստ քո աղյուսակի ճիշտ դաշտի
            }

            // debit_account_id
            if (!Schema::hasColumn('documents_journal', 'debit_account_id')) {
                $table->foreignId('debit_account_id')
                    ->nullable()
                    ->constrained('chart_of_accounts')
                    ->nullOnDelete()
                    ->after('credit_partner_id');
            }

            // credit_account_id
            if (!Schema::hasColumn('documents_journal', 'credit_account_id')) {
                $table->foreignId('credit_account_id')
                    ->nullable()
                    ->constrained('chart_of_accounts')
                    ->nullOnDelete()
                    ->after('debit_account_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents_journal', function (Blueprint $table) {
            if (Schema::hasColumn('documents_journal', 'credit_partner_id')) {
                $table->dropForeign(['credit_partner_id']);
                $table->dropColumn('credit_partner_id');
            }
            if (Schema::hasColumn('documents_journal', 'debit_account_id')) {
                $table->dropForeign(['debit_account_id']);
                $table->dropColumn('debit_account_id');
            }
            if (Schema::hasColumn('documents_journal', 'credit_account_id')) {
                $table->dropForeign(['credit_account_id']);
                $table->dropColumn('credit_account_id');
            }
        });
    }
};
