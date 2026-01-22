use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

public function run(): void
{
    // Disable foreign key checks
    Schema::disableForeignKeyConstraints();
    
    // Clear the table
    DB::table('machines')->truncate(); 

    // ... your seeding logic ...

    // Re-enable checks
    Schema::enableForeignKeyConstraints();
}