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
        Schema::table('clients', function (Blueprint $table) {
            /**
             * Ապահովված չէ` բլանկային    0
             * Ապահովված է` այլ ապահովվածությամբ    1
             * Ապահովված է` երաշխավորությամբ    2
             * Ապահովված է` երաշխիքով    3
             * Ապահովված է` գրավով	4
             */
            $table->integer('status')->default(0)->after('type');
            $table->string('region_code')->nullable()->after('status');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
           $table->dropColumn(['status','region_code']);
        });
    }
};
