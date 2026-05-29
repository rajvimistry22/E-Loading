<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\MachineSectionReport;

$tableName = 'm1_cout';
$rowNo = 999; // Dummy row

try {
    echo "Saving dummy row to $tableName...\n";
    $data = [
        'row_no' => $rowNo,
        'start_datetime' => '2026-05-08 08:00:00',
        'end_datetime' => '2026-05-08 20:00:00',
        'expected_end_datetime' => '2026-05-08 20:00:00',
        'is_cycle_complete' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    
    DB::table($tableName)->insert($data);
    
    $saved = DB::table($tableName)->where('row_no', $rowNo)->first();
    echo "Saved value in DB: " . ($saved->is_cycle_complete ?? 'NULL') . "\n";
    
    DB::table($tableName)->where('row_no', $rowNo)->delete();
    echo "Cleaned up.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
