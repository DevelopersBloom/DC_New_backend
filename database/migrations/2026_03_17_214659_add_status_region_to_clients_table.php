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
             * Վարկատուի կարգավիճակը
             * Գործող է 0
             * Գտնվում է սնանկության գործընթացում 1
             * Գտնվում է լուծարման գործընթացում 2
             * Լուծարված է 3
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
