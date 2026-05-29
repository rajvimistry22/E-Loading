<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\TableManager;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Manually get all machine-section tables (since dynamic, check common patterns)
        $allTables = DB::select('SHOW TABLES');
        $tableNames = [];
        
        foreach ($allTables as $table) {
            $tableName = array_values((array) $table)[0];
            if (preg_match('/^M\d+_[A-Z]+$/', $tableName)) {
                $tableNames[] = $tableName;
            }
        }
        
        Log::info('Found machine-section tables: ' . implode(', ', $tableNames));
        
        foreach ($tableNames as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // Add if not exists
                if (!Schema::hasColumn($tableName, 'stop_start_datetime')) {
                    $table->datetime('stop_start_datetime')->nullable()->after('machine_stop_hours');
                }
                if (!Schema::hasColumn($tableName, 'stop_end_datetime')) {
                    $table->datetime('stop_end_datetime')->nullable()->after('stop_start_datetime');
                }
                if (!Schema::hasColumn($tableName, 'stop_remarks')) {
                    $table->text('stop_remarks')->nullable()->after('stop_end_datetime');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $allTables = DB::select('SHOW TABLES');
        $tableNames = [];

        foreach ($allTables as $table) {
            $tableName = array_values((array) $table)[0];
            if (preg_match('/^M\d+_[A-Z]+$/', $tableName)) {
                $tableNames[] = $tableName;
            }
        }
        
        foreach ($tableNames as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $columnsToDrop = [];

                    if (Schema::hasColumn($tableName, 'stop_start_datetime')) {
                        $columnsToDrop[] = 'stop_start_datetime';
                    }

                    if (Schema::hasColumn($tableName, 'stop_end_datetime')) {
                        $columnsToDrop[] = 'stop_end_datetime';
                    }

                    if (Schema::hasColumn($tableName, 'stop_remarks')) {
                        $columnsToDrop[] = 'stop_remarks';
                    }

                    if (!empty($columnsToDrop)) {
                        $table->dropColumn($columnsToDrop);
                    }
                });
            }
        }
    }
};

