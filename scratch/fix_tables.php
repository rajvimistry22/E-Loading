<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Models\Machine;
use App\Services\TableManager;
use Illuminate\Support\Facades\Log;

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$machines = Machine::all();
echo "Found " . $machines->count() . " machines.\n";

foreach ($machines as $machine) {
    $name = $machine->name;
    if (preg_match('/\d+/', $name, $matches)) {
        $num = (int)$matches[0];
        echo "Updating tables for Machine M$num...\n";
        foreach (TableManager::getSections() as $sec) {
            $tbl = TableManager::getTableName($num, $sec);
            if (TableManager::tableExists($tbl)) {
                echo "  Ensuring columns for $tbl...\n";
                if (TableManager::ensureStopDetailColumns($tbl)) {
                    echo "    [OK] $tbl updated.\n";
                } else {
                    echo "    [FAIL] $tbl failed to update.\n";
                }
            }
        }
    }
}

echo "Done.\n";
