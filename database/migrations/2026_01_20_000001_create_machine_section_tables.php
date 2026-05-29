<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Services\TableManager;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates all machine-section tables (M1_AOUT through M50_DIN)
     */
    public function up(): void
    {
        // Get all machines from the machines table
        $machines = DB::table('machines')->pluck('name')->toArray();
        
        $created = 0;
        $failed = 0;

        if (!empty($machines)) {
            foreach ($machines as $machineName) {
                // Extract machine number from name (e.g., "M-1" -> 1)
                if (preg_match('/M-?(\d+)/', $machineName, $matches)) {
                    $machineNumber = (int) $matches[1];
                    
                    // Create tables for all 8 sections
                    foreach (TableManager::getSections() as $section) {
                        if (TableManager::createTable($machineNumber, $section)) {
                            $created++;
                        } else {
                            $failed++;
                        }
                    }
                }
            }
        }

        // If no machines exist, create tables for machines 1-50
        if ($created === 0) {
            $result = TableManager::createAllTables(50);
            $created = $result['created'];
            $failed = $result['failed'];
        }

        \Log::info("Migration completed: {$created} tables created, {$failed} failed");
    }

    /**
     * Reverse the migrations.
     * 
     * WARNING: This will drop ALL machine-section tables!
     * Use with caution in production.
     */
    public function down(): void
    {
        // Get all tables in the database
        $tables = DB::select('SHOW TABLES');
        $databaseName = DB::getDatabaseName();
        $tableKey = "Tables_in_{$databaseName}";

        $dropped = 0;

        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            
            // Check if table matches the pattern M{number}_{SECTION}
            $parsed = TableManager::parseTableName($tableName);
            
            if ($parsed && TableManager::isValidSection($parsed['section'])) {
                Schema::dropIfExists($tableName);
                $dropped++;
            }
        }

        \Log::info("Migration rollback: {$dropped} tables dropped");
    }
};
