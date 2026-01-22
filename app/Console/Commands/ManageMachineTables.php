<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TableManager;
use App\Models\Machine;

class ManageMachineTables extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'machine:tables 
                            {action : Action to perform (create|drop|list|check)}
                            {--machine= : Machine number (for create/drop)}
                            {--max=1000 : Maximum machine number (for create all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage machine-section tables (M{machine}_{section})';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'create':
                $this->handleCreate();
                break;
            case 'drop':
                $this->handleDrop();
                break;
            case 'list':
                $this->handleList();
                break;
            case 'check':
                $this->handleCheck();
                break;
            default:
                $this->error("Unknown action: {$action}");
                $this->info("Available actions: create, drop, list, check");
                return 1;
        }

        return 0;
    }

    /**
     * Handle create action
     */
    private function handleCreate()
    {
        $machineNumber = $this->option('machine');
        $max = (int) $this->option('max');

        if ($machineNumber) {
            // Create tables for specific machine
            $machineNumber = (int) $machineNumber;
            $this->info("Creating tables for machine M{$machineNumber}...");
            
            $result = TableManager::createMachineTables($machineNumber);
            
            $this->info("Created: {$result['created']} tables");
            if ($result['failed'] > 0) {
                $this->warn("Failed: {$result['failed']} tables");
                foreach ($result['errors'] as $error) {
                    $this->error("  - {$error}");
                }
            }
        } else {
            // Create tables for all machines
            if (!$this->confirm("This will create tables for machines 1-{$max}. Continue?")) {
                $this->info("Cancelled.");
                return;
            }

            $this->info("Creating tables for all machines (1-{$max})...");
            $bar = $this->output->createProgressBar($max);
            $bar->start();

            $result = TableManager::createAllTables($max);
            $bar->finish();
            $this->newLine(2);

            $this->info("Total machines: {$result['total_machines']}");
            $this->info("Total tables: {$result['total_tables']}");
            $this->info("Created: {$result['created']} tables");
            if ($result['failed'] > 0) {
                $this->warn("Failed: {$result['failed']} tables");
            }
        }
    }

    /**
     * Handle drop action
     */
    private function handleDrop()
    {
        $machineNumber = $this->option('machine');

        if (!$machineNumber) {
            $this->error("Machine number is required for drop action. Use --machine=1");
            return;
        }

        $machineNumber = (int) $machineNumber;

        if (!$this->confirm("This will drop all tables for machine M{$machineNumber}. Continue?")) {
            $this->info("Cancelled.");
            return;
        }

        $this->info("Dropping tables for machine M{$machineNumber}...");
        $sections = TableManager::getSections();
        $dropped = 0;
        $failed = 0;

        foreach ($sections as $section) {
            if (TableManager::dropTable($machineNumber, $section)) {
                $dropped++;
                $this->info("  ✓ Dropped M{$machineNumber}_{$section}");
            } else {
                $failed++;
                $this->error("  ✗ Failed to drop M{$machineNumber}_{$section}");
            }
        }

        $this->info("Dropped: {$dropped} tables");
        if ($failed > 0) {
            $this->warn("Failed: {$failed} tables");
        }
    }

    /**
     * Handle list action
     */
    private function handleList()
    {
        $machines = Machine::all();
        
        if ($machines->isEmpty()) {
            $this->warn("No machines found in database.");
            return;
        }

        $this->info("Machine Tables Status:");
        $this->newLine();

        $headers = ['Machine', 'AOUT', 'AIN', 'BOUT', 'BIN', 'COUT', 'CIN', 'DOUT', 'DIN'];
        $rows = [];

        foreach ($machines->take(50) as $machine) {
            if (preg_match('/M-?(\d+)/', $machine->name, $matches)) {
                $machineNumber = (int) $matches[1];
                $row = [$machine->name];
                
                foreach (TableManager::getSections() as $section) {
                    $tableName = TableManager::getTableName($machineNumber, $section);
                    $exists = TableManager::tableExists($tableName);
                    $row[] = $exists ? '✓' : '✗';
                }
                
                $rows[] = $row;
            }
        }

        $this->table($headers, $rows);
        
        if ($machines->count() > 50) {
            $this->warn("Showing first 50 machines. Total: {$machines->count()}");
        }
    }

    /**
     * Handle check action
     */
    private function handleCheck()
    {
        $machines = Machine::all();
        
        if ($machines->isEmpty()) {
            $this->warn("No machines found in database.");
            return;
        }

        $this->info("Checking table status...");
        $this->newLine();

        $totalMachines = 0;
        $totalTables = 0;
        $missingTables = 0;
        $missingMachines = [];

        foreach ($machines as $machine) {
            if (preg_match('/M-?(\d+)/', $machine->name, $matches)) {
                $machineNumber = (int) $matches[1];
                $totalMachines++;
                
                $missing = [];
                foreach (TableManager::getSections() as $section) {
                    $totalTables++;
                    $tableName = TableManager::getTableName($machineNumber, $section);
                    if (!TableManager::tableExists($tableName)) {
                        $missingTables++;
                        $missing[] = $section;
                    }
                }
                
                if (!empty($missing)) {
                    $missingMachines[] = [
                        'machine' => $machine->name,
                        'missing' => implode(', ', $missing)
                    ];
                }
            }
        }

        $expectedTables = $totalMachines * count(TableManager::getSections());
        $existingTables = $expectedTables - $missingTables;

        $this->info("Total machines: {$totalMachines}");
        $this->info("Expected tables: {$expectedTables}");
        $this->info("Existing tables: {$existingTables}");
        $this->info("Missing tables: {$missingTables}");

        if (!empty($missingMachines)) {
            $this->newLine();
            $this->warn("Machines with missing tables:");
            $this->table(['Machine', 'Missing Sections'], $missingMachines);
        } else {
            $this->newLine();
            $this->info("✓ All tables exist!");
        }
    }
}
