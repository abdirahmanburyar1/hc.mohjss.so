<?php

use App\Models\Facility;
use App\Models\Product;
use App\Models\FacilityInventory;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Starting inventory initialization for eligible items...\n";

$facilities = Facility::all();
$totalCreated = 0;

foreach ($facilities as $facility) {
    echo "Processing facility: {$facility->name} (Type: {$facility->facility_type})...\n";
    
    // Get all eligible products for this facility type
    $eligibleProducts = Product::whereHas('eligible', function($query) use ($facility) {
        $query->where('facility_type', $facility->facility_type);
    })->where('is_active', true)->get();
    
    $facilityCreated = 0;
    
    foreach ($eligibleProducts as $product) {
        // Check if inventory record already exists
        $exists = FacilityInventory::where('facility_id', $facility->id)
            ->where('product_id', $product->id)
            ->exists();
            
        if (!$exists) {
            FacilityInventory::create([
                'facility_id' => $facility->id,
                'product_id' => $product->id,
                'quantity' => 0
            ]);
            $facilityCreated++;
            $totalCreated++;
        }
    }
    
    if ($facilityCreated > 0) {
        echo "  - Created {$facilityCreated} missing inventory records.\n";
    }
}

echo "\nInitialization complete. Total records created: {$totalCreated}\n";
