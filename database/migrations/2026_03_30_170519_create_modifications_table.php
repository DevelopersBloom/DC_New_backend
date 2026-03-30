<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('modifications', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('subject');

            // 'ANNUAL_INTEREST_RATE', 'CONTRACT_MODIFIED_AMOUNT', 'CONTRACT_TYPE', 'DATA_DELETE'
            $table->string('modification_type', 100);
            // 'LastExpirationDate', 'Notes'
            $table->string('field_code', 100)->nullable();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->date('effective_date')->nullable();

            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'modification_type'], 'mod_subject_type_id_type_idx');
            $table->index(['is_sent', 'effective_date'], 'mod_is_sent_effective_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('contract_modifications');
    }
};
