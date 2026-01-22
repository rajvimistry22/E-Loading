<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Section;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    /**
     * Display the machine selection page
     */
    public function index()
    {
        $machines = Machine::all();

        return view('machines.index', compact('machines'));
    }

    /**
     * Get all machines (AJAX)
     */
    public function getMachines()
    {
        $machines = Machine::where('is_active', true)->orderBy('name')->get();
        return response()->json($machines);
    }
}
