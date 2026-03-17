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
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('currency_id')
                ->nullable()
                ->after('client_id')
                ->constrained('currencies')
                ->onDelete('set null');

            /**
             * Պարզ 1
             * Բարդ 2
             * Համատեղ 3
             * Խմբային 4
             */
            $table->integer('contract_kind')->nullable()->after('currency_id');

            $table->integer('loan_type')->nullable()->after('contract_kind');    // Վարկի տեսակ

            /**
             * Անվանական տոկոսադրույքի տեսակ
             * Լողացող    1
             * Ֆիքսված    2
             * Փոփոխվող   3
             * Անտոկոս    4
             * Լողացող փոփոխվող 5
             */
            $table->integer('interest_rate_type')->nullable()->after('loan_type');


            /**
             * Վարկի ապահովվածության տեսակը
             * Ապահովված չէ` բլանկային    0
             * Ապահովված է` այլ ապահովվածությամբ    1
             * Ապահովված է` երաշխավորությամբ    2
             * Ապահովված է` երաշխիքով    3
             * Ապահովված է` գրավով	4
            */
            $table->integer('security_type')->nullable()->after('interest_rate_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn([
                'currency_id',
                'contract_kind',
                'loan_type',
                'interest_rate_type',
                'security_type'
            ]);
        });
    }
};
