<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Models\Machine;
use App\Services\TableManager;
use Illuminate\Support\Facades\DB;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$machines = Machine::all();
$totalReset = 0;

echo "Resetting is_cycle_complete to 0 for all records...\n";

foreach ($machines as $machine) {
    if (preg_match('/\d+/', $machine->name, $matches)) {
        $num = (int)$matches[0];
        foreach (TableManager::getSections() as $sec) {
            $tbl = TableManager::getTableName($num, $sec);
            if (TableManager::tableExists($tbl)) {
                $affected = DB::table($tbl)
                    ->where('is_cycle_complete', 1)
                    ->update(['is_cycle_complete' => 0]);
                
                if ($affected > 0) {
                    echo "  $tbl: Reset $affected records.\n";
                    $totalReset += $affected;
                }
            }
        }
    }
}

echo "Done. Total records reset: $totalReset\n";
