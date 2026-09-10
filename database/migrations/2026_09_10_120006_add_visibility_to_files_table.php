<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->enum('visibility', ['public', 'admin_only'])->default('public')->after('doc_type');
        });

        // Backfill existing rows explicitly (the default already covers them, but be defensive).
        DB::table('files')->whereNull('visibility')->update(['visibility' => 'public']);
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
