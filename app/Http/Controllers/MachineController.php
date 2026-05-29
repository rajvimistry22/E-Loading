<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Section;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    private const MAX_MACHINE_NUMBER = 100;

    /**
     * Display the machine selection page
     */
    public function index()
    {
        $this->ensureDefaultMachinesExist();

        $machines = Machine::where('is_active', true)
            ->orderBy('id')
            ->take(self::MAX_MACHINE_NUMBER)
            ->get();

        return view('machines.index', compact('machines'));
    }

    /**
     * Get all active machines up to 100 (AJAX)
     */
    public function getMachines()
    {
        $this->ensureDefaultMachinesExist();

        $machines = Machine::where('is_active', true)
            ->orderBy('id')
            ->take(self::MAX_MACHINE_NUMBER)
            ->get();

        return response()->json($machines);
    }

    private function ensureDefaultMachinesExist(): void
    {
        if (Machine::exists()) {
            return;
        }

        for ($i = 1; $i <= self::MAX_MACHINE_NUMBER; $i++) {
            Machine::create([
                'name' => "M-{$i}",
                'description' => "Machine {$i}",
                'is_active' => true,
            ]);
        }
    }
}
