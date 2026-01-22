<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('challans', function (Blueprint $table) {
            // Add section_name column
            $table->string('section_name')->nullable()->after('machine_id');
        });

        // Migrate existing data: copy section name from sections table
        DB::statement('
            UPDATE challans 
            INNER JOIN sections ON challans.section_id = sections.id 
            SET challans.section_name = sections.name
        ');

        Schema::table('challans', function (Blueprint $table) {
            // Make section_name required and drop section_id
            $table->string('section_name')->nullable(false)->change();
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('challans', function (Blueprint $table) {
            // Add section_id back
            $table->unsignedBigInteger('section_id')->nullable()->after('machine_id');
            $table->foreign('section_id')->references('id')->on('sections')->onDelete('cascade');
        });

        // Try to restore section_id from section_name (may not be perfect)
        DB::statement('
            UPDATE challans 
            INNER JOIN sections ON challans.section_name = sections.name 
            AND challans.machine_id = sections.machine_id
            SET challans.section_id = sections.id
        ');

        Schema::table('challans', function (Blueprint $table) {
            $table->dropColumn('section_name');
        });
    }
};
