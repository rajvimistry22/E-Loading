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
     * Removes report_date, quantity, remarks, and status columns from all machine-section tables
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
                    // Check if columns exist before dropping
                    $columns = DB::select("SHOW COLUMNS FROM `{$tableName}`");
                    $columnNames = array_column($columns, 'Field');
                    
                    Schema::table($tableName, function ($table) use ($columnNames) {
                        // Drop columns if they exist
                        if (in_array('report_date', $columnNames)) {
                            $table->dropColumn('report_date');
                        }
                        if (in_array('quantity', $columnNames)) {
                            $table->dropColumn('quantity');
                        }
                        if (in_array('remarks', $columnNames)) {
                            $table->dropColumn('remarks');
                        }
                        if (in_array('status', $columnNames)) {
                            $table->dropColumn('status');
                        }
                    });
                    
                    // Drop indexes that reference these columns
                    try {
                        DB::statement("ALTER TABLE `{$tableName}` DROP INDEX IF EXISTS idx_report_date");
                        DB::statement("ALTER TABLE `{$tableName}` DROP INDEX IF EXISTS idx_status");
                        DB::statement("ALTER TABLE `{$tableName}` DROP INDEX IF EXISTS idx_date_status");
                    } catch (\Exception $e) {
                        // Index might not exist, ignore
                    }
                    
                    $modified++;
                } catch (\Exception $e) {
                    $errors[] = "Failed to modify {$tableName}: " . $e->getMessage();
                }
            }
        }

        \Log::info("Migration completed: {$modified} tables modified");
        if (!empty($errors)) {
            \Log::warning("Migration errors: " . implode(', ', $errors));
        }
    }

    /**
     * Reverse the migrations.
     * 
     * WARNING: This will add back the columns with default values.
     * Data that was in these columns will be lost.
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
                    Schema::table($tableName, function ($table) {
                        // Add columns back with default values
                        $table->date('report_date')->nullable()->after('id');
                        $table->decimal('quantity', 10, 2)->nullable()->after('machine_stop_time');
                        $table->text('remarks')->nullable()->after('quantity');
                        $table->string('status', 20)->default('active')->after('remarks');
                    });
                    
                    // Re-add indexes
                    try {
                        DB::statement("ALTER TABLE `{$tableName}` ADD INDEX idx_report_date (report_date)");
                        DB::statement("ALTER TABLE `{$tableName}` ADD INDEX idx_status (status)");
                        DB::statement("ALTER TABLE `{$tableName}` ADD INDEX idx_date_status (report_date, status)");
                    } catch (\Exception $e) {
                        // Index might already exist, ignore
                    }
                    
                    $modified++;
                } catch (\Exception $e) {
                    \Log::error("Failed to rollback {$tableName}: " . $e->getMessage());
                }
            }
        }

        \Log::info("Migration rollback: {$modified} tables modified");
    }
};
