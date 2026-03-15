<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Category;
use App\Models\Dosage;
use App\Models\Inventory;
use App\Models\Supply;
use App\Models\SupplyItem;
use App\Models\SubCategory;
use App\Models\MonthlyConsumptionReport;
use App\Models\MonthlyConsumptionItem;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'productID',
        'name',
        'category_id',
        'dosage_id',
        // 'movement',
        'is_active',
        'tracert_type'
    ];

    protected $casts = [
        'tracert_type' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            // Find the highest productID in the database
            $maxProductId = self::max('productID');
            
            // If there are existing products, increment the highest productID
            if ($maxProductId) {
                $nextId = (int)$maxProductId + 1;
            } else {
                // Start from 1 if no products exist
                $nextId = 1;
            }
            
            // Format as 6-digit number with leading zeros
            $product->productID = str_pad($nextId, 6, '0', STR_PAD_LEFT);
        });
    }



    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    
    public function dosage()
    {
        return $this->belongsTo(Dosage::class);
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }

    /**
     * Get the supply items that contain this product.
     */
    public function supplyItems()
    {
        return $this->hasMany(SupplyItem::class);
    }

    /**
     * Get the supplies that contain this product.
     */
    public function supplies()
    {
        return $this->belongsToMany(Supply::class, 'supply_items')
            ->withPivot(['quantity', 'status'])
            ->withTimestamps();
    }

    // reorderLevel
    public function reorderLevel()
    {
        return $this->hasOne(ReorderLevel::class);
    }

    /**
     * Get the inventories for the product.
     */
    public function inventories()
    {
        return $this->hasMany(FacilityInventory::class);
    }

    public function items(){
        return $this->hasMany(FacilityInventoryItem::class);
    }

    public function eligible(){
        return $this->hasMany(EligibleItem::class);
    }

    public function facilityInventories(){
        return $this->hasMany(FacilityInventory::class);
    }

    /**
     * Get the monthly consumption reports for this product.
     */
    public function monthlyConsumptionReports()
    {
        return $this->hasManyThrough(
            MonthlyConsumptionReport::class,
            MonthlyConsumptionItem::class,
            'product_id', // Foreign key on facility_monthly_report_items table
            'id', // Local key on facility_monthly_reports table
            'id', // Local key on products table
            'parent_id' // Foreign key on facility_monthly_report_items table
        );
    }

    /**
     * Get the monthly consumption items for this product.
     */
    public function monthlyConsumptionItems()
    {
        return $this->hasMany(MonthlyConsumptionItem::class);
    }

    /**
     * Get the monthly consumption items for this product in a specific facility.
     */
    public function monthlyConsumptionItemsForFacility()
    {
        return $this->monthlyConsumptionItems()
            ->join('monthly_consumption_reports', 'monthly_consumption_items.parent_id', '=', 'monthly_consumption_reports.id')
            ->where('monthly_consumption_reports.facility_id', auth()->user()->facility_id);
    }

    /**
     * Get AMC data for a specific facility with detailed information
     */
    public function getAMCDataForFacility()
    {
        $facilityId = auth()->user()->facility_id;
        $amc = $this->calculateAMC($facilityId);
        $bufferStock = $this->calculateBufferStock($facilityId);
        $reorderLevel = $this->calculateReorderLevel($facilityId);
        
        return [
            'amc' => $amc,
            'buffer_stock' => $bufferStock,
            'reorder_level' => $reorderLevel,
            'facility_id' => $facilityId
        ];
    }

    /**
     * Check if there are any monthly consumption reports available for this product
     */
    public function hasConsumptionData($facilityId = null)
    {
        try {
            $query = $this->monthlyConsumptionItems()
                ->join('facility_monthly_reports', 'facility_monthly_report_items.parent_id', '=', 'facility_monthly_reports.id')
                ->where('facility_monthly_reports.status', 'approved')
                ->where('facility_monthly_report_items.stock_issued', '>', 0);
            
            if ($facilityId) {
                $query->where('facility_monthly_reports.facility_id', $facilityId);
            }
            
            $count = $query->count();
            
            return $count >= 3;
            
        } catch (\Exception $e) {
            \Log::warning("Error checking consumption data for product {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate a fallback reorder level when no consumption data is available
     * This method provides reasonable defaults based on current inventory
     */
    public function calculateFallbackReorderLevel($facilityId = null)
    {
        try {
            // Check if this product has ANY consumption data for the facility
            $hasAnyConsumption = $this->monthlyConsumptionItems()
                ->join('monthly_consumption_reports', 'monthly_consumption_items.parent_id', '=', 'monthly_consumption_reports.id')
                ->where('monthly_consumption_reports.facility_id', $facilityId)
                ->where('monthly_consumption_items.quantity', '>', 0)
                ->exists();
            
            // If no consumption data exists at all, return 0 (not a static value)
            if (!$hasAnyConsumption) {
                return 0;
            }
            
            // If we have some consumption data but less than 3 months, 
            // we could potentially use a different fallback strategy
            // For now, return 0 to indicate insufficient data
            return 0;
            
        } catch (\Exception $e) {
            \Log::warning("Error calculating fallback reorder level for product {$this->id}: " . $e->getMessage());
            return 0; // Return 0 on error, not a static value
        }
    }

    /**
     * Calculate AMC using Monthly Consumption Reports (Primary) 
     * or Movement data (Fallback)
     */
    public function calculateAMC($facilityId = null)
    {
        try {
            if (!$facilityId) {
                $facilityId = auth()->user()->facility_id ?? null;
            }
            
            if (!$facilityId) {
                return [
                    'amc' => 0,
                    'max_amc' => 0,
                    'months_used' => 0,
                    'selected_months' => []
                ];
            }

            // Attempt to calculate AMC using AMCService (Consumption Reports)
            $amcService = new \App\Services\AMCService();
            $result = $amcService->calculateScreenedAMC($facilityId, $this->id);

            if ($result['amc'] > 0 || $result['eligible_months_count'] > 0) {
                return [
                    'amc' => $result['amc'],
                    'max_amc' => $result['max_amc'],
                    'months_used' => $result['eligible_months_count'],
                    'selected_months' => $result['months_breakdown']
                ];
            }
            
            // Fallback to movement data if no consumption reports exist
            $weeklyData = $this->calculateWeeklyUsageData($facilityId);
            $totalIssued = $weeklyData['total'];
            
            return [
                'amc' => $totalIssued,
                'max_amc' => $weeklyData['max_weekly'] * 4,
                'months_used' => 1,
                'selected_months' => []
            ];
            
        } catch (\Exception $e) {
            \Log::error("Error calculating AMC for product {$this->id}: " . $e->getMessage());
            return [
                'amc' => 0,
                'max_amc' => 0,
                'months_used' => 0,
                'selected_months' => []
            ];
        }
    }

    /**
     * Calculate weekly usage data for the last 28 days
     */
    public function calculateWeeklyUsageData($facilityId = null)
    {
        if (!$facilityId) {
            $facilityId = auth()->user()->facility_id ?? null;
        }

        if (!$facilityId) {
            return ['w1' => 0, 'w2' => 0, 'w3' => 0, 'w4' => 0, 'total' => 0, 'max_weekly' => 0];
        }

        $endDate = Carbon::now();
        $startDate = (clone $endDate)->subDays(28);

        $movements = FacilityInventoryMovement::where('facility_id', $facilityId)
            ->where('product_id', $this->id)
            ->where('movement_type', 'facility_issued')
            ->whereBetween('movement_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();

        $weeks = [
            'w1' => 0, // Most recent week
            'w2' => 0,
            'w3' => 0,
            'w4' => 0,
        ];

        foreach ($movements as $movement) {
            $date = Carbon::parse($movement->movement_date);
            $daysAgo = $date->diffInDays($endDate);

            if ($daysAgo < 7) {
                $weeks['w1'] += $movement->facility_issued_quantity;
            } elseif ($daysAgo < 14) {
                $weeks['w2'] += $movement->facility_issued_quantity;
            } elseif ($daysAgo < 21) {
                $weeks['w3'] += $movement->facility_issued_quantity;
            } elseif ($daysAgo < 28) {
                $weeks['w4'] += $movement->facility_issued_quantity;
            }
        }

        $weeks['total'] = array_sum($weeks);
        $weeks['max_weekly'] = max($weeks['w1'], $weeks['w2'], $weeks['w3'], $weeks['w4']);

        return $weeks;
    }

    /**
     * Calculate buffer stock (Safety Stock) using Facility formula:
     * [(MAX Weekly Usage / 7) * Max Lead Time] - (Average Daily Usage * Average Lead Time)
     * Default Lead Times: Avg = 15 days, Max = 20 days
     */
    public function calculateBufferStock($facilityId = null)
    {
        try {
            $weeklyData = $this->calculateWeeklyUsageData($facilityId);
            $amcData = $this->calculateAMC($facilityId);
            
            $amc = is_array($amcData) ? $amcData['amc'] : 0;
            
            $avgDailyUsage = $amc / 30;
            $maxWeeklyUsage = $weeklyData['max_weekly'];
            $maxDailyFromWeekly = $maxWeeklyUsage / 7;
            
            $avgLeadTime = 15;
            $maxLeadTime = 20;

            // Formula: [(MAX Weekly Usage/7) X Max Lead Time] - (Average Daily Usage X Average Lead Time)
            $safetyStock = ($maxDailyFromWeekly * $maxLeadTime) - ($avgDailyUsage * $avgLeadTime);
            
            return round(max(0, $safetyStock), 2);
            
        } catch (\Exception $e) {
            \Log::error("Error calculating safety stock for product {$this->id}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Calculate reorder level using Facility formula:
     * (Average Daily Usage * Average Lead Time) + Safety Stock
     */
    public function calculateReorderLevel($facilityId = null)
    {
        try {
            $amcData = $this->calculateAMC($facilityId);
            $amc = is_array($amcData) ? $amcData['amc'] : 0;
            
            if ($amc == 0) {
                return 0;
            }
            
            $avgDailyUsage = $amc / 30;
            $avgLeadTime = 15;
            $safetyStock = $this->calculateBufferStock($facilityId);
            
            // Formula: (Average Daily Usage x Average Lead Time in Days) + Safety Stock
            $reorderLevel = ($avgDailyUsage * $avgLeadTime) + $safetyStock;
            
            return round($reorderLevel, 2);
            
        } catch (\Exception $e) {
            \Log::error("Error calculating reorder level for product {$this->id}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Fast and simple method to calculate AMC, Buffer Stock, and Reorder Level
     * This replaces the complex percentage deviation screening with a simple approach
     */
    public function calculateInventoryMetrics($facilityId = null)
    {
        try {
            if (!$facilityId) {
                $facilityId = auth()->user()->facility_id ?? null;
            }
            
            $amcData = $this->calculateAMC($facilityId);
            $amc = is_array($amcData) ? ($amcData['amc'] ?? 0) : 0;
            
            // Safety stock and reorder level should follow the same AMC logic
            $safetyStock = $this->calculateBufferStock($facilityId);
            $reorderLevel = $this->calculateReorderLevel($facilityId);
            
            return [
                'amc' => $amc,
                'buffer_stock' => $safetyStock,
                'reorder_level' => $reorderLevel
            ];

        } catch (\Exception $e) {
            \Log::error("Error calculating inventory metrics for product {$this->id}: " . $e->getMessage());
            
            return [
                'amc' => 0,
                'buffer_stock' => 0,
                'reorder_level' => 0
            ];
        }
    }

    /**
     * Get the inventory structure for frontend
     */
    public function getInventoryStructureAttribute()
    {
        // Get inventory items directly for this product
        $inventoryItems = $this->items;
        
        // Calculate all inventory metrics in one optimized call
        $metrics = $this->calculateInventoryMetrics();
        
        // Calculate the current status based on total quantity and reorder level
        // EXACTLY matching the frontend getInventoryStatus logic
        $totalQuantity = $inventoryItems->sum('quantity');
        $reorderLevel = $metrics['reorder_level'];
        
        $status = 'in_stock'; // default
        
        // Check if completely out of stock first
        if ($totalQuantity <= 0) {
            $status = 'out_of_stock';
        } elseif ($amc > 0 && $totalQuantity > ($amc * 5)) {
            // Over-stock for Facility is > AMC × 5
            $status = 'over_stock';
        } elseif ($reorderLevel <= 0) {
            $status = 'in_stock';
        } else {
            // Critical Threshold = Reorder Level – 30%
            $criticalThreshold = $reorderLevel * 0.7;
            
            if ($totalQuantity <= $criticalThreshold) {
                $status = 'low_stock';
            } elseif ($totalQuantity <= $reorderLevel) {
                $status = 'reorder_level';
            } else {
                $status = 'in_stock';
            }
        }
        
        return [
            'id' => $this->id,
            'product_id' => $this->id,
            'items' => $inventoryItems, // This will be empty array if no items exist
            'amc' => $metrics['amc'],
            'buffer_stock' => $metrics['buffer_stock'],
            'reorder_level' => $metrics['reorder_level'],
            'status' => $status, // Add calculated status
            'product' => [
                'id' => $this->id,
                'name' => $this->name,
                'category' => [
                    'id' => $this->category->id ?? null,
                    'name' => $this->category->name ?? null
                ],
                'dosage' => [
                    'id' => $this->dosage->id ?? null,
                    'name' => $this->dosage->name ?? null
                ]
            ]
        ];
    }


}
