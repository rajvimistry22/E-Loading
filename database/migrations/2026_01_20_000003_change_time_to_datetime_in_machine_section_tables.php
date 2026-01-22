<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\TableManager;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Changes start_time and end_time columns from TIME to DATETIME
     * This allows storing both date and time together
     */
    public function up(): void
    {
        // Get all tables in the database
        $tables = DB::select('SHOW TABLES');
        $databaseName = DB::getDatabaseName();
        $tableKey = "Tables_in_{$databaseName}";

        $modified = 0;
        $errors = [];

        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            
            // Check if table matches the pattern M{number}_{SECTION}
            $parsed = TableManager::parseTableName($tableName);
            
            if ($parsed && TableManager::isValidSection($parsed['section'])) {
                try {
                    // Check current column types
                    $columns = DB::select("SHOW COLUMNS FROM `{$tableName}`");
                    $columnInfo = [];
                    foreach ($columns as $col) {
                        $columnInfo[$col->Field] = $col;
                    }
                    
                    // Change start_time from TIME to DATETIME
                    if (isset($columnInfo['start_time'])) {
                        $startTimeType = strtolower($columnInfo['start_time']->Type);
                        // Check if it's TIME type (not DATETIME or TIMESTAMP)
                        if (strpos($startTimeType, 'time') !== false && strpos($startTimeType, 'datetime') === false && strpos($startTimeType, 'timestamp') === false) {
                            // Convert TIME to DATETIME
                            // For existing TIME values, combine with created_at date if available
                            DB::statement("ALTER TABLE `{$tableName}` 
                                MODIFY COLUMN `start_time` DATETIME NULL");
                            $modified++;
                        }
                    }
                    
                    // Change end_time from TIME to DATETIME
                    if (isset($columnInfo['end_time'])) {
                        $endTimeType = strtolower($columnInfo['end_time']->Type);
                        // Check if it's TIME type (not DATETIME or TIMESTAMP)
                        if (strpos($endTimeType, 'time') !== false && strpos($endTimeType, 'datetime') === false && strpos($endTimeType, 'timestamp') === false) {
                            // Convert TIME to DATETIME
                            DB::statement("ALTER TABLE `{$tableName}` 
                                MODIFY COLUMN `end_time` DATETIME NULL");
                            $modified++;
                        }
                    }
                    
                } catch (\Exception $e) {
                    $errors[] = "Failed to modify {$tableName}: " . $e->getMessage();
                }
            }
        }

        \Log::info("Migration completed: {$modified} tables modified (start_time/end_time changed to DATETIME)");
        if (!empty($errors)) {
            \Log::warning("Migration errors: " . implode(', ', $errors));
        }
    }

    /**
     * Reverse the migrations.
     * 
     * WARNING: This will change DATETIME back to TIME, losing date information.
     */
    public function down(): void
    {
        // Get all tables in the database
        $tables = DB::select('SHOW TABLES');
        $databaseName = DB::getDatabaseName();
        $tableKey = "Tables_in_{$databaseName}";

        $modified = 0;

        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            
            // Check if table matches the pattern M{number}_{SECTION}
            $parsed = TableManager::parseTableName($tableName);
            
            if ($parsed && TableManager::isValidSection($parsed['section'])) {
                try {
                    // Change back to TIME (will lose date component)
                    DB::statement("ALTER TABLE `{$tableName}` 
                        MODIFY COLUMN `start_time` TIME NULL");
                    DB::statement("ALTER TABLE `{$tableName}` 
                        MODIFY COLUMN `end_time` TIME NULL");
                    
                    $modified++;
                } catch (\Exception $e) {
                    \Log::error("Failed to rollback {$tableName}: " . $e->getMessage());
                }
            }
        }

        \Log::info("Migration rollback: {$modified} tables modified (DATETIME changed back to TIME)");
    }
};
