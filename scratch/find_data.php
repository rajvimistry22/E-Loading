<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Models\Machine;
use App\Services\TableManager;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$machines = Machine::all();
$date = '2026-05-08';
$found = false;

foreach ($machines as $machine) {
    if (preg_match('/\d+/', $machine->name, $matches)) {
        $num = (int)$matches[0];
        foreach (TableManager::getSections() as $sec) {
            $tbl = TableManager::getTableName($num, $sec);
            if (TableManager::tableExists($tbl)) {
                $count = DB::table($tbl)->whereDate('end_datetime', $date)->count();
                if ($count > 0) {
                    echo "$tbl has $count records on $date\n";
                    $found = true;
                }
            }
        }
    }
}

if (!$found) {
    echo "No records found on $date in any table.\n";
    
    // Check 2026-05-07 too
    echo "Checking 2026-05-07...\n";
    foreach ($machines as $machine) {
        if (preg_match('/\d+/', $machine->name, $matches)) {
            $num = (int)$matches[0];
            foreach (TableManager::getSections() as $sec) {
                $tbl = TableManager::getTableName($num, $sec);
                if (TableManager::tableExists($tbl)) {
                    $count = DB::table($tbl)->whereDate('end_datetime', '2026-05-07')->count();
                    $completeCount = DB::table($tbl)->whereDate('end_datetime', '2026-05-07')->where('is_cycle_complete', 1)->count();
                    if ($count > 0) {
                        echo "$tbl has $count records on 2026-05-07 ($completeCount complete)\n";
                    }
                }
            }
        }
    }
}
