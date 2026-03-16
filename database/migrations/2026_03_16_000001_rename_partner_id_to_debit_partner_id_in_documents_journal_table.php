<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_journal', function (Blueprint $table) {
            if (Schema::hasColumn('documents_journal', 'partner_id') && !Schema::hasColumn('documents_journal', 'debit_partner_id')) {
                $table->renameColumn('partner_id', 'debit_partner_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents_journal', function (Blueprint $table) {
            if (Schema::hasColumn('documents_journal', 'debit_partner_id') && !Schema::hasColumn('documents_journal', 'partner_id')) {
                $table->renameColumn('debit_partner_id', 'partner_id');
            }
        });
    }
};

