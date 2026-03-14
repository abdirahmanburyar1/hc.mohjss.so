<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Facility;
use App\Models\FacilityMonthlyReport;
use App\Models\FacilityMonthlyReportItem;
use App\Models\FacilityInventoryMovement;
use App\Services\LmisReportFromMovementsService;
use App\Services\AMCService;
use App\Models\FacilityInventoryItem;
use App\Models\Product;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Jobs\GenerateMonthlyInventoryReportJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Display the reports dashboard (filter layout aligned with warehouse report filter)
     */
    public function index(Request $request)
    {
        $reportTypes = [
            ['value' => 'facility_monthly_consumption', 'label' => 'Facility LMIS Report'],
            ['value' => 'order_report', 'label' => 'Order Report'],
            ['value' => 'transfer_report', 'label' => 'Transfer Report'],
            ['value' => 'expired_report', 'label' => 'Expired Report'],
            ['value' => 'liquidation_disposal_report', 'label' => 'Liquidation & Disposals Report'],
        ];

        $reportPeriodOptions = [
            ['value' => 'monthly', 'label' => 'Monthly'],
            ['value' => 'bi-monthly', 'label' => 'Bi-monthly'],
            ['value' => 'quarterly', 'label' => 'Quarterly'],
            ['value' => 'six_months', 'label' => 'Six months'],
            ['value' => 'yearly', 'label' => 'Yearly'],
        ];

        return Inertia::render('Reports/Index', [
            'reportTypes' => $reportTypes,
            'reportPeriodOptions' => $reportPeriodOptions,
            'filters' => $request->only(['report_type', 'report_period', 'year', 'month']),
        ]);
    }

    /**
     * Unified data endpoint for facility reports
     */
    public function unifiedData(Request $request)
    {
        $request->validate([
            'report_type' => 'required|in:facility_monthly_consumption,transfer_report,order_report,inventory_movements,expired_report,liquidation_disposal_report',
            'report_period' => 'nullable|in:monthly,bi-monthly,quarterly,six_months,yearly',
            'year' => 'nullable|integer|min:2020|max:' . (now()->year + 1),
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $type = $request->report_type;
        $facilityId = auth()->user()->facility_id;

        if (!$facilityId) {
            return response()->json(['message' => 'User does not belong to a facility.'], 403);
        }

        $startDate = $request->start_date;
        $endDate = $request->end_date;
        $dateRangeTypes = ['transfer_report', 'order_report', 'inventory_movements', 'liquidation_disposal_report'];
        if (in_array($type, $dateRangeTypes) && (!$startDate || !$endDate)) {
            $year = (int) ($request->year ?? now()->year);
            $month = (int) ($request->month ?? now()->month);
            $period = $request->report_period ?? 'monthly';
            $range = $this->dateRangeFromPeriod($year, $month, $period);
            $startDate = $startDate ?? $range['start'];
            $endDate = $endDate ?? $range['end'];
        }

        try {
            if ($type === 'facility_monthly_consumption') {
                $year = $request->year ?? now()->year;
                $month = $request->month ?? now()->month;
                $period = sprintf('%04d-%02d', $year, $month);

                $report = FacilityMonthlyReport::with([
                    'facility',
                    'items.product.category',
                    'approvedBy',
                    'reviewedBy',
                    'submittedBy',
                    'rejectedBy'
                ])
                ->where('report_period', $period)
                ->where('facility_id', $facilityId)
                ->first();

                if (!$report) {
                    return response()->json([
                        'type' => 'facility_monthly_consumption',
                        'data' => null,
                        'message' => 'No report found for the specified period.'
                    ]);
                }

                $this->ensureLmisItemsHaveAmc($report);
                return response()->json([
                    'type' => 'facility_monthly_consumption',
                    'data' => $report
                ]);

            } elseif ($type === 'transfer_report') {
                $query = Transfer::with([
                    'fromFacility:id,name',
                    'items.inventory_allocations:id,transfer_item_id,transfer_reason'
                ])
                    ->where('from_facility_id', $facilityId);

                if ($startDate) {
                    $query->whereDate('transfer_date', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('transfer_date', '<=', $endDate);
                }
                if ($request->filled('search')) {
                    $search = $request->search;
                    $query->where(function ($q) use ($search) {
                        $q->where('transferID', 'like', "%{$search}%")
                            ->orWhere('note', 'like', "%{$search}%");
                    });
                }

                $transfers = $query->orderBy('transfer_date', 'desc')->get();

                $facility = Facility::find($facilityId);
                $facilityName = $facility ? $facility->name : '–';

                $totalTransfers = $transfers->count();
                $completedTransfers = $transfers->where('status', 'received')->count();
                $rejectedTransfers = $transfers->where('status', 'rejected')->count();
                $completedPct = $totalTransfers > 0 ? round($completedTransfers / $totalTransfers * 100, 1) : 0;
                $rejectedPct = $totalTransfers > 0 ? round($rejectedTransfers / $totalTransfers * 100, 1) : 0;

                $reasonOverstock = 0;
                $reasonSoonToExpire = 0;
                $reasonSlowMoving = 0;
                foreach ($transfers as $transfer) {
                    foreach ($transfer->items as $item) {
                        foreach ($item->inventory_allocations ?? [] as $alloc) {
                            $r = strtolower(trim((string) ($alloc->transfer_reason ?? '')));
                            if (str_contains($r, 'overstock')) {
                                $reasonOverstock++;
                            } elseif (str_contains($r, 'soon') || str_contains($r, 'expire')) {
                                $reasonSoonToExpire++;
                            } elseif (str_contains($r, 'slow') || str_contains($r, 'moving')) {
                                $reasonSlowMoving++;
                            }
                        }
                    }
                }

                $typeLabels = [
                    'Warehouse to Facility' => 'type_warehouse_to_facility',
                    'Warehouse to Warehouse' => 'type_warehouse_to_warehouse',
                    'Facility to Warehouse' => 'type_facility_to_warehouse',
                    'Facility to Facility' => 'type_facility_to_facility',
                ];
                $typeCounts = array_fill_keys(array_values($typeLabels), 0);
                foreach ($transfers as $transfer) {
                    $type = $transfer->transfer_type ?? '';
                    if (isset($typeLabels[$type])) {
                        $typeCounts[$typeLabels[$type]]++;
                    }
                }

                $data = [
                    'facility_name' => $facilityName,
                    'total_transfers' => $totalTransfers,
                    'completed_transfers' => $completedTransfers,
                    'completed_pct' => $completedPct,
                    'rejected_transfers' => $rejectedTransfers,
                    'rejected_pct' => $rejectedPct,
                    'reason_overstock' => $reasonOverstock,
                    'reason_soon_to_expire' => $reasonSoonToExpire,
                    'reason_slow_moving' => $reasonSlowMoving,
                    'type_warehouse_to_facility' => $typeCounts['type_warehouse_to_facility'],
                    'type_warehouse_to_warehouse' => $typeCounts['type_warehouse_to_warehouse'],
                    'type_facility_to_warehouse' => $typeCounts['type_facility_to_warehouse'],
                    'type_facility_to_facility' => $typeCounts['type_facility_to_facility'],
                ];

                return response()->json([
                    'type' => 'transfer_report',
                    'data' => $data
                ]);

            } elseif ($type === 'order_report') {
                $query = Order::with('items')
                    ->where('facility_id', $facilityId);

                if ($startDate) {
                    $query->whereDate('order_date', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('order_date', '<=', $endDate);
                }
                if ($request->filled('search')) {
                    $search = $request->search;
                    $query->where(function ($q) use ($search) {
                        $q->where('order_number', 'like', "%{$search}%")
                            ->orWhere('notes', 'like', "%{$search}%")
                            ->orWhere('note', 'like', "%{$search}%");
                    });
                }

                $orders = $query->orderBy('order_date', 'desc')->get();

                $totalOrders = $orders->count();
                $completedOrders = $orders->where('status', 'received')->count();
                $rejectedOrders = $orders->where('status', 'rejected')->count();
                $completedPct = $totalOrders > 0 ? round($completedOrders / $totalOrders * 100, 1) : 0;
                $rejectedPct = $totalOrders > 0 ? round($rejectedOrders / $totalOrders * 100, 1) : 0;

                $receivedOrders = $orders->where('status', 'received');
                $receivedCount = $receivedOrders->count();
                $ontimeCount = 0;
                $lateCount = 0;
                foreach ($receivedOrders as $order) {
                    if ($order->expected_date && $order->received_at) {
                        $expectedLimit = Carbon::parse($order->expected_date)->addDays(2);
                        if (Carbon::parse($order->received_at)->lte($expectedLimit)) {
                            $ontimeCount++;
                        } else {
                            $lateCount++;
                        }
                    }
                }
                $ontimePct = $receivedCount > 0 ? round($ontimeCount / $receivedCount * 100, 1) : 0;
                $latePct = $receivedCount > 0 ? round($lateCount / $receivedCount * 100, 1) : 0;

                $ordersWithItems = $orders->filter(fn ($o) => $o->items->isNotEmpty());
                $ordersWithItemsCount = $ordersWithItems->count();
                $itemsGood = 0;
                $itemsFair = 0;
                $itemsPoor = 0;
                $qtyGood = 0;
                $qtyFair = 0;
                $qtyPoor = 0;
                foreach ($ordersWithItems as $order) {
                    $itemsTotal = $order->items->count();
                    $itemsSupplied = $order->items->where(fn ($i) => (float) ($i->quantity_to_release ?? 0) > 0)->count();
                    $itemsRate = $itemsTotal > 0 ? ($itemsSupplied / $itemsTotal) * 100 : 0;
                    if ($itemsRate > 90) {
                        $itemsGood++;
                    } elseif ($itemsRate >= 80) {
                        $itemsFair++;
                    } else {
                        $itemsPoor++;
                    }

                    $qtyOrdered = $order->items->sum(fn ($i) => (float) ($i->quantity ?? 0));
                    $qtyReleased = $order->items->sum(fn ($i) => (float) ($i->quantity_to_release ?? 0));
                    $qtyRate = $qtyOrdered > 0 ? ($qtyReleased / $qtyOrdered) * 100 : 0;
                    if ($qtyRate > 90) {
                        $qtyGood++;
                    } elseif ($qtyRate >= 80) {
                        $qtyFair++;
                    } else {
                        $qtyPoor++;
                    }
                }
                $itemsGoodPct = $ordersWithItemsCount > 0 ? round($itemsGood / $ordersWithItemsCount * 100, 0) : 0;
                $itemsFairPct = $ordersWithItemsCount > 0 ? round($itemsFair / $ordersWithItemsCount * 100, 0) : 0;
                $itemsPoorPct = $ordersWithItemsCount > 0 ? round($itemsPoor / $ordersWithItemsCount * 100, 0) : 0;
                $qtyGoodPct = $ordersWithItemsCount > 0 ? round($qtyGood / $ordersWithItemsCount * 100, 0) : 0;
                $qtyFairPct = $ordersWithItemsCount > 0 ? round($qtyFair / $ordersWithItemsCount * 100, 0) : 0;
                $qtyPoorPct = $ordersWithItemsCount > 0 ? round($qtyPoor / $ordersWithItemsCount * 100, 0) : 0;

                $data = [
                    'total_orders' => $totalOrders,
                    'completed_orders' => $completedOrders,
                    'completed_pct' => $completedPct,
                    'rejected_orders' => $rejectedOrders,
                    'rejected_pct' => $rejectedPct,
                    'delivery_ontime_count' => $ontimeCount,
                    'delivery_ontime_pct' => $ontimePct,
                    'delivery_late_count' => $lateCount,
                    'delivery_late_pct' => $latePct,
                    'items_fulfillment_good_pct' => $itemsGoodPct,
                    'items_fulfillment_fair_pct' => $itemsFairPct,
                    'items_fulfillment_poor_pct' => $itemsPoorPct,
                    'qty_fulfillment_good_pct' => $qtyGoodPct,
                    'qty_fulfillment_fair_pct' => $qtyFairPct,
                    'qty_fulfillment_poor_pct' => $qtyPoorPct,
                ];

                return response()->json([
                    'type' => 'order_report',
                    'data' => $data
                ]);

            } elseif ($type === 'inventory_movements') {
                $query = FacilityInventoryMovement::with([
                    'facility:id,name',
                    'product:id,name',
                    'createdBy:id,name'
                ])->where('facility_id', $facilityId);

                if ($startDate) {
                    $query->whereDate('movement_date', '>=', $startDate);
                }
                if ($endDate) {
                    $query->whereDate('movement_date', '<=', $endDate);
                }

                $data = $query->orderBy('movement_date', 'desc')->paginate($request->get('per_page', 25));

                return response()->json([
                    'type' => 'inventory_movements',
                    'data' => $data
                ]);

            } elseif ($type === 'expired_report') {
                // Same logic as ExpiredController and warehouse Expiry Report: 180 and 365 days
                $today = Carbon::today()->startOfDay();
                $in180Days = $today->copy()->addDays(180);
                $in365Days = $today->copy()->addDays(365);

                $itemsQuery = FacilityInventoryItem::query()
                    ->whereHas('inventory', function ($q) use ($facilityId) {
                        $q->where('facility_id', $facilityId);
                    })
                    ->where('quantity', '>', 0)
                    ->whereNotNull('expiry_date')
                    ->where(function ($q) use ($today, $in365Days) {
                        $q->where('expiry_date', '<=', $in365Days)
                          ->orWhere('expiry_date', '<', $today);
                    });

                // Optional filters to match Expired page behaviour
                if ($request->filled('tab')) {
                    $tab = $request->tab;
                    if ($tab === 'expired') {
                        $itemsQuery->where('expiry_date', '<', $today);
                    } elseif ($tab === 'six_months') {
                        $itemsQuery->where('expiry_date', '>=', $today)
                            ->where('expiry_date', '<=', $in180Days);
                    } elseif ($tab === 'year') {
                        $itemsQuery->where('expiry_date', '>', $in180Days)
                            ->where('expiry_date', '<=', $in365Days);
                    }
                }

                if ($request->filled('expiry_status')) {
                    $status = $request->expiry_status;
                    if ($status === 'expired') {
                        $itemsQuery->where('expiry_date', '<', $today);
                    } elseif ($status === 'expiring_very_soon') {
                        $itemsQuery->where('expiry_date', '>', $today)
                            ->where('expiry_date', '<=', $in180Days);
                    } elseif ($status === 'expiring_soon') {
                        $itemsQuery->where('expiry_date', '>', $in180Days)
                            ->where('expiry_date', '<=', $in365Days);
                    }
                }

                if ($request->filled('search')) {
                    $search = $request->search;
                    $itemsQuery->where(function ($q) use ($search) {
                        $q->where('barcode', 'like', "%{$search}%")
                            ->orWhere('batch_number', 'like', "%{$search}%")
                            ->orWhereHas('product', function ($prodQ) use ($search) {
                                $prodQ->where('name', 'like', "%{$search}%");
                            });
                    });
                }

                // Summary only (no per-item/product rows): match warehouse unified expiry report table behaviour
                // Prefer stored total_cost when meaningful; fall back to unit_cost × quantity for current batch stock
                $sumExpr = DB::raw('COALESCE(NULLIF(total_cost, 0), COALESCE(unit_cost,0) * COALESCE(quantity,0))');
                $expiredQuery = (clone $itemsQuery)->where('expiry_date', '<', $today);
                $within6Query = (clone $itemsQuery)->where('expiry_date', '>', $today)->where('expiry_date', '<=', $in180Days);
                $within1YearQuery = (clone $itemsQuery)->where('expiry_date', '>', $in180Days)->where('expiry_date', '<=', $in365Days);

                $row = [
                    'expiring_1_year_item_no' => (int) $within1YearQuery->count(),
                    'expiring_1_year_value' => round((float) $within1YearQuery->sum($sumExpr), 2),
                    'expiring_6_months_item_no' => (int) $within6Query->count(),
                    'expiring_6_months_value' => round((float) $within6Query->sum($sumExpr), 2),
                    'expired_item_no' => (int) $expiredQuery->count(),
                    'expired_value' => round((float) $expiredQuery->sum($sumExpr), 2),
                ];

                return response()->json([
                    'type' => 'expired_report',
                    'data' => ['rows' => [$row]]
                ]);

            } elseif ($type === 'liquidation_disposal_report') {
                $facility = \App\Models\Facility::find($facilityId);
                $facilityName = $facility ? $facility->name : null;
                $warehouseNames = collect();
                if ($facilityName) {
                    $warehouseNames = \App\Models\Liquidate::where('facility', $facilityName)
                        ->whereNotNull('warehouse')->where('warehouse', '!=', '')
                        ->distinct()->pluck('warehouse');
                    $dispWarehouses = \App\Models\Disposal::where('facility', $facilityName)
                        ->whereNotNull('warehouse')->where('warehouse', '!=', '')
                        ->distinct()->pluck('warehouse');
                    $warehouseNames = $warehouseNames->merge($dispWarehouses)->unique()->values()->sort()->values();
                }
                $aggregateRows = [];
                foreach ($warehouseNames as $wn) {
                    $liqQuery = \App\Models\Liquidate::where('facility', $facilityName)->where('warehouse', $wn);
                    $dispQuery = \App\Models\Disposal::where('facility', $facilityName)->where('warehouse', $wn);
                    if ($startDate) {
                        $liqQuery->whereDate('liquidated_at', '>=', $startDate);
                        $dispQuery->whereDate('disposed_at', '>=', $startDate);
                    }
                    if ($endDate) {
                        $liqQuery->whereDate('liquidated_at', '<=', $endDate);
                        $dispQuery->whereDate('disposed_at', '<=', $endDate);
                    }
                    if ($request->filled('status')) {
                        $liqQuery->where('status', $request->status);
                        $dispQuery->where('status', $request->status);
                    }
                    $liquidateIds = (clone $liqQuery)->pluck('id');
                    $disposalIds = (clone $dispQuery)->pluck('id');
                    $liquidatedItemNo = \App\Models\LiquidateItem::whereIn('liquidate_id', $liquidateIds)->sum('quantity');
                    $liquidatedTotalValue = \App\Models\LiquidateItem::whereIn('liquidate_id', $liquidateIds)->sum('total_cost');
                    $disposedItemNo = \App\Models\DisposalItem::whereIn('disposal_id', $disposalIds)->sum('quantity');
                    $disposedTotalValue = \App\Models\DisposalItem::whereIn('disposal_id', $disposalIds)->sum('total_cost');
                    $liqMissing = (clone $liqQuery)->where(function ($q) {
                        $q->where('rejection_reason', 'like', '%missing%')->orWhere('rejection_reason', 'like', '%Missing%');
                    })->count();
                    $liqLost = (clone $liqQuery)->where(function ($q) {
                        $q->where('rejection_reason', 'like', '%lost%')->orWhere('rejection_reason', 'like', '%Lost%');
                    })->count();
                    $dispDamage = (clone $dispQuery)->where(function ($q) {
                        $q->where('rejection_reason', 'like', '%damage%')->orWhere('rejection_reason', 'like', '%Damage%');
                    })->count();
                    $dispExpired = (clone $dispQuery)->where(function ($q) {
                        $q->where('rejection_reason', 'like', '%expired%')->orWhere('rejection_reason', 'like', '%Expired%');
                    })->count();
                    $aggregateRows[] = [
                        'warehouse_name' => $wn,
                        'liquidated_item_no' => (int) $liquidatedItemNo,
                        'liquidated_total_value' => round((float) $liquidatedTotalValue, 2),
                        'disposed_item_no' => (int) $disposedItemNo,
                        'disposed_total_value' => round((float) $disposedTotalValue, 2),
                        'liquidation_reason_missing' => $liqMissing,
                        'liquidation_reason_lost' => $liqLost,
                        'disposal_reason_damage' => $dispDamage,
                        'disposal_reason_expired' => $dispExpired,
                    ];
                }
                if ($warehouseNames->isEmpty()) {
                    $aggregateRows[] = [
                        'warehouse_name' => $facilityName ?: '–',
                        'liquidated_item_no' => 0,
                        'liquidated_total_value' => 0,
                        'disposed_item_no' => 0,
                        'disposed_total_value' => 0,
                        'liquidation_reason_missing' => 0,
                        'liquidation_reason_lost' => 0,
                        'disposal_reason_damage' => 0,
                        'disposal_reason_expired' => 0,
                    ];
                }
                return response()->json([
                    'type' => 'liquidation_disposal_report',
                    'data' => ['aggregateByWarehouse' => $aggregateRows]
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Error generating unified report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching report data.'
            ], 500);
        }
    }

    /**
     * Derive start/end date from report period + year + month (month = first month of period for non-monthly).
     */
    private function dateRangeFromPeriod(int $year, int $month, string $period): array
    {
        $start = Carbon::createFromDate($year, $month, 1);
        switch ($period) {
            case 'bi-monthly':
                $end = $start->copy()->addMonth()->endOfMonth();
                break;
            case 'quarterly':
                $end = $start->copy()->addMonths(2)->endOfMonth();
                break;
            case 'six_months':
                $end = $start->copy()->addMonths(5)->endOfMonth();
                break;
            case 'yearly':
                $end = $start->copy()->endOfYear();
                break;
            default:
                $end = $start->copy()->endOfMonth();
                break;
        }
        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    /**
     * Display the monthly inventory report interface
     */
    public function monthlyInventory()
    {
        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return redirect()->route('reports.monthly-inventory')
                ->with('error', 'No facility assigned to your account.');
        }

        return Inertia::render('Reports/MonthlyInventory', [
            'facility' => [
                'id' => $currentFacility->id,
                'name' => $currentFacility->name,
                'facility_type' => $currentFacility->facility_type,
            ],
            'currentYear' => now()->year,
            'currentMonth' => now()->month,
        ]);
    }

    /**
     * Generate monthly inventory report
     */
    public function generateMonthlyReport(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:' . (now()->year + 1),
            'month' => 'required|integer|min:1|max:12',
            'force' => 'boolean',
            'use_selected_unit_only' => 'boolean'
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return redirect()->route('reports.monthly-inventory')
                ->with('error', 'No facility assigned to your account.');
        }

        $facilityId = $currentFacility->id;
        $year = $request->year;
        $month = $request->month;
        $force = $request->boolean('force', false);

        try {
            // Dispatch the job to generate the report
            $reportPeriod = sprintf('%04d-%02d', $year, $month);
            GenerateMonthlyInventoryReportJob::dispatch($facilityId, $reportPeriod, $force);

            return response()->json([
                'success' => true,
                'message' => 'Monthly inventory report generation started. You will be notified when it\'s complete.',
                'report_period' => $reportPeriod
            ]);

        } catch (\Exception $e) {
            Log::error('Error generating monthly report: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to start report generation. Please try again.'
            ], 500);
        }
    }

    /**
     * View generated monthly inventory report
     */
    public function viewMonthlyReport(Request $request)
    {
        $request->validate([
            'report_period' => 'required|string',
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return redirect()->route('reports.monthly-inventory')
                ->with('error', 'No facility assigned to your account.');
        }

        $facilityId = $currentFacility->id;
        $reportPeriod = $request->report_period;

        // Get the report data using report_period field
        $query = FacilityMonthlyReport::with(['facility', 'items.product','approvedBy','reviewedBy','submittedBy','rejectedBy'])
            ->where('report_period', $reportPeriod)
            ->where('facility_id', $facilityId)
            ->first();

        if(!$query){
            return redirect()->route('reports.monthly-inventory')
                ->with('error', 'No report found for the specified period.');
        }

        return Inertia::render('Reports/ViewMonthlyInventory', [
            'reports' => $query,
            'facility' => $currentFacility,
            'reportPeriod' => $reportPeriod,
            'monthName' => Carbon::createFromFormat('Y-m', $reportPeriod)->format('F Y'),
            'isApproved' => $query->status === 'approved',
            'noReportsFound' => false,
            'message' => null,
            'canApprove' => true
        ]);
    }

    /**
     * Export monthly inventory report as Excel
     */
    public function exportMonthlyReportExcel(Request $request)
    {
        $request->validate([
            'report_period' => 'required|string',
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return redirect()->route('reports.monthly-inventory')
                ->with('error', 'No facility assigned to your account.');
        }

        $facilityId = $currentFacility->id;
        $reportPeriod = $request->report_period;

        // Get the report data
        $query = FacilityMonthlyReport::with(['facility', 'items.product'])
            ->where('report_period', $reportPeriod)
            ->where('facility_id', $facilityId);

        $reports = $query->get();

        if ($reports->isEmpty()) {
            return redirect()->route('reports.monthly-inventory')
                ->with('error', 'No reports found for the specified period.');
        }

        // Prepare Excel data
        $excelData = [];
        
        // Add headers
        $excelData[] = [
            'Item / Strength/Dose /Dosage Form',
            'Unit of Measurement',
            'Beginning Balance',
            'Qty Received',
            'Qty Consumed',
            'Positive Adjustment',
            'Negative Adjustment',
            'Closing Balance',
            'Stockout Days'
        ];

        // Add data rows
        foreach ($reports as $report) {
            foreach ($report->items as $item) {
                $excelData[] = [
                    $item->product->name,
                    $item->product->unit ?? 'Units',
                    $item->opening_balance ?? 0,
                    $item->stock_received ?? 0,
                    $item->stock_issued ?? 0,
                    $item->positive_adjustments ?? 0,
                    $item->negative_adjustments ?? 0,
                    $item->closing_balance ?? 0,
                    $item->stockout_days ?? 0
                ];
            }
        }

        // Create CSV content (simple Excel format)
        $filename = 'LMIS_Monthly_Report_' . $currentFacility->name . '_' . $reportPeriod . '.csv';
        
        $callback = function() use ($excelData) {
            $file = fopen('php://output', 'w');
            
            foreach ($excelData as $row) {
                fputcsv($file, $row);
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Export monthly inventory report as PDF
     */
    public function exportMonthlyReportPdf(Request $request)
    {
        // Implementation for PDF export
        // This would use Laravel PDF package
        return response()->json(['message' => 'PDF export feature coming soon']);
    }

    /**
     * Get eligible products for the current user's facility
     */
    public function getTemplateProducts(Request $request)
    {
        try {
            $facility = auth()->user()->facility;
            if (!$facility) {
                return response()->json([
                    'success' => false,
                    'message' => 'No facility assigned to your account.',
                    'products' => []
                ], 403);
            }

            // Products eligible for this facility (by facility_type)
            $products = $facility->eligibleProducts()
                ->select('products.id', 'products.name')
                ->orderBy('products.name')
                ->get();

            return response()->json([
                'success' => true,
                'products' => $products,
                'count' => $products->count()
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching eligible products for facility', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch eligible products'
            ], 500);
        }
    }

    /**
     * Get report generation status
     */
    public function getReportStatus(Request $request)
    {
        $request->validate([
            'report_period' => 'required|string',
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility assigned to your account.'
            ], 403);
        }

        $facilityId = $currentFacility->id;
        $reportPeriod = $request->report_period;

        try {
            // Get the report with all relationships
            $report = FacilityMonthlyReport::where('report_period', $reportPeriod)
                ->where('facility_id', $facilityId)
                ->with([
                    'facility', 
                    'submittedBy', 
                    'reviewedBy',
                    'approvedBy',
                    'rejectedBy'
                ])
                ->first();

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'exists' => false,
                    'message' => 'Report not found for this period.'
                ], 404);
            }

            // Calculate summary statistics using database aggregations
            $summaryStats = FacilityMonthlyReportItem::where('parent_id', $report->id)
                ->selectRaw('
                    COUNT(*) as total_items,
                    COUNT(DISTINCT product_id) as total_products,
                    COALESCE(SUM(opening_balance), 0) as total_opening_balance,
                    COALESCE(SUM(closing_balance), 0) as total_closing_balance,
                    COALESCE(SUM(stock_received), 0) as total_received,
                    COALESCE(SUM(stock_issued), 0) as total_issued
                ')
                ->first();

            // Get user permissions
            $permissions = [
                'can_submit' => true,
                'can_review' => true,
                'can_approve' => true,
                'can_edit' => true,
            ];

            // Build audit trail using relationships
            $auditTrail = collect();
            
            if ($report->submitted_at && $report->submittedBy) {
                $auditTrail->push([
                    'action' => 'submitted',
                    'timestamp' => $report->submitted_at->format('Y-m-d H:i:s'),
                    'user' => $report->submittedBy->name,
                    'status' => 'Submitted for Review'
                ]);
            }

            if ($report->reviewed_at && $report->reviewedBy) {
                $auditTrail->push([
                    'action' => 'reviewed',
                    'timestamp' => $report->reviewed_at->format('Y-m-d H:i:s'),
                    'user' => $report->reviewedBy->name,
                    'status' => 'Reviewed'
                ]);
            }

            if ($report->approved_at && $report->approvedBy) {
                $auditTrail->push([
                    'action' => 'approved',
                    'timestamp' => $report->approved_at->format('Y-m-d H:i:s'),
                    'user' => $report->approvedBy->name,
                    'status' => 'Approved'
                ]);
            }

            if ($report->rejected_at && $report->rejectedBy) {
                $auditTrail->push([
                    'action' => 'rejected',
                    'timestamp' => $report->rejected_at->format('Y-m-d H:i:s'),
                    'user' => $report->rejectedBy->name,
                    'status' => 'Rejected',
                    'comments' => $report->comments
                ]);
            }

            return response()->json([
                'success' => true,
                'exists' => true,
                'report' => [
                    'id' => $report->id,
                    'facility_id' => $report->facility_id,
                    'report_period' => $report->report_period,
                    'status' => $report->status,
                    'comments' => $report->comments,
                    'created_at' => $report->created_at,
                    'updated_at' => $report->updated_at,
                ],
                'summary' => [
                    'total_items' => (int) $summaryStats->total_items,
                    'total_products' => (int) $summaryStats->total_products,
                    'total_opening_balance' => (float) $summaryStats->total_opening_balance,
                    'total_closing_balance' => (float) $summaryStats->total_closing_balance,
                    'total_received' => (float) $summaryStats->total_received,
                    'total_issued' => (float) $summaryStats->total_issued,
                ],
                'audit_trail' => $auditTrail->sortBy('timestamp')->values(),
                'facility' => [
                    'id' => $report->facility->id,
                    'name' => $report->facility->name,
                    'facility_type' => $report->facility->facility_type,
                ],
                'permissions' => $permissions
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting report status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving report status.'
            ], 500);
        }
    }

    /**
     * Update individual report item (adjustments and stockout days)
     */
    public function updateReportItem(Request $request)
    {
        $request->validate([
            'item_id' => 'required|integer|exists:facility_monthly_report_items,id',
            'positive_adjustments' => 'nullable|numeric|min:0',
            'negative_adjustments' => 'nullable|numeric|min:0',
            'stockout_days' => 'nullable|integer|min:0',
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility assigned to your account.'
            ], 403);
        }

        // Find the report item and verify it belongs to user's facility
        $reportItem = FacilityMonthlyReportItem::with('report')
            ->where('id', $request->item_id)
            ->first();

        if (!$reportItem || $reportItem->report->facility_id !== $currentFacility->id) {
            return response()->json([
                'success' => false,
                'message' => 'Report item not found or access denied.'
            ], 404);
        }

        // Check if report is still editable (not approved)
        if ($reportItem->report->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot edit approved reports.'
            ], 403);
        }

        // Update the item
        $reportItem->update([
            'positive_adjustments' => $request->positive_adjustments ?? 0,
            'negative_adjustments' => $request->negative_adjustments ?? 0,
            'stockout_days' => $request->stockout_days ?? 0,
        ]);

        // Recalculate closing balance
        $reportItem->closing_balance = $reportItem->opening_balance 
            + $reportItem->stock_received 
            - $reportItem->stock_issued 
            + $reportItem->positive_adjustments 
            - $reportItem->negative_adjustments;
        
        $reportItem->save();

        return response()->json([
            'success' => true,
            'message' => 'Report item updated successfully.',
            'item' => $reportItem
        ]);
    }

    /**
     * Save report (update status or other report-level changes)
     */
    public function saveReport(Request $request)
    {
        $request->validate([
            'report_period' => 'required|string',
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return redirect()->route('reports.monthly-inventory')
                ->with('error', 'No facility assigned to your account.');
        }

        $facilityId = $currentFacility->id;
        $reportPeriod = $request->report_period;

        // Find the report
        $report = FacilityMonthlyReport::where('report_period', $reportPeriod)
            ->where('facility_id', $facilityId)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found.'
            ], 404);
        }

        // Update report timestamp
        $report->touch();

        return response()->json([
            'success' => true,
            'message' => 'Report saved successfully.',
            'report' => $report
        ]);
    }

    /**
     * Submit monthly report for review
     */
    public function submitMonthlyReport(Request $request)
    {
        $request->validate([
            'report_id' => 'required|integer|exists:facility_monthly_reports,id',
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility assigned to your account.'
            ], 403);
        }

        // Find the report and verify ownership
        $report = FacilityMonthlyReport::where('id', $request->report_id)
            ->where('facility_id', $currentFacility->id)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found or access denied.'
            ], 404);
        }

        // Check if report can be submitted
        if ($report->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft reports can be submitted for review.'
            ], 400);
        }

        // Update report status
        $report->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => auth()->id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Report submitted for review successfully.',
            'report' => $report
        ]);
    }

    /**
     * Approve monthly report
     */
    public function approveMonthlyReport(Request $request)
    {
        $request->validate([
            'report_id' => 'required|integer|exists:facility_monthly_reports,id'
        ]);

        try {
            $report = FacilityMonthlyReport::where('id', $request->report_id)
                ->where('facility_id', auth()->user()->facility_id)
                ->first();

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report not found or you do not have permission to access it.'
                ], 404);
            }

            // Only allow approval from reviewed status
            if ($report->status !== 'reviewed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Report must be reviewed before it can be approved.'
                ], 400);
            }

            // Check permission
            // Permission check removed - all users can approve reports

            $report->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Report approved successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error('Error approving report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while approving the report.'
            ], 500);
        }
    }

    /**
     * Reject monthly report
     */
    public function rejectMonthlyReport(Request $request)
    {
        $request->validate([
            'report_id' => 'required|integer|exists:facility_monthly_reports,id',
            'comments' => 'nullable|string|max:1000'
        ]);

        try {
            $report = FacilityMonthlyReport::where('id', $request->report_id)
                ->where('facility_id', auth()->user()->facility_id)
                ->first();

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report not found or you do not have permission to access it.'
                ], 404);
            }

            // Allow rejection from submitted or reviewed status
            if (!in_array($report->status, ['submitted', 'reviewed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only submitted or reviewed reports can be rejected.'
                ], 400);
            }

            // Check permission
            // Permission check removed - all users can reject reports

            $report->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by' => auth()->id(),
                'comments' => $request->comments,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Report rejected successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error('Error rejecting report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while rejecting the report.'
            ], 500);
        }
    }

    /**
     * Return a monthly report to draft status
     */
    public function returnMonthlyReportToDraft(Request $request)
    {
        $request->validate([
            'report_id' => 'required|integer|exists:facility_monthly_reports,id'
        ]);

        try {
            $report = FacilityMonthlyReport::where('id', $request->report_id)
                ->where('facility_id', auth()->user()->facility_id)
                ->first();

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report not found or you do not have permission to access it.'
                ], 404);
            }

            if ($report->status !== 'submitted') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only submitted reports can be returned to draft.'
                ], 400);
            }

            $report->update([
                'status' => 'draft',
                'submitted_at' => null,
                'submitted_by' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Report has been returned to draft successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error('Error returning report to draft: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while returning the report to draft.'
            ], 500);
        }
    }

    /**
     * Reopen an approved monthly report for editing
     */
    public function reopenMonthlyReport(Request $request)
    {
        $request->validate([
            'report_id' => 'required|integer|exists:facility_monthly_reports,id'
        ]);

        try {
            $report = FacilityMonthlyReport::where('id', $request->report_id)
                ->where('facility_id', auth()->user()->facility_id)
                ->first();

            if (!$report) {
                return response()->json([
                    'success' => false,
                    'message' => 'Report not found or you do not have permission to access it.'
                ], 404);
            }

            if ($report->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved reports can be reopened.'
                ], 400);
            }

            $report->update([
                'status' => 'draft',
                'approved_at' => null,
                'approved_by' => null,
                'reopened_at' => now(),
                'reopened_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Report has been reopened for editing successfully.'
            ]);

        } catch (\Exception $e) {
            Log::error('Error reopening report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while reopening the report.'
            ], 500);
        }
    }

    /**
     * Start review of a monthly inventory report
     */
    public function startMonthlyReportReview(Request $request)
    {
        $request->validate([
            'report_id' => 'required|integer|exists:facility_monthly_reports,id',
        ]);

        // Get current user's facility
        $currentFacility = auth()->user()->facility;
        
        if (!$currentFacility) {
            return response()->json([
                'success' => false,
                'message' => 'No facility assigned to your account.'
            ], 403);
        }

        // Find the report and verify it belongs to user's facility
        $report = FacilityMonthlyReport::where('id', $request->report_id)
            ->where('facility_id', $currentFacility->id)
            ->first();

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => 'Report not found or access denied.'
            ], 404);
        }

        // Check if report is in submitted status
        if ($report->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Report must be in submitted status to start review.'
            ], 400);
        }

        // // Check if user has review permission
        // if (!auth()->user()->can('lmis.review')) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'You do not have permission to review reports.'
        //     ], 403);
        // }

        try {
            // Update report status to reviewed
            $report->update([
                'status' => 'reviewed',
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Report has been reviewed successfully.',
                'report' => [
                    'id' => $report->id,
                    'status' => $report->status,
                    'reviewed_at' => $report->reviewed_at,
                    'reviewed_by' => $report->reviewed_by,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display facility inventory movements with filtering options
     */
    public function inventoryMovements(Request $request)
    {
        // Check if any filters are applied
        $hasFilters = $request->filled('product_id') || 
                     $request->filled('movement_type') || 
                     $request->filled('source_type') || 
                     $request->filled('start_date') || 
                     $request->filled('end_date');

        // Initialize empty paginated result
        $movements = new \Illuminate\Pagination\LengthAwarePaginator(
            [],
            0,
            $request->get('per_page', 25),
            1,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );

        // Only fetch data if filters are applied
        if ($hasFilters) {
            $query = FacilityInventoryMovement::with([
                'facility:id,name',
                'product:id,name',
                'createdBy:id,name'
            ])->where('facility_id', auth()->user()->facility_id);

            // Apply filters
            if ($request->filled('product_id') && is_array($request->product_id)) {
                $query->whereIn('product_id', $request->product_id);
            }

            if ($request->filled('movement_type') && is_array($request->movement_type)) {
                $query->whereIn('movement_type', $request->movement_type);
            }

            if ($request->filled('source_type') && is_array($request->source_type)) {
                $query->whereIn('source_type', $request->source_type);
            }

            if ($request->filled('start_date')) {
                $query->whereDate('movement_date', '>=', $request->start_date);
            }

            if ($request->filled('end_date')) {
                $query->whereDate('movement_date', '<=', $request->end_date);
            }

            // Order by movement date descending
            $query->orderBy('movement_date', 'desc');

            // Get per page value, default to 25
            $perPage = $request->get('per_page', 25);
            $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 25;

            $movements = $query->paginate($perPage)->withQueryString();
        }

        // Get products for filter options
        $products = Product::select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Reports/InventoryMovements', [
            'movements' => $movements,
            'products' => $products,
        ]);
    }

    /**
     * Get facility inventory summary for movements
    */
    
    public function inventoryMovementsSummary(Request $request)
    {
        $query = FacilityInventoryMovement::where('facility_id', auth()->user()->facility_id);
        
        // Apply filters if provided
        if ($request->filled('product_id') && is_array($request->product_id)) {
            $query->whereIn('product_id', $request->product_id);
        }

        if ($request->filled('movement_type') && is_array($request->movement_type)) {
            $query->whereIn('movement_type', $request->movement_type);
        }

        if ($request->filled('source_type') && is_array($request->source_type)) {
            $query->whereIn('source_type', $request->source_type);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('movement_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('movement_date', '<=', $request->end_date);
        }

        // Get summary data
        $summary = [
            'total_received' => $query->clone()->where('movement_type', 'facility_received')->sum('quantity') ?: 0,
            'total_issued' => $query->clone()->where('movement_type', 'facility_issued')->sum('quantity') ?: 0,
            'received_count' => $query->clone()->where('movement_type', 'facility_received')->count(),
            'issued_count' => $query->clone()->where('movement_type', 'facility_issued')->count(),
        ];

        return response()->json($summary);
    }

    /**
     * Export inventory movements to CSV
     */
    public function exportInventoryMovements(Request $request)
    {
        $query = FacilityInventoryMovement::with([
            'facility:id,name',
            'product:id,name',
            'createdBy:id,name'
        ])->where('facility_id', auth()->user()->facility_id);

        // Apply the same filters as index method
        if ($request->filled('product_id') && is_array($request->product_id)) {
            $query->whereIn('product_id', $request->product_id);
        }

        if ($request->filled('movement_type') && is_array($request->movement_type)) {
            $query->whereIn('movement_type', $request->movement_type);
        }

        if ($request->filled('source_type') && is_array($request->source_type)) {
            $query->whereIn('source_type', $request->source_type);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('movement_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('movement_date', '<=', $request->end_date);
        }

        $movements = $query->orderBy('movement_date', 'desc')->get();

        $filename = 'inventory_movements_' . now()->format('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function() use ($movements) {
            $file = fopen('php://output', 'w');
            
            // Add CSV headers
            fputcsv($file, [
                'Date',
                'Item',
                'Movement Type',
                'Source Type',
                'Quantity',
                'Batch Number',
                'Expiry Date',
                'Reference Number',
                'Created By',
                'Created At'
            ]);

            // Add data rows
            foreach ($movements as $movement) {
                fputcsv($file, [
                    $movement->movement_date,
                    $movement->product->name ?? '',
                    $movement->movement_type == 'facility_received' ? 'Received' : 'Issued',
                    $movement->source_type,
                    $movement->quantity ?: '',
                    $movement->batch_number ?: '',
                    $movement->expiry_date ?: '',
                    $movement->reference_number ?: '',
                    $movement->createdBy->name ?? '',
                    $movement->created_at
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Display facility transfers with filtering options.
     *
     * Data source: transfers table (Transfer model). The facility_inventory_movements table
     * is the canonical log of what actually moved (source_type='transfer', source_id=transfer_id,
     * reference_number=transferID, movement_date). Movements are written when a transfer is
     * received/issued via FacilityInventoryMovementService. This report shows transfer headers;
     * for movement-level detail use the Inventory Movements report filtered by source_type.
     */
    public function transfers(Request $request)
    {
        $user = auth()->user();
        $facilityId = $user->facility_id;

        $perPage = $request->input('per_page', 15);

        $query = Transfer::with([
            'toWarehouse:id,name',
            'fromWarehouse:id,name', 
            'fromFacility:id,name',
            'toFacility:id,name',
            'createdBy:id,name',
            'approvedBy:id,name',
            'dispatchedBy:id,name',
            'rejectedBy:id,name',
            'items.product:id,name'
        ])
        ->where(function($q) use ($facilityId) {
            $q->where('from_facility_id', $facilityId)
              ->orWhere('to_facility_id', $facilityId);
        });

        // Apply filters
        if ($request->filled('status') && is_array($request->status)) {
            $query->whereIn('status', $request->status);
        }

        if ($request->filled('transfer_type')) {
            if ($request->transfer_type === 'outgoing') {
                $query->where('from_facility_id', $facilityId);
            } elseif ($request->transfer_type === 'incoming') {
                $query->where('to_facility_id', $facilityId);
            }
        }

        if ($request->filled('start_date')) {
            $query->whereDate('transfer_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('transfer_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transferID', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%")
                  ->orWhereHas('toWarehouse', function($wq) use ($search) {
                      $wq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('fromWarehouse', function($wq) use ($search) {
                      $wq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('toFacility', function($fq) use ($search) {
                      $fq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('fromFacility', function($fq) use ($search) {
                      $fq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $transfers = $query->orderBy('transfer_date', 'desc')
                          ->paginate($perPage)
                          ->withQueryString();

        return Inertia::render('Reports/Transfers', [
            'transfers' => $transfers,
            'filters' => $request->only(['status', 'transfer_type', 'start_date', 'end_date', 'search', 'per_page']),
            'status_options' => ['pending', 'approved', 'dispatched', 'received', 'rejected', 'cancelled'],
        ]);
    }

    /**
     * Get transfers summary for the facility
     */
    public function transfersSummary(Request $request)
    {
        $user = auth()->user();
        $facilityId = $user->facility_id;

        $query = Transfer::where(function($q) use ($facilityId) {
            $q->where('from_facility_id', $facilityId)
              ->orWhere('to_facility_id', $facilityId);
        });

        // Apply date filters if provided
        if ($request->filled('start_date')) {
            $query->whereDate('transfer_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('transfer_date', '<=', $request->end_date);
        }

        $summary = [
            'total_transfers' => $query->count(),
            'outgoing_transfers' => $query->clone()->where('from_facility_id', $facilityId)->count(),
            'incoming_transfers' => $query->clone()->where('to_facility_id', $facilityId)->count(),
            'pending' => $query->clone()->where('status', 'pending')->count(),
            'approved' => $query->clone()->where('status', 'approved')->count(),
            'dispatched' => $query->clone()->where('status', 'dispatched')->count(),
            'received' => $query->clone()->where('status', 'received')->count(),
            'rejected' => $query->clone()->where('status', 'rejected')->count(),
        ];

        return response()->json($summary);
    }

    /**
     * Export transfers report to CSV
     */
    public function exportTransfers(Request $request)
    {
        $user = auth()->user();
        $facilityId = $user->facility_id;

        $query = Transfer::with([
            'toWarehouse:id,name',
            'fromWarehouse:id,name', 
            'fromFacility:id,name',
            'toFacility:id,name',
            'createdBy:id,name',
            'approvedBy:id,name',
            'dispatchedBy:id,name',
            'rejectedBy:id,name',
            'items.product:id,name'
        ])
        ->where(function($q) use ($facilityId) {
            $q->where('from_facility_id', $facilityId)
              ->orWhere('to_facility_id', $facilityId);
        });

        // Apply the same filters as the main report
        if ($request->filled('status') && is_array($request->status)) {
            $query->whereIn('status', $request->status);
        }

        if ($request->filled('transfer_type')) {
            if ($request->transfer_type === 'outgoing') {
                $query->where('from_facility_id', $facilityId);
            } elseif ($request->transfer_type === 'incoming') {
                $query->where('to_facility_id', $facilityId);
            }
        }

        if ($request->filled('start_date')) {
            $query->whereDate('transfer_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('transfer_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transferID', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        $transfers = $query->orderBy('transfer_date', 'desc')->get();

        $filename = 'transfers_report_' . now()->format('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function() use ($transfers) {
            $file = fopen('php://output', 'w');
            
            // Add CSV headers
            fputcsv($file, [
                'Transfer ID',
                'Transfer Date',
                'Status',
                'From',
                'To',
                'Items Count',
                'Total Quantity',
                'Created By',
                'Approved By',
                'Dispatched By',
                'Note'
            ]);

            // Add data rows
            foreach ($transfers as $transfer) {
                $from = '';
                if ($transfer->fromWarehouse) {
                    $from = $transfer->fromWarehouse->name . ' (Warehouse)';
                } elseif ($transfer->fromFacility) {
                    $from = $transfer->fromFacility->name . ' (Facility)';
                }

                $to = '';
                if ($transfer->toWarehouse) {
                    $to = $transfer->toWarehouse->name . ' (Warehouse)';
                } elseif ($transfer->toFacility) {
                    $to = $transfer->toFacility->name . ' (Facility)';
                }

                $totalQuantity = $transfer->items->sum('quantity');

                fputcsv($file, [
                    $transfer->transferID ?: '',
                    $transfer->transfer_date ?: '',
                    $transfer->status ?: '',
                    $from,
                    $to,
                    $transfer->items->count(),
                    $totalQuantity,
                    $transfer->createdBy->name ?? '',
                    $transfer->approvedBy->name ?? '',
                    $transfer->dispatchedBy->name ?? '',
                    $transfer->note ?: ''
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Display orders report.
     *
     * Data source: orders table (Order model). The facility_inventory_movements table
     * records actual receipts (source_type='order', source_id=order_id, reference_number=order_number).
     * Movements are written when an order is received via FacilityInventoryMovementService::recordOrderReceived().
     * This report shows order headers; for movement-level detail use Inventory Movements filtered by source_type.
     */
    public function orders(Request $request)
    {
        $facilityId = auth()->user()->facility_id;
        
        $query = Order::with([
            'facility:id,name',
            'user:id,name',
            'items.product:id,name'
        ])
        ->where('facility_id', $facilityId);

        // Apply filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('order_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('order_date', '<=', $request->end_date);
        }

        $orders = $query->orderBy('order_date', 'desc')
                       ->paginate($request->get('per_page', 15))
                       ->withQueryString();

        return Inertia::render('Reports/Orders', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'order_type', 'start_date', 'end_date', 'per_page']),
            'summary' => $this->ordersSummary($request)->getData()
        ]);
    }

    /**
     * Get orders summary statistics
     */
    public function ordersSummary(Request $request)
    {
        $facilityId = auth()->user()->facility_id;
        
        $query = Order::where('facility_id', $facilityId);

        // Apply same filters as main query
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('order_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('order_date', '<=', $request->end_date);
        }

        $summary = [
            'total_orders' => $query->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
        ];

        return response()->json($summary);
    }

    /**
     * Export orders to CSV
     */
    public function exportOrders(Request $request)
    {
        $facilityId = auth()->user()->facility_id;
        
        $query = Order::with([
            'facility:id,name',
            'user:id,name',
            'items.product:id,name'
        ])
        ->where('facility_id', $facilityId);

        // Apply same filters as main query
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('order_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('order_date', '<=', $request->end_date);
        }

        $orders = $query->orderBy('order_date', 'desc')->get();

        $filename = 'orders-report-' . now()->format('Y-m-d-H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($orders) {
            $file = fopen('php://output', 'w');
            
            // Add CSV headers
            fputcsv($file, [
                'Order Number',
                'Order Date',
                'Order Type',
                'Status',
                'Items Count',
                'Total Quantity',
                'Created By',
                'Expected Date',
                'Notes'
            ]);

            // Add data rows
            foreach ($orders as $order) {
                fputcsv($file, [
                    $order->order_number ?? 'N/A',
                    $order->order_date ? \Carbon\Carbon::parse($order->order_date)->format('Y-m-d') : 'N/A',
                    ucfirst($order->order_type ?? 'N/A'),
                    ucfirst($order->status ?? 'N/A'),
                    $order->items->count(),
                    $order->items->sum('quantity'),
                    $order->user->name ?? 'N/A',
                    $order->expected_date ? \Carbon\Carbon::parse($order->expected_date)->format('Y-m-d') : 'N/A',
                    $order->notes ?? $order->note ?? ''
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Facility LMIS Report (dedicated page) - current user's facility, select period.
     * Loads from facility_monthly_reports (fed by facility_inventory_movements via Create LMIS Report).
     */
    public function facilityLmisReport(Request $request)
    {
        $user = auth()->user();
        $facility = $user->facility;
        if (!$facility) {
            return redirect()->route('reports.index')
                ->with('error', 'No facility assigned to your account.');
        }

        $monthYear = $request->get('month_year', now()->format('Y-m'));
        $report = null;

        if ($monthYear) {
            $report = FacilityMonthlyReport::with([
                'facility',
                'items.product:id,name',
                'items.product.category:id,name',
                'generatedBy:id,name',
                'approvedBy:id,name',
                'reviewedBy:id,name',
                'submittedBy:id,name',
                'rejectedBy:id,name',
            ])
                ->where('facility_id', $facility->id)
                ->where('report_period', $monthYear)
                ->first();
            if ($report) {
                $this->ensureLmisItemsHaveAmc($report);
            }
        }

        $products = $facility->eligibleProducts()->select('products.id', 'products.name')->orderBy('products.name')->get();

        return Inertia::render('Reports/FacilityLmisReport', [
            'reports' => $report,
            'facility' => $facility,
            'products' => $products,
            'filters' => $request->only(['month_year']),
        ]);
    }

    /**
     * Create LMIS report from facility_inventory_movements (draft). Triggered by "Create LMIS Report" button.
     */
    public function createLmisReport(Request $request)
    {
        $request->validate([
            'month_year' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        $user = auth()->user();
        $facility = $user->facility;
        if (!$facility) {
            return redirect()->route('reports.index')
                ->with('error', 'No facility assigned to your account.');
        }

        $service = new LmisReportFromMovementsService();
        $report = $service->generate($facility->id, $request->month_year, $user->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'LMIS report created successfully. You can submit it for approval when ready.',
                'report' => $report,
            ]);
        }

        return redirect()->route('reports.facility-lmis-report', ['month_year' => $request->month_year])
            ->with('success', 'LMIS report created successfully. You can submit it for approval when ready.');
    }

    /**
     * Ensure LMIS report items have dynamic AMC (screened) for the frontend AMC column.
     *
     * Instead of relying on the persisted average_monthly_consumption value, we
     * recalculate AMC from monthly_consumption_items using the same screened
     * algorithm as the warehouse (AMCService / AmcCalculationService).
     */
    private function ensureLmisItemsHaveAmc(FacilityMonthlyReport $report): void
    {
        if (!$report->relationLoaded('items')) {
            return;
        }

        $facilityId = (int) $report->facility_id;
        if (!$facilityId) {
            return;
        }

        $amcService = new AMCService();

        // Compute AMC once per product to avoid repeated queries
        $productIds = $report->items
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        $amcByProduct = [];
        foreach ($productIds as $productId) {
            try {
                // Use dynamic AMC based on historical monthly_consumption_items
                // Screening starts only when we have 3+ months; with 1–2 months it uses the simple average.
                // AMCService itself excludes the *current calendar month* (same as warehouse logic).
                $result = $amcService->calculateScreenedAMC($facilityId, (int) $productId);
                $amcByProduct[$productId] = $result['amc'] ?? 0;
            } catch (\Throwable $e) {
                // Fail-soft: log and fall back to 0 so the report still loads
                Log::warning('Failed to calculate AMC for facility LMIS item', [
                    'facility_id' => $facilityId,
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
                $amcByProduct[$productId] = 0;
            }
        }

        foreach ($report->items as $item) {
            $pid = $item->product_id;
            $item->setAttribute('amc', $pid ? ($amcByProduct[$pid] ?? 0) : null);
        }
    }

    /**
     * Combined Liquidation & Disposals report page - list both for facility
     */
    public function liquidationDisposalReport(Request $request)
    {
        $facilityId = auth()->user()->facility_id;
        if (!$facilityId) {
            return redirect()->route('reports.index')->with('error', 'No facility assigned.');
        }

        $perPage = $request->get('per_page', 25);
        $facility = auth()->user()->facility;

        // Liquidations
        $liquidQuery = \App\Models\Liquidate::with([
            'items.product:id,name',
            'items.product.category:id,name',
            'items.product.dosage:id,name',
            'liquidatedBy:id,name',
        ]);
        if ($facility && $facility->name) {
            $liquidQuery->where('facility', $facility->name);
        }
        if ($request->filled('status')) {
            $liquidQuery->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $liquidQuery->whereDate('liquidated_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $liquidQuery->whereDate('liquidated_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $liquidQuery->where(function ($q) use ($s) {
                $q->where('liquidate_id', 'like', "%{$s}%")->orWhere('note', 'like', "%{$s}%");
            });
        }
        $liquidations = $liquidQuery->orderBy('liquidated_at', 'desc')
            ->paginate($perPage, ['*'], 'liquid_page')->withQueryString();

        $liquidBaseQuery = \App\Models\Liquidate::query();
        if ($facility && $facility->name) {
            $liquidBaseQuery->where('facility', $facility->name);
        }
        $liquidationSummary = [
            'total_liquidations' => $liquidBaseQuery->count(),
            'approved_count' => (clone $liquidBaseQuery)->where('status', 'approved')->count(),
            'rejected_count' => (clone $liquidBaseQuery)->where('status', 'rejected')->count(),
            'pending_count' => (clone $liquidBaseQuery)->where('status', 'pending')->count(),
            'total_value' => 0,
        ];

        // Disposals
        $dispQuery = \App\Models\Disposal::with([
            'items.product:id,name',
            'items.product.category:id,name',
            'items.product.dosage:id,name',
            'disposedBy:id,name',
        ]);
        if ($request->filled('status')) {
            $dispQuery->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $dispQuery->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $dispQuery->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $dispQuery->where(function ($q) use ($s) {
                $q->where('disposal_id', 'like', "%{$s}%")->orWhere('note', 'like', "%{$s}%");
            });
        }
        $disposals = $dispQuery->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'disp_page')->withQueryString();

        $disposalSummary = [
            'total_disposals' => \App\Models\Disposal::count(),
            'approved_count' => \App\Models\Disposal::where('status', 'approved')->count(),
            'rejected_count' => \App\Models\Disposal::where('status', 'rejected')->count(),
            'pending_count' => \App\Models\Disposal::where('status', 'pending')->count(),
            'total_value' => 0,
        ];

        $filters = $request->only([
            'status', 'date_from', 'date_to', 'search', 'per_page',
            'liquid_page', 'disp_page',
        ]);

        // Aggregate by warehouse for summary table (Warehouse Name | Total Liquidated Items | Total Disposed Items | Reasons)
        $facilityName = $facility ? $facility->name : null;
        $warehouseNames = collect();
        if ($facilityName) {
            $warehouseNames = \App\Models\Liquidate::where('facility', $facilityName)
                ->whereNotNull('warehouse')->where('warehouse', '!=', '')
                ->distinct()->pluck('warehouse');
            $dispWarehouses = \App\Models\Disposal::where('facility', $facilityName)
                ->whereNotNull('warehouse')->where('warehouse', '!=', '')
                ->distinct()->pluck('warehouse');
            $warehouseNames = $warehouseNames->merge($dispWarehouses)->unique()->values()->sort()->values();
        }
        $aggregateRows = [];
        foreach ($warehouseNames as $wn) {
            $liqQuery = \App\Models\Liquidate::where('facility', $facilityName)->where('warehouse', $wn);
            $dispQuery = \App\Models\Disposal::where('facility', $facilityName)->where('warehouse', $wn);
            if ($request->filled('date_from')) {
                $liqQuery->whereDate('liquidated_at', '>=', $request->date_from);
                $dispQuery->whereDate('disposed_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $liqQuery->whereDate('liquidated_at', '<=', $request->date_to);
                $dispQuery->whereDate('disposed_at', '<=', $request->date_to);
            }
            if ($request->filled('status')) {
                $liqQuery->where('status', $request->status);
                $dispQuery->where('status', $request->status);
            }
            $liquidateIds = (clone $liqQuery)->pluck('id');
            $disposalIds = (clone $dispQuery)->pluck('id');
            $liquidatedItemNo = \App\Models\LiquidateItem::whereIn('liquidate_id', $liquidateIds)->sum('quantity');
            $liquidatedTotalValue = \App\Models\LiquidateItem::whereIn('liquidate_id', $liquidateIds)->sum('total_cost');
            $disposedItemNo = \App\Models\DisposalItem::whereIn('disposal_id', $disposalIds)->sum('quantity');
            $disposedTotalValue = \App\Models\DisposalItem::whereIn('disposal_id', $disposalIds)->sum('total_cost');
            $liqMissing = (clone $liqQuery)->where(function ($q) {
                $q->where('rejection_reason', 'like', '%missing%')->orWhere('rejection_reason', 'like', '%Missing%');
            })->count();
            $liqLost = (clone $liqQuery)->where(function ($q) {
                $q->where('rejection_reason', 'like', '%lost%')->orWhere('rejection_reason', 'like', '%Lost%');
            })->count();
            $dispDamage = (clone $dispQuery)->where(function ($q) {
                $q->where('rejection_reason', 'like', '%damage%')->orWhere('rejection_reason', 'like', '%Damage%');
            })->count();
            $dispExpired = (clone $dispQuery)->where(function ($q) {
                $q->where('rejection_reason', 'like', '%expired%')->orWhere('rejection_reason', 'like', '%Expired%');
            })->count();
            $aggregateRows[] = [
                'warehouse_name' => $wn,
                'liquidated_item_no' => (int) $liquidatedItemNo,
                'liquidated_total_value' => round((float) $liquidatedTotalValue, 2),
                'disposed_item_no' => (int) $disposedItemNo,
                'disposed_total_value' => round((float) $disposedTotalValue, 2),
                'liquidation_reason_missing' => $liqMissing,
                'liquidation_reason_lost' => $liqLost,
                'disposal_reason_damage' => $dispDamage,
                'disposal_reason_expired' => $dispExpired,
            ];
        }
        if ($warehouseNames->isEmpty()) {
            $aggregateRows[] = [
                'warehouse_name' => $facilityName ?: '–',
                'liquidated_item_no' => 0,
                'liquidated_total_value' => 0,
                'disposed_item_no' => 0,
                'disposed_total_value' => 0,
                'liquidation_reason_missing' => 0,
                'liquidation_reason_lost' => 0,
                'disposal_reason_damage' => 0,
                'disposal_reason_expired' => 0,
            ];
        }

        return Inertia::render('Reports/LiquidationDisposal/Index', [
            'liquidations' => $liquidations,
            'disposals' => $disposals,
            'liquidationSummary' => $liquidationSummary,
            'disposalSummary' => $disposalSummary,
            'aggregateByWarehouse' => $aggregateRows,
            'filters' => $filters,
            'sources' => ['Transfer', 'Order', 'Back Order', 'Expired'],
        ]);
    }

    /**
     * Liquidation report page - list liquidations for facility
     */
    public function liquidationReport(Request $request)
    {
        $facilityId = auth()->user()->facility_id;
        if (!$facilityId) {
            return redirect()->route('reports.index')->with('error', 'No facility assigned.');
        }

        $perPage = $request->get('per_page', 25);
        $facility = auth()->user()->facility;
        $query = \App\Models\Liquidate::with([
            'items.product:id,name',
            'items.product.category:id,name',
            'items.product.dosage:id,name',
            'liquidatedBy:id,name',
        ]);
        if ($facility && $facility->name) {
            $query->where('facility', $facility->name);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('liquidated_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('liquidated_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('liquidate_id', 'like', "%{$s}%")->orWhere('note', 'like', "%{$s}%");
            });
        }

        $liquidations = $query->orderBy('liquidated_at', 'desc')->paginate($perPage)->withQueryString();

        $baseQuery = \App\Models\Liquidate::query();
        if ($facility && $facility->name) {
            $baseQuery->where('facility', $facility->name);
        }
        $summary = [
            'total_liquidations' => $baseQuery->count(),
            'approved_count' => (clone $baseQuery)->where('status', 'approved')->count(),
            'rejected_count' => (clone $baseQuery)->where('status', 'rejected')->count(),
            'pending_count' => (clone $baseQuery)->where('status', 'pending')->count(),
            'total_value' => 0,
        ];

        return Inertia::render('Reports/LiquidationDisposal/Liquidation', [
            'liquidations' => $liquidations,
            'summary' => $summary,
            'filters' => $request->only(['status', 'date_from', 'date_to', 'search', 'per_page', 'page']),
            'sources' => ['Transfer', 'Order', 'Back Order'],
        ]);
    }

    /**
     * Disposal report page - list disposals for facility
     */
    public function disposalReport(Request $request)
    {
        $facilityId = auth()->user()->facility_id;
        if (!$facilityId) {
            return redirect()->route('reports.index')->with('error', 'No facility assigned.');
        }

        $perPage = $request->get('per_page', 25);
        $query = \App\Models\Disposal::with([
            'items.product:id,name',
            'items.product.category:id,name',
            'items.product.dosage:id,name',
            'disposedBy:id,name',
        ]);
        // Disposal model may not have facility_id; scope can be added when table is decided

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('disposal_id', 'like', "%{$s}%")->orWhere('note', 'like', "%{$s}%");
            });
        }

        $disposals = $query->orderBy('created_at', 'desc')->paginate($perPage)->withQueryString();

        $summary = [
            'total_disposals' => \App\Models\Disposal::count(),
            'approved_count' => \App\Models\Disposal::where('status', 'approved')->count(),
            'rejected_count' => \App\Models\Disposal::where('status', 'rejected')->count(),
            'pending_count' => \App\Models\Disposal::where('status', 'pending')->count(),
            'total_value' => 0,
        ];

        return Inertia::render('Reports/LiquidationDisposal/Disposal', [
            'disposals' => $disposals,
            'summary' => $summary,
            'filters' => $request->only(['status', 'date_from', 'date_to', 'search', 'per_page', 'page']),
            'sources' => ['Transfer', 'Order', 'Back Order', 'Expired'],
        ]);
    }
}
