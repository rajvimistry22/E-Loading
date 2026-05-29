<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Schema\Blueprint;

/**
 * TableManager Service
 * 
 * Manages dynamic table creation and operations for machine-section combinations.
 * Table naming: M{machine_number}_{SECTION}
 * Example: M1_AOUT, M25_BIN, M1000_DIN
 */
class TableManager
{
    /**
     * Fixed sections for each machine
     */
    const SECTIONS = ['AOUT', 'AIN', 'BOUT', 'BIN', 'COUT', 'CIN', 'DOUT', 'DIN'];

    /**
     * Generate table name from machine number and section
     * 
     * @param int $machineNumber
     * @param string $section
     * @return string
     */
    public static function getTableName(int $machineNumber, string $section): string
    {
        return "M{$machineNumber}_{$section}";
    }

    /**
     * Parse table name to extract machine number and section
     * 
     * @param string $tableName
     * @return array|null ['machine_number' => int, 'section' => string] or null if invalid
     */
    public static function parseTableName(string $tableName): ?array
    {
        // Pattern: M{number}_{SECTION}
        if (preg_match('/^M(\d+)_([A-Z]+)$/', $tableName, $matches)) {
            return [
                'machine_number' => (int) $matches[1],
                'section' => $matches[2]
            ];
        }
        return null;
    }

    /**
     * Check if table exists (case-insensitive check for MySQL on Windows)
     * 
     * @param string $tableName
     * @return bool
     */
    public static function tableExists(string $tableName): bool
    {
        // First try exact match
        if (Schema::hasTable($tableName)) {
            return true;
        }
        
        // On Windows MySQL, table names are case-insensitive, so check lowercase version
        $lowercaseName = strtolower($tableName);
        if ($lowercaseName !== $tableName && Schema::hasTable($lowercaseName)) {
            Log::info("Table exists as {$lowercaseName} (case-insensitive match for {$tableName})");
            return true;
        }
        
        // Also check by querying database directly
        try {
            $tables = DB::select("SHOW TABLES LIKE ?", [$tableName]);
            if (!empty($tables)) {
                return true;
            }
            
            // Try case-insensitive search
            $tables = DB::select("SHOW TABLES LIKE ?", [strtolower($tableName)]);
            if (!empty($tables)) {
                Log::info("Table exists with different case: " . $tables[0]->{array_keys((array)$tables[0])[0]});
                return true;
            }
        } catch (\Exception $e) {
            Log::warning("Error checking table existence: " . $e->getMessage());
        }
        
        return false;
    }

    /**
     * Create table for a machine-section combination
     * 
     * @param int $machineNumber
     * @param string $section
     * @return bool
     */
    public static function createTable(int $machineNumber, string $section): bool
    {
        $tableName = self::getTableName($machineNumber, $section);

        if (self::tableExists($tableName)) {
            Log::info("Table {$tableName} already exists");
            return self::ensureStopDetailColumns($tableName);
        }

        try {
            // Double-check table doesn't exist (case-insensitive)
            if (self::tableExists($tableName)) {
                Log::info("Table {$tableName} already exists (checked case-insensitively)");
                return true;
            }
            
            Schema::create($tableName, function (Blueprint $table) {
                $table->id();
                $table->integer('row_no')->nullable();
                $table->datetime('start_datetime')->nullable();
                $table->datetime('expected_end_datetime')->nullable();
                $table->datetime('end_datetime')->nullable();
                $table->decimal('loading_hours', 10, 2)->nullable();
                $table->decimal('machine_stop_hours', 10, 2)->default(0);
                $table->datetime('stop_start_datetime')->nullable();
                $table->datetime('stop_end_datetime')->nullable();
                $table->text('stop_remarks')->nullable();
                $table->boolean('is_cycle_complete')->default(false);
                $table->timestamps();

                // Indexes
                $table->index('row_no');
                $table->index('start_datetime');
                $table->index('end_datetime');
                $table->index('expected_end_datetime');
                $table->index('created_at');
            });

            // Verify table was actually created
            if (!self::tableExists($tableName)) {
                Log::error("Table {$tableName} creation reported success but table does not exist after creation");
                return false;
            }

            if (!self::ensureStopDetailColumns($tableName)) {
                Log::error("Table {$tableName} created but stop detail columns could not be verified");
                return false;
            }
            
            Log::info("Table {$tableName} created and verified successfully");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to create table {$tableName}: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Create all tables for a machine (all 8 sections)
     * 
     * @param int $machineNumber
     * @return array ['created' => int, 'failed' => int, 'errors' => array]
     */
    public static function createMachineTables(int $machineNumber): array
    {
        $result = [
            'created' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach (self::SECTIONS as $section) {
            if (self::createTable($machineNumber, $section)) {
                $result['created']++;
            } else {
                $result['failed']++;
                $result['errors'][] = "Failed to create table for M{$machineNumber}_{$section}";
            }
        }

        return $result;
    }

    /**
     * Create all tables for all machines
     * 
     * @param int $maxMachineNumber Maximum machine number (default: 1000)
     * @return array
     */
    public static function createAllTables(int $maxMachineNumber = 1000): array
    {
        $result = [
            'total_machines' => 0,
            'total_tables' => 0,
            'created' => 0,
            'failed' => 0,
            'errors' => []
        ];

        for ($i = 1; $i <= $maxMachineNumber; $i++) {
            $result['total_machines']++;
            $machineResult = self::createMachineTables($i);
            $result['created'] += $machineResult['created'];
            $result['failed'] += $machineResult['failed'];
            $result['errors'] = array_merge($result['errors'], $machineResult['errors']);
        }

        $result['total_tables'] = $result['total_machines'] * count(self::SECTIONS);

        return $result;
    }

    /**
     * Drop table for a machine-section combination
     * 
     * @param int $machineNumber
     * @param string $section
     * @return bool
     */
    public static function dropTable(int $machineNumber, string $section): bool
    {
        $tableName = self::getTableName($machineNumber, $section);

        if (!self::tableExists($tableName)) {
            return true;
        }

        try {
            Schema::dropIfExists($tableName);
            Log::info("Table {$tableName} dropped successfully");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to drop table {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all table names for a machine
     * 
     * @param int $machineNumber
     * @return array
     */
    public static function getMachineTableNames(int $machineNumber): array
    {
        $tables = [];
        foreach (self::SECTIONS as $section) {
            $tableName = self::getTableName($machineNumber, $section);
            if (self::tableExists($tableName)) {
                $tables[] = $tableName;
            }
        }
        return $tables;
    }

    /**
     * Validate section name
     * 
     * @param string $section
     * @return bool
     */
    public static function isValidSection(string $section): bool
    {
        return in_array(strtoupper($section), self::SECTIONS);
    }

    /**
     * Ensure legacy machine-section tables contain stop detail columns.
     *
     * @param string $tableName
     * @return bool
     */
    public static function ensureStopDetailColumns(string $tableName): bool
    {
        if (!self::tableExists($tableName)) {
            return false;
        }

        try {
            $columnsInfo = Schema::getColumnListing($tableName);

            // Older production tables may still use the legacy machine_stop_time column name.
            if (!in_array('machine_stop_hours', $columnsInfo, true)) {
                if (in_array('machine_stop_time', $columnsInfo, true)) {
                    try {
                        DB::statement("ALTER TABLE `{$tableName}` CHANGE COLUMN `machine_stop_time` `machine_stop_hours` DECIMAL(10,2) DEFAULT 0");
                        Log::info("Renamed legacy machine_stop_time column on {$tableName}");
                    } catch (\Exception $e) {
                        Log::warning("Failed renaming machine_stop_time on {$tableName}: " . $e->getMessage());
                    }
                }

                $columnsInfo = Schema::getColumnListing($tableName);

                if (!in_array('machine_stop_hours', $columnsInfo, true)) {
                    Schema::table($tableName, function (Blueprint $table) use ($columnsInfo) {
                        $column = $table->decimal('machine_stop_hours', 10, 2)->default(0)->unsigned();

                        if (in_array('loading_hours', $columnsInfo, true)) {
                            $column->after('loading_hours');
                        } elseif (in_array('end_datetime', $columnsInfo, true)) {
                            $column->after('end_datetime');
                        }
                    });
                }
            }

            $columnsInfo = Schema::getColumnListing($tableName);
            $needsFixLoading = in_array('loading_hours', $columnsInfo, true) && Schema::getColumnType($tableName, 'loading_hours') !== 'decimal';
            $needsFixStop = in_array('machine_stop_hours', $columnsInfo, true) && Schema::getColumnType($tableName, 'machine_stop_hours') !== 'decimal';

            if ($needsFixLoading || $needsFixStop) {
                Log::warning("Numeric column types incorrect in {$tableName}, model will handle casting");
            }

            $needsStopStart = !Schema::hasColumn($tableName, 'stop_start_datetime');
            $needsStopEnd = !Schema::hasColumn($tableName, 'stop_end_datetime');
            $needsRemarks = !Schema::hasColumn($tableName, 'stop_remarks');


            // Ensure additional columns are added if missing
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $needsStopStart, $needsStopEnd, $needsRemarks) {
                if ($needsStopStart) {
                    $column = $table->datetime('stop_start_datetime')->nullable();
                    if (Schema::hasColumn($tableName, 'machine_stop_hours')) {
                        $column->after('machine_stop_hours');
                    }
                }

                if ($needsStopEnd) {
                    $column = $table->datetime('stop_end_datetime')->nullable();
                    if (Schema::hasColumn($tableName, 'stop_start_datetime')) {
                        $column->after('stop_start_datetime');
                    }
                }

                if ($needsRemarks) {
                    $column = $table->text('stop_remarks')->nullable();
                    if (Schema::hasColumn($tableName, 'stop_end_datetime')) {
                        $column->after('stop_end_datetime');
                    }
                }
            });

            // Re-fetch column listing before the check
            $columnsInfo = Schema::getColumnListing($tableName);
            
            // Ensure cycle-complete flag exists (robust against partial/legacy tables)
            if (!in_array('is_cycle_complete', $columnsInfo)) {
                try {
                    Log::info("Adding is_cycle_complete to {$tableName}...");
                    Schema::table($tableName, function (Blueprint $table) {
                        $table->boolean('is_cycle_complete')->default(false);
                    });
                    Log::info("Successfully added is_cycle_complete to {$tableName}");
                    // Refresh listing
                    $columnsInfo = Schema::getColumnListing($tableName);
                } catch (\Exception $e) {
                    Log::error("Failed adding is_cycle_complete to {$tableName}: " . $e->getMessage());
                    // Fallback to direct SQL if Schema facade fails
                    try {
                        DB::statement("ALTER TABLE `{$tableName}` ADD COLUMN `is_cycle_complete` TINYINT(1) DEFAULT 0");
                        Log::info("Added is_cycle_complete to {$tableName} via direct SQL");
                        $columnsInfo = Schema::getColumnListing($tableName);
                    } catch (\Exception $e2) {
                        Log::error("Direct SQL also failed for {$tableName}: " . $e2->getMessage());
                    }
                }
            }

            // Final re-verify
            $finalColumns = Schema::getColumnListing($tableName);
            $essentialColumns = ['row_no', 'start_datetime', 'end_datetime', 'loading_hours', 'is_cycle_complete'];
            foreach ($essentialColumns as $col) {
                if (!in_array($col, $finalColumns)) {
                    Log::error("Table {$tableName} is missing essential column after ensuring: {$col}");
                    return false;
                }
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed ensuring stop detail columns on {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all valid sections
     * 
     * @return array
     */
    public static function getSections(): array
    {
        return self::SECTIONS;
    }
}
