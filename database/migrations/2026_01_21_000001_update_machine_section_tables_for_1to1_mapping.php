<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Services\TableManager;

/**
 * Migration: Update machine-section tables for 1:1 UI-to-Database mapping
 * 
 * Adds:
 * - row_no: For maintaining row order
 * - expected_end_datetime: Calculated field (start + loading_hours)
 * - Makes loading_duration nullable (for intermediate rows)
 */
class UpdateMachineSectionTablesFor1to1Mapping extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all machine-section tables
        $tables = $this->getAllMachineSectionTables();
        $total = count($tables);
        $processed = 0;
        
        \Log::info("Starting migration for {$total} machine-section tables");
        
        foreach ($tables as $tableName) {
            $processed++;
            if ($processed % 100 === 0) {
                \Log::info("Migration progress: {$processed}/{$total} tables processed");
            }
            if (Schema::hasTable($tableName)) {
                try {
                    // Check if table uses old column names (start_time, end_time) or new (start_datetime, end_datetime)
                    $hasOldColumns = Schema::hasColumn($tableName, 'start_time');
                    $hasNewColumns = Schema::hasColumn($tableName, 'start_datetime');
                    
                    if ($hasOldColumns && !$hasNewColumns) {
                        // Migrate from old schema to new schema
                        $this->migrateToNewSchema($tableName);
                    } else if ($hasNewColumns) {
                        // Already using new schema, just add missing columns
                        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                            if (!Schema::hasColumn($tableName, 'row_no')) {
                                $table->unsignedInteger('row_no')->nullable()->after('id');
                                $table->index('row_no');
                            }
                            
                            if (!Schema::hasColumn($tableName, 'expected_end_datetime')) {
                                $table->datetime('expected_end_datetime')->nullable()->after('start_datetime');
                                $table->index('expected_end_datetime');
                            }
                        });
                        
                        // Rename columns if needed (outside Schema::table closure)
                        if (Schema::hasColumn($tableName, 'loading_duration') && !Schema::hasColumn($tableName, 'loading_hours')) {
                            try {
                                DB::statement("ALTER TABLE `{$tableName}` CHANGE COLUMN `loading_duration` `loading_hours` DECIMAL(10,2) NULL");
                            } catch (\Exception $e) {
                                \Log::warning("Failed to rename loading_duration in {$tableName}: " . $e->getMessage());
                            }
                        }
                        
                        if (Schema::hasColumn($tableName, 'machine_stop_time') && !Schema::hasColumn($tableName, 'machine_stop_hours')) {
                            try {
                                DB::statement("ALTER TABLE `{$tableName}` CHANGE COLUMN `machine_stop_time` `machine_stop_hours` DECIMAL(10,2) DEFAULT 0");
                            } catch (\Exception $e) {
                                \Log::warning("Failed to rename machine_stop_time in {$tableName}: " . $e->getMessage());
                            }
                        }
                        
                        // Update existing records
                        $this->updateRowNumbers($tableName);
                        $this->calculateExpectedEndDatetime($tableName);
                    } else {
                        // Table exists but has neither old nor new columns - might be empty or different structure
                        // Just add the new columns
                        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                            if (!Schema::hasColumn($tableName, 'row_no')) {
                                $table->unsignedInteger('row_no')->nullable()->after('id');
                                $table->index('row_no');
                            }
                            
                            if (!Schema::hasColumn($tableName, 'start_datetime')) {
                                $table->datetime('start_datetime')->nullable();
                                $table->index('start_datetime');
                            }
                            
                            if (!Schema::hasColumn($tableName, 'expected_end_datetime')) {
                                $table->datetime('expected_end_datetime')->nullable()->after('start_datetime');
                                $table->index('expected_end_datetime');
                            }
                        });
                    }
                } catch (\Exception $e) {
                    \Log::error("Failed to migrate table {$tableName}: " . $e->getMessage());
                    // Continue with other tables
                }
            }
        }
        
        \Log::info("Migration completed: {$processed}/{$total} tables processed");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = $this->getAllMachineSectionTables();
        
        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (Schema::hasColumn($tableName, 'row_no')) {
                        $table->dropIndex([$tableName . '_row_no_index']);
                        $table->dropColumn('row_no');
                    }
                    
                    if (Schema::hasColumn($tableName, 'expected_end_datetime')) {
                        $table->dropIndex([$tableName . '_expected_end_datetime_index']);
                        $table->dropColumn('expected_end_datetime');
                    }
                    
                    // Make loading_duration NOT NULL again
                    if (Schema::hasColumn($tableName, 'loading_duration')) {
                        DB::statement("ALTER TABLE `{$tableName}` MODIFY COLUMN `loading_duration` DECIMAL(10,2) NOT NULL DEFAULT 0");
                    }
                });
            }
        }
    }

    /**
     * Get all machine-section table names that actually exist in the database
     */
    private function getAllMachineSectionTables(): array
    {
        $tables = [];
        
        // Get all tables from the database and filter for machine-section tables
        $allTables = DB::select('SHOW TABLES');
        $databaseName = DB::getDatabaseName();
        $tableKey = "Tables_in_{$databaseName}";
        
        foreach ($allTables as $table) {
            $tableName = $table->$tableKey;
            $parsed = TableManager::parseTableName($tableName);
            
            if ($parsed && TableManager::isValidSection($parsed['section'])) {
                $tables[] = $tableName;
            }
        }
        
        return $tables;
    }

    /**
     * Migrate from old schema (start_time, end_time) to new schema (start_datetime, end_datetime)
     */
    private function migrateToNewSchema(string $tableName): void
    {
        // Rename columns only if they exist and new columns don't exist
        if (Schema::hasColumn($tableName, 'start_time') && !Schema::hasColumn($tableName, 'start_datetime')) {
            try {
                DB::statement("ALTER TABLE `{$tableName}` CHANGE COLUMN `start_time` `start_datetime` DATETIME NULL");
            } catch (\Exception $e) {
                \Log::warning("Failed to rename start_time in {$tableName}: " . $e->getMessage());
            }
        }
        if (Schema::hasColumn($tableName, 'end_time') && !Schema::hasColumn($tableName, 'end_datetime')) {
            try {
                DB::statement("ALTER TABLE `{$tableName}` CHANGE COLUMN `end_time` `end_datetime` DATETIME NULL");
            } catch (\Exception $e) {
                \Log::warning("Failed to rename end_time in {$tableName}: " . $e->getMessage());
            }
        }
        if (Schema::hasColumn($tableName, 'loading_duration') && !Schema::hasColumn($tableName, 'loading_hours')) {
            try {
                DB::statement("ALTER TABLE `{$tableName}` CHANGE COLUMN `loading_duration` `loading_hours` DECIMAL(10,2) NULL");
            } catch (\Exception $e) {
                \Log::warning("Failed to rename loading_duration in {$tableName}: " . $e->getMessage());
            }
        }
        if (Schema::hasColumn($tableName, 'machine_stop_time') && !Schema::hasColumn($tableName, 'machine_stop_hours')) {
            try {
                DB::statement("ALTER TABLE `{$tableName}` CHANGE COLUMN `machine_stop_time` `machine_stop_hours` DECIMAL(10,2) DEFAULT 0");
            } catch (\Exception $e) {
                \Log::warning("Failed to rename machine_stop_time in {$tableName}: " . $e->getMessage());
            }
        }
        
        // Add new columns
        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (!Schema::hasColumn($tableName, 'row_no')) {
                $table->unsignedInteger('row_no')->nullable()->after('id');
                $table->index('row_no');
            }
            
            if (!Schema::hasColumn($tableName, 'expected_end_datetime')) {
                $table->datetime('expected_end_datetime')->nullable()->after('start_datetime');
                $table->index('expected_end_datetime');
            }
        });
        
        // Update existing records
        $this->updateRowNumbers($tableName);
        $this->calculateExpectedEndDatetime($tableName);
    }

    /**
     * Update row_no for existing records based on start_datetime order
     */
    private function updateRowNumbers(string $tableName): void
    {
        $orderColumn = Schema::hasColumn($tableName, 'start_datetime') ? 'start_datetime' : 'start_time';
        
        $records = DB::table($tableName)
            ->orderBy($orderColumn)
            ->get(['id']);
        
        foreach ($records as $index => $record) {
            DB::table($tableName)
                ->where('id', $record->id)
                ->update(['row_no' => $index + 1]);
        }
    }

    /**
     * Calculate expected_end_datetime for existing records
     */
    private function calculateExpectedEndDatetime(string $tableName): void
    {
        $records = DB::table($tableName)->get();
        
        foreach ($records as $record) {
            $startDatetime = $record->start_datetime ?? $record->start_time ?? null;
            $loadingHours = $record->loading_hours ?? $record->loading_duration ?? null;
            
            if ($startDatetime && $loadingHours && (float)$loadingHours > 0) {
                $expectedEnd = \Carbon\Carbon::parse($startDatetime)->addHours((float)$loadingHours);
                DB::table($tableName)
                    ->where('id', $record->id)
                    ->update(['expected_end_datetime' => $expectedEnd]);
            } else if ($startDatetime) {
                // For intermediate rows, expected_end = start
                DB::table($tableName)
                    ->where('id', $record->id)
                    ->update(['expected_end_datetime' => $startDatetime]);
            }
        }
    }
}
