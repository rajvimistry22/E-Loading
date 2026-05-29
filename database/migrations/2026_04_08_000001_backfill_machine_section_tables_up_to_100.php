<?php

use App\Services\TableManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const MAX_MACHINE_NUMBER = 50;

    /**
     * Create any missing machine-section tables up to M50.
     */
    public function up(): void
    {
        $created = 0;
        $failed = 0;

        $machineNumbers = DB::table('machines')
            ->select('name')
            ->get()
            ->map(function ($machine) {
                if (preg_match('/M-?(\d+)/', $machine->name, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn ($machineNumber) => $machineNumber !== null && $machineNumber >= 1 && $machineNumber <= self::MAX_MACHINE_NUMBER)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if (empty($machineNumbers)) {
            $machineNumbers = range(1, self::MAX_MACHINE_NUMBER);
        }

        foreach ($machineNumbers as $machineNumber) {
            foreach (TableManager::getSections() as $section) {
                if (TableManager::createTable($machineNumber, $section)) {
                    $created++;
                } else {
                    $failed++;
                }
            }
        }

        Log::info("Backfill migration completed: {$created} machine-section tables created/verified, {$failed} failed");
    }

    /**
     * Keep rollback non-destructive.
     */
    public function down(): void
    {
        //
    }
};
