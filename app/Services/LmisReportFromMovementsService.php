<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\FacilityInventoryMovement;
use App\Models\FacilityMonthlyReport;
use App\Models\FacilityMonthlyReportItem;
use App\Models\FacilityReorderLevel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generate LMIS report (facility_monthly_reports / facility_monthly_report_items) from facility_inventory_movements.
 * Reports all eligible products for the facility; movement data filled from facility_inventory_movements where it exists.
 *
 * AMC (average_monthly_consumption) uses the same screened calculation as InventoryController:
 * AMCService::calculateScreenedAMC (70% deviation screening, 3 closest months from monthly_consumption_items).
 */
class LmisReportFromMovementsService
{
    /**
     * Generate or regenerate LMIS report for a facility and month.
     * Writes to facility_monthly_reports and facility_monthly_report_items.
     * Includes every product eligible for the facility (even with no movements).
     *
     * @return FacilityMonthlyReport
     */
    public function generate(int $facilityId, string $monthYear, ?int $generatedBy = null): FacilityMonthlyReport
    {
        $facility = Facility::find($facilityId);
        if (!$facility) {
            throw new \InvalidArgumentException('Facility not found.');
        }

        $period = Carbon::createFromFormat('Y-m', $monthYear);
        $startDate = $period->copy()->startOfMonth()->startOfDay();
        $endDate = $period->copy()->endOfMonth()->endOfDay();

        $previousMonthYear = $period->copy()->subMonth()->format('Y-m');
        $previousReport = FacilityMonthlyReport::where('facility_id', $facilityId)
            ->where('report_period', $previousMonthYear)
            ->first();
        $previousClosings = [];
        if ($previousReport) {
            $previousClosings = FacilityMonthlyReportItem::where('parent_id', $previousReport->id)
                ->pluck('closing_balance', 'product_id')
                ->toArray();
        }

        $movements = FacilityInventoryMovement::where('facility_id', $facilityId)
            ->whereBetween('movement_date', [$startDate, $endDate])
            ->select([
                'product_id',
                DB::raw('COALESCE(SUM(facility_received_quantity), 0) as received'),
                DB::raw('COALESCE(SUM(facility_issued_quantity), 0) as issued'),
            ])
            ->groupBy('product_id')
            ->get();

        $byProduct = $movements->keyBy('product_id');

        $eligibleProductIds = $facility->eligibleProducts()->pluck('products.id')->toArray();

        $report = FacilityMonthlyReport::updateOrCreate(
            [
                'facility_id' => $facilityId,
                'report_period' => $monthYear,
            ],
            [
                'status' => 'draft',
                'generated_by' => $generatedBy ?? auth()->id(),
            ]
        );

        $amcService = new AMCService();
        $reorderLevelAmcs = FacilityReorderLevel::where('facility_id', $facilityId)
            ->whereIn('product_id', $eligibleProductIds)
            ->pluck('amc', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        foreach ($eligibleProductIds as $productId) {
            $mov = $byProduct->get($productId);
            $received = $mov ? (float) $mov->received : 0;
            $issued = $mov ? (float) $mov->issued : 0;
            $opening = (float) ($previousClosings[$productId] ?? 0);
            $closing = $opening + $received - $issued;

            $amcResult = $amcService->calculateScreenedAMC($facilityId, $productId);
            $amc = (float) ($amcResult['amc'] ?? 0);
            if ($amc <= 0 && isset($reorderLevelAmcs[$productId]) && $reorderLevelAmcs[$productId] > 0) {
                $amc = $reorderLevelAmcs[$productId];
            }
            if ($amc <= 0 && $issued > 0) {
                $amc = $issued;
            }
            $totalClosing = max(0, $closing);
            $monthsOfStock = $amc > 0 ? (string) round($totalClosing / $amc, 1) : null;

            FacilityMonthlyReportItem::updateOrCreate(
                [
                    'parent_id' => $report->id,
                    'product_id' => $productId,
                ],
                [
                    'opening_balance' => max(0, $opening),
                    'stock_received' => $received,
                    'stock_issued' => $issued,
                    'other_quantity_out' => 0,
                    'positive_adjustments' => 0,
                    'negative_adjustments' => 0,
                    'closing_balance' => $totalClosing,
                    'total_closing_balance' => $totalClosing,
                    'average_monthly_consumption' => $amc,
                    'months_of_stock' => $monthsOfStock,
                    'stockout_days' => 0,
                    'quantity_in_pipeline' => 0,
                ]
            );
        }

        return $report->load(['items.product', 'facility', 'generatedBy']);
    }
}
