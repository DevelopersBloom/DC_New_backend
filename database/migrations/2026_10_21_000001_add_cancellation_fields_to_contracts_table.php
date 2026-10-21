<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Cancellation of contracts that were created but never disbursed (BE-001). */
    public function up(): void
    {
        $this->setStatusEnum(add: 'cancelled');

        Schema::table('contracts', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('provided_at');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->string('cancel_reason_code', 32)->nullable()->after('cancelled_by');
            $table->text('cancel_reason')->nullable()->after('cancel_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason_code', 'cancel_reason']);
        });

        if (!DB::table('contracts')->where('status', 'cancelled')->exists()) {
            $this->setStatusEnum(remove: 'cancelled');
        }
    }

    /**
     * contracts.status is a MySQL ENUM. Extend whatever values the live column has
     * (they may differ from the original create migration) instead of hardcoding them.
     */
    private function setStatusEnum(?string $add = null, ?string $remove = null): void
    {
        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts' AND COLUMN_NAME = 'status'"
        );

        if (!$column || !str_starts_with(strtolower($column->type), 'enum(')) {
            return; // a plain string column accepts the new value as is
        }

        preg_match_all("/'((?:[^']|'')*)'/", $column->type, $matches);
        $values = $matches[1];

        if ($add !== null && !in_array($add, $values, true)) {
            $values[] = $add;
        }
        if ($remove !== null) {
            $values = array_values(array_diff($values, [$remove]));
        }

        $list = implode(',', array_map(fn ($v) => "'" . $v . "'", $values));
        DB::statement("ALTER TABLE contracts MODIFY status ENUM($list) NOT NULL DEFAULT 'initial'");
    }
};
