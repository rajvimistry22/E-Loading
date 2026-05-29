<?php

namespace Database\Seeders;

use App\Models\Machine;
use App\Models\Section;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed Machines (M-1 to M-100)
        $this->command->info('Seeding machines...');
        for ($i = 1; $i <= 100; $i++) {
            Machine::create([
                'name' => "M-{$i}",
                'description' => "Machine {$i}",
                'is_active' => true,
            ]);
        }
        $this->command->info('Machines seeded successfully!');

        // Seed Sections for each machine
        $this->command->info('Seeding sections...');
        $machines = Machine::all();
        $sectionNames = ['A-OUT', 'A-IN', 'B-OUT', 'B-IN', 'C-OUT', 'C-IN', 'D-OUT', 'D-IN'];
        
        foreach ($machines as $machine) {
            foreach ($sectionNames as $sectionName) {
                Section::create([
                    'name' => $sectionName,
                    'machine_id' => $machine->id,
                ]);
            }
        }
        $this->command->info('Sections seeded successfully!');
    }
}
