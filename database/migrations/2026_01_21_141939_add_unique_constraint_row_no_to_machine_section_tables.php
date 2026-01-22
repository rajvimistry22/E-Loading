<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\TableManager;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds unique constraint on row_no for all machine-section tables.
     * Since each table is already machine-section specific, row_no alone is unique.
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
                    // Check if unique constraint already exists
                    $indexes = DB::select("SHOW INDEXES FROM `{$tableName}` WHERE Key_name = 'unique_row_no'");
                    
                    if (empty($indexes)) {
                        // Check if row_no column exists
                        if (Schema::hasColumn($tableName, 'row_no')) {
                            // Add unique constraint on row_no
                            DB::statement("ALTER TABLE `{$tableName}` ADD UNIQUE KEY `unique_row_no` (`row_no`)");
                            $modified++;
                            Log::info("Added unique constraint on row_no for table {$tableName}");
                        } else {
                            Log::warning("Table {$tableName} does not have row_no column, skipping");
                        }
                    } else {
                        Log::info("Unique constraint already exists on row_no for table {$tableName}");
                    }
                } catch (\Exception $e) {
                    $errors[] = "Table {$tableName}: " . $e->getMessage();
                    Log::error("Error adding unique constraint to {$tableName}: " . $e->getMessage());
                }
            }
        }

        Log::info("Migration completed: Modified {$modified} tables, " . count($errors) . " errors");
        
        if (!empty($errors)) {
            Log::warning("Errors encountered: " . implode('; ', $errors));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
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
                    // Check if unique constraint exists
                    $indexes = DB::select("SHOW INDEXES FROM `{$tableName}` WHERE Key_name = 'unique_row_no'");
                    
                    if (!empty($indexes)) {
                        // Drop unique constraint
                        DB::statement("ALTER TABLE `{$tableName}` DROP INDEX `unique_row_no`");
                        $modified++;
                        Log::info("Dropped unique constraint on row_no for table {$tableName}");
                    }
                } catch (\Exception $e) {
                    $errors[] = "Table {$tableName}: " . $e->getMessage();
                    Log::error("Error dropping unique constraint from {$tableName}: " . $e->getMessage());
                }
            }
        }

        Log::info("Rollback completed: Modified {$modified} tables, " . count($errors) . " errors");
    }
};
