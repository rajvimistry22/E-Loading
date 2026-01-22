<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Section;
use App\Models\Machine;

class SectionSeeder extends Seeder
{
 
    public function run(): void
{
    $machines = \App\Models\Machine::all();

    foreach ($machines as $machine) {
        $sections = ['A-OUT', 'A-IN', 'B-OUT', 'B-IN', 'C-OUT', 'C-IN', 'D-OUT', 'D-IN'];
        
        foreach ($sections as $sectionName) {
            \App\Models\Section::create([
                'name' => $sectionName,
                'machine_id' => $machine->id,
            ]);
        }
    }
}
}

