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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            $table->enum('type', ['individual', 'legal'])->default('individual');

            $table->string('name')->nullable();
            $table->string('surname')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('passport_series')->nullable();
            $table->date('passport_validity')->nullable();
            $table->string('passport_issued')->nullable();
            $table->string('date_of_birth')->nullable();

            $table->string('company_name')->nullable();      // Անվանում
            $table->string('legal_form')->nullable();        // Իրավական ձև (ՍՊԸ, ՓԲԸ…)
            $table->string('tax_number')->nullable();        // ՀՎՀՀ / ՀՎՔ
            $table->string('state_register_number')->nullable(); // Պետական գրանցման համար
            $table->string('activity_field')->nullable();    // Գործունեության բնագավառ
            $table->string('director_name')->nullable();     // Տնօրենի անուն, ազգանուն
            $table->string('accountant_info')->nullable();   // Հաշվապահի տվյալներ
            $table->string('internal_code')->nullable();     // Ներքին կոդ / դասակարգիչ

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('additional_phone')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('street')->nullable();
            $table->string('building')->nullable();
            $table->string('website')->nullable();

            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('card_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift_code')->nullable();

            $table->boolean('has_contract')->default(true);
            $table->date('date')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('clients');
    }
};
