<template>
    <Head title="Reports" />
    <AuthenticatedLayout
        title="Reports"
        description="Generate and view all facility reports"
        img="/assets/images/report.png"
    >
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Reports
            </h2>
        </template>

        <div class="py-5">
            <!-- Filters (same layout and behaviour as warehouse report filter) -->
            <div class="bg-blue-50/90 border border-blue-200 rounded-lg shadow-sm p-6 mb-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select Report Type</label>
                        <select
                            v-model="filters.report_type"
                            class="mt-1 block w-full rounded-md border border-gray-300 bg-white shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm py-2"
                        >
                            <option value="">Report Type</option>
                            <option v-for="rt in reportTypes" :key="rt.value" :value="rt.value">{{ rt.label }}</option>
                        </select>
                    </div>
                    <!-- Report Period: Report Period (top), Year + Month/Period (one row) -->
                    <div class="flex flex-col gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Report Period</label>
                            <select
                                v-model="filters.report_period"
                                class="mt-1 block w-full rounded-md border border-gray-300 bg-white shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm py-2"
                            >
                                <option
                                    v-for="opt in reportPeriodOptionsList"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex flex-row gap-3 items-end flex-wrap">
                            <div class="flex-1 min-w-[100px]">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Year</label>
                                <select
                                    v-model="filters.year"
                                    class="mt-1 block w-full rounded-md border border-gray-300 bg-white shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm py-2"
                                >
                                    <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                                </select>
                            </div>
                            <div class="flex-1 min-w-[100px]">
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ periodLabel }}</label>
                                <select
                                    v-model="filters.periodValue"
                                    class="mt-1 block w-full rounded-md border border-gray-300 bg-white shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm py-2"
                                >
                                    <option
                                        v-for="opt in periodOptions"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >
                                        {{ opt.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <button
                        type="button"
                        @click="generateReport"
                        :disabled="generating || !filters.report_type || (isFacilityLmisReport && !hasFacilityLmisRequiredFilters)"
                        class="inline-flex justify-center items-center px-6 py-2.5 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150"
                    >
                        <span v-if="generating">Generating...</span>
                        <span v-else>Generate Report</span>
                    </button>
                    <p v-if="!filters.report_type" class="mt-2 text-xs text-amber-600">
                        Select a report type to continue.
                    </p>
                    <p v-else-if="isFacilityLmisReport && !hasFacilityLmisRequiredFilters" class="mt-2 text-xs text-amber-600">
                        Select Report Period (Year + Month) to generate the Facility LMIS report for your facility.
                    </p>
                </div>
            </div>

            <!-- Search (Transfers, Orders, Expired) -->
            <div v-if="filters.report_type === 'transfer_report' || filters.report_type === 'order_report' || filters.report_type === 'expired_report'" class="mb-4">
                <label class="sr-only">Search</label>
                <div class="relative">
                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input
                        v-model="filters.search"
                        @input="debounceGenerate"
                        type="text"
                        class="block w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        placeholder="Search by item name, barcode, batch number"
                    />
                </div>
            </div>

            <!-- Report Table Content -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200 min-h-[400px]">
                <div v-if="generating" class="p-8 text-center">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Loading report...</p>
                </div>

                <!-- LMIS Monthly Report (always show table when this report type is generated, even if empty) -->
                <div v-else-if="currentReportType === 'facility_monthly_consumption'">
                    <div class="p-4 border-b border-gray-200 bg-gray-50 flex flex-wrap justify-between items-center gap-3">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">LMIS Monthly Report</h3>
                            <p class="text-sm text-gray-500">
                                Period: {{ lmisReportPeriod }} | Status: <span class="font-semibold uppercase">{{ currentData?.status ?? '—' }}</span>
                            </p>
                        </div>
                        <!-- LMIS: only Submit for review and Return to draft (no Mark as reviewed / Reject / Approve) -->
                        <div class="flex flex-wrap items-center gap-2">
                            <template v-if="currentData?.id && currentData?.items?.length">
                                <button
                                    v-if="currentData.status === 'draft'"
                                    type="button"
                                    @click="submitLmisForReview"
                                    :disabled="lmisWorkflowLoading"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-3 rounded-md text-xs uppercase tracking-wide disabled:opacity-50"
                                >
                                    Submit for review
                                </button>
                                <button
                                    v-if="currentData.status === 'submitted'"
                                    type="button"
                                    @click="returnLmisToDraft"
                                    :disabled="lmisWorkflowLoading"
                                    class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-3 rounded-md text-xs uppercase tracking-wide disabled:opacity-50"
                                >
                                    Return to draft
                                </button>
                            </template>
                            <button
                                v-if="!currentData?.items?.length"
                                @click="createLmisReport"
                                :disabled="creatingLmis"
                                class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {{ creatingLmis ? 'Creating...' : 'Create LMIS Report' }}
                            </button>
                            <button
                                @click="exportLMIS"
                                :disabled="!currentData?.items?.length"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md text-sm disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Export Excel
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th rowspan="2" class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">Item</th>
                                    <th rowspan="2" class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">Category</th>
                                    <th rowspan="2" class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">UoM</th>
                                    <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Item Details (batch level)</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">Beginning<br>Balance</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">QTY<br>Received</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">QTY<br>Issued</th>
                                    <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Adjustments</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">Closing<br>Balance</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">Total<br>Closing<br>Balance</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">AMC</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">MOS<br>(Months<br>of Stock)</th>
                                    <th rowspan="2" class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">Stockout<br>Days</th>
                                </tr>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-1 text-left text-xs font-medium text-gray-600 border border-gray-300">Batch No.:</th>
                                    <th class="px-3 py-1 text-left text-xs font-medium text-gray-600 border border-gray-300">Expiry Date</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">(-)</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">(+)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <tr v-for="item in lmisItems" :key="item.id" class="hover:bg-gray-50 border-t border-gray-200">
                                    <td class="px-3 py-2 text-sm text-gray-900 border border-gray-300">{{ item.product?.name || '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 border border-gray-300">{{ item.product?.category?.name || '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 border border-gray-300">{{ item.product?.unit_of_measure || item.product?.uom || '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 border border-gray-300 whitespace-nowrap">–</td>
                                    <td class="px-3 py-2 text-sm text-gray-600 border border-gray-300 whitespace-nowrap">–</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ formatNum(item.opening_balance) }}</td>
                                    <td class="px-3 py-2 text-sm text-green-600 text-right border border-gray-300">{{ formatNum(item.stock_received) }}</td>
                                    <td class="px-3 py-2 text-sm text-red-600 text-right border border-gray-300">{{ formatNum(item.stock_issued) }}</td>
                                    <td class="px-3 py-2 text-sm text-right border border-gray-300">{{ formatNum(item.negative_adjustments) }}</td>
                                    <td class="px-3 py-2 text-sm text-right border border-gray-300">{{ formatNum(item.positive_adjustments) }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ formatNum(item.closing_balance) }}</td>
                                    <td class="px-3 py-2 text-sm font-medium text-blue-600 text-right border border-gray-300">{{ formatNum(item.closing_balance) }}</td>
                                    <td class="px-3 py-2 text-sm text-right border border-gray-300">{{ lmisAmc(item) }}</td>
                                    <td class="px-3 py-2 text-sm text-right border border-gray-300">{{ lmisMos(item) }}</td>
                                    <td class="px-3 py-2 text-sm text-right border border-gray-300">{{ formatNum(item.stockout_days) }}</td>
                                </tr>
                                <tr v-if="!lmisItems.length" class="bg-gray-50">
                                    <td colspan="15" class="px-3 py-6 text-sm text-center border border-gray-300">
                                        <p class="text-gray-500 mb-3">{{ reportMessage || 'No report found for the specified period.' }}</p>
                                        <button
                                            @click="createLmisReport"
                                            :disabled="creatingLmis"
                                            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white hover:bg-indigo-700 disabled:opacity-50"
                                        >
                                            {{ creatingLmis ? 'Creating...' : 'Create LMIS Report' }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else-if="hasGenerated && !hasData" class="p-8 text-center text-gray-600 max-w-xl mx-auto">
                    <div class="text-gray-400 mb-4">
                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h3 class="text-sm font-medium text-gray-900 mb-2">{{ reportMessage }}</h3>
                </div>

                <!-- Transfer Report (aggregate: from_facility_id only; tabs: Table | Charts) -->
                <div v-else-if="currentData && currentReportType === 'transfer_report'">
                    <div class="p-4 border-b border-gray-200 bg-gray-50 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-lg font-medium text-gray-900">Transfer Report</h3>
                        <div class="flex rounded-lg border border-gray-200 bg-gray-100 p-0.5">
                            <button
                                type="button"
                                :class="transferReportTab === 'table' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                @click="transferReportTab = 'table'"
                            >
                                Table
                            </button>
                            <button
                                type="button"
                                :class="transferReportTab === 'charts' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                @click="transferReportTab = 'charts'"
                            >
                                Charts
                            </button>
                        </div>
                    </div>
                    <!-- Tab: Table -->
                    <div v-show="transferReportTab === 'table'" class="p-4">
                        <div class="overflow-x-auto">
                            <table class="min-w-full border-collapse border border-gray-300">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Total Transfers</th>
                                        <th class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Completed Transfers</th>
                                        <th class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Rejected Transfers</th>
                                        <th colspan="3" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Transfer Reasons</th>
                                        <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Transfer Type (from current facility)</th>
                                    </tr>
                                    <tr class="bg-gray-50">
                                        <th class="px-3 py-1 border border-gray-300"></th>
                                        <th class="px-3 py-1 border border-gray-300"></th>
                                        <th class="px-3 py-1 border border-gray-300"></th>
                                        <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Overstock</th>
                                        <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Soon to Expire</th>
                                        <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Slow Moving</th>
                                        <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">To Warehouse</th>
                                        <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">To Facility</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white">
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportMetrics?.total_transfers ?? '–' }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportCompleted }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportRejected }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportMetrics?.reason_overstock ?? '–' }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportMetrics?.reason_soon_to_expire ?? '–' }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportMetrics?.reason_slow_moving ?? '–' }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportMetrics?.type_facility_to_warehouse ?? '–' }}</td>
                                        <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ transferReportMetrics?.type_facility_to_facility ?? '–' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Tab: Charts -->
                    <div v-show="transferReportTab === 'charts'" class="p-4">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <h4 class="text-sm font-semibold text-gray-800 mb-3">Transfer Type (from current facility)</h4>
                                <div class="h-64">
                                    <Bar v-if="transferReportChartTypeData" :data="transferReportChartTypeData" :options="transferChartTypeOptions" />
                                </div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-lg p-4">
                                <h4 class="text-sm font-semibold text-gray-800 mb-3">Transfer Status</h4>
                                <div class="h-64">
                                    <Bar v-if="transferReportChartStatusData" :data="transferReportChartStatusData" :options="transferChartStatusOptions" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Report (aggregate KPIs; no Facility Name column) -->
                <div v-else-if="currentData && currentReportType === 'order_report'">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h3 class="text-lg font-medium text-gray-900">Order Report</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Total Orders</th>
                                    <th class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Completed Orders</th>
                                    <th class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Rejected Orders</th>
                                    <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Order Delivery Status*</th>
                                    <th colspan="3" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Order Items Fulfillment Rate*</th>
                                    <th colspan="3" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Order QTY Fulfillment Rate*</th>
                                </tr>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-1 border border-gray-300"></th>
                                    <th class="px-3 py-1 border border-gray-300"></th>
                                    <th class="px-3 py-1 border border-gray-300"></th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Ontime</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Late</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Good</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Fair</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Poor</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Good</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Fair</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Poor</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportMetrics.total_orders ?? '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportCompleted }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportRejected }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportOntime }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportLate }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportItemsGood }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportItemsFair }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportItemsPoor }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportQtyGood }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportQtyFair }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ orderReportQtyPoor }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Expired Report (summary only: counts + values; facility-only) -->
                <div v-else-if="currentReportType === 'expired_report'">
                    <div class="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-gray-900">Expired Report</h3>
                        <Link :href="route('expired.index')" class="text-sm text-blue-600 hover:text-blue-800">Open full Expired page</Link>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th colspan="6" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Expiring Status</th>
                                </tr>
                                <tr class="bg-gray-50">
                                    <th colspan="2" class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Expiring within next 1 Year</th>
                                    <th colspan="2" class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Expiring within next 6 Months</th>
                                    <th colspan="2" class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Expired</th>
                                </tr>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Item No.</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Total Value</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Item No.</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Total Value</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Item No.</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Total Value</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <tr v-if="expiredReportRows.length" class="hover:bg-gray-50">
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ expiredReportRows[0].expiring_1_year_item_no ? formatNum(expiredReportRows[0].expiring_1_year_item_no) : '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ expiredReportRows[0].expiring_1_year_value != null && expiredReportRows[0].expiring_1_year_value !== '' ? '$' + formatCost(expiredReportRows[0].expiring_1_year_value) : '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ expiredReportRows[0].expiring_6_months_item_no ? formatNum(expiredReportRows[0].expiring_6_months_item_no) : '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ expiredReportRows[0].expiring_6_months_value != null && expiredReportRows[0].expiring_6_months_value !== '' ? '$' + formatCost(expiredReportRows[0].expiring_6_months_value) : '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ expiredReportRows[0].expired_item_no ? formatNum(expiredReportRows[0].expired_item_no) : '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ expiredReportRows[0].expired_value != null && expiredReportRows[0].expired_value !== '' ? '$' + formatCost(expiredReportRows[0].expired_value) : '–' }}</td>
                                </tr>
                                <tr v-if="!expiredReportRows.length" class="bg-gray-50">
                                    <td colspan="6" class="px-3 py-6 text-sm text-center text-gray-500 border border-gray-300">
                                        {{ reportMessage || 'No expired or expiring items for your facility.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Inventory Movements Report -->
                <div v-else-if="currentData && currentReportType === 'inventory_movements'">
                    <div class="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-900">Inventory Movements Report</h3>
                        <button @click="exportCSV('/reports/inventory-movements/export')" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md text-sm">
                            Export CSV
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">Date</th>
                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">Product</th>
                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">Type</th>
                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">Source</th>
                                    <th class="px-3 py-2 text-right text-xs font-bold text-gray-700 border border-gray-300">Quantity</th>
                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300">Batch/Expiry</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <tr v-for="movement in currentData.data" :key="movement.id" class="hover:bg-gray-50">
                                    <td class="px-3 py-2 text-sm text-gray-900 border border-gray-300">{{ formatDate(movement.movement_date) }}</td>
                                    <td class="px-3 py-2 text-sm font-medium text-gray-900 border border-gray-300">{{ movement.product?.name }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 border border-gray-300">
                                        <span class="px-2 py-1 text-xs rounded-full" :class="movement.movement_type === 'facility_received' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                                            {{ movement.movement_type === 'facility_received' ? 'Received' : 'Issued' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-gray-900 border border-gray-300 capitalize">{{ movement.source_type }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ formatNum(movement.quantity) }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 border border-gray-300">
                                        <div v-if="movement.batch_number">{{ movement.batch_number }}</div>
                                        <div v-if="movement.expiry_date" class="text-xs text-gray-500">{{ formatDate(movement.expiry_date) }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Liquidation & Disposals Report (unified: aggregate by warehouse) -->
                <div v-else-if="currentData && currentReportType === 'liquidation_disposal_report'">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h3 class="text-lg font-medium text-gray-900">Liquidation & Disposals Report</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Total Liquidated Items</th>
                                    <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Total Disposed Items</th>
                                    <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Reasons for Liquidation</th>
                                    <th colspan="2" class="px-3 py-2 text-center text-xs font-bold text-gray-700 border border-gray-300">Reasons for Disposal</th>
                                </tr>
                                <tr class="bg-gray-50">
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Item No.</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Total Value</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Item No.</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Total Value</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Missing</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Lost</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Damage</th>
                                    <th class="px-3 py-1 text-center text-xs font-medium text-gray-600 border border-gray-300">Expired</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <tr v-for="(row, idx) in (liquidationDisposalRows || [])" :key="idx" class="hover:bg-gray-50">
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.liquidated_item_no ?? '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ formatLiquidationValue(row.liquidated_total_value) }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.disposed_item_no ?? '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ formatLiquidationValue(row.disposed_total_value) }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.liquidation_reason_missing ?? '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.liquidation_reason_lost ?? '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.disposal_reason_damage ?? '–' }}</td>
                                    <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.disposal_reason_expired ?? '–' }}</td>
                                </tr>
                                <tr v-if="!liquidationDisposalRows?.length" class="bg-gray-50">
                                    <td colspan="8" class="px-3 py-6 text-sm text-center text-gray-500 border border-gray-300">
                                        {{ reportMessage || 'No warehouse data for the selected period.' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination for paginated tables -->
                <div v-if="hasData && currentData && currentData.links" class="p-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            Showing {{ currentData.from || 0 }} to {{ currentData.to || 0 }} of {{ currentData.total || 0 }} entries
                        </div>
                        <div class="flex gap-1">
                            <button 
                                v-for="(link, i) in currentData.links" 
                                :key="i"
                                @click="link.url && loadPage(link.url)"
                                :disabled="!link.url"
                                class="px-3 py-1 text-sm border rounded"
                                :class="link.active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50 disabled:opacity-50'"
                                v-html="link.label"
                            ></button>
                        </div>
                    </div>
                </div>

                <!-- Empty state when filters changed or no report generated yet -->
                <div v-else-if="!generating && !hasGenerated" class="p-8 text-center text-gray-500">
                    <svg class="mx-auto h-12 w-12 text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <p class="text-sm font-medium text-gray-700">Select Report type</p>
                    <p class="text-xs text-gray-500 mt-1">Choose a report type, set period and year, then click Generate Report.</p>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import axios from 'axios'
import Swal from 'sweetalert2'
import { useToast } from 'vue-toastification'
import * as XLSX from 'xlsx'
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend)

const props = defineProps({
    reportTypes: Array,
    reportPeriodOptions: Array,
    filters: { type: Object, default: () => ({}) },
})

const toast = useToast()

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const DEFAULT_REPORT_PERIOD_OPTIONS = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'bi-monthly', label: 'Bi-monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'six_months', label: 'Six months' },
    { value: 'yearly', label: 'Yearly' },
]

const currentYear = new Date().getFullYear()
const currentMonth = new Date().getMonth() + 1
const yearOptions = computed(() => {
    const start = currentYear - 10
    const end = currentYear + 1
    const list = []
    for (let y = end; y >= start; y--) list.push(y)
    return list
})

const filters = ref({
    report_type: props.filters?.report_type ?? '',
    report_period: props.filters?.report_period ?? 'monthly',
    year: props.filters?.year ?? currentYear,
    month: props.filters?.month ?? currentMonth,
    periodValue: props.filters?.month ?? currentMonth,
    start_date: '',
    end_date: '',
    search: '',
})

const reportPeriodOptionsList = computed(() =>
    (props.reportPeriodOptions?.length ? props.reportPeriodOptions : DEFAULT_REPORT_PERIOD_OPTIONS)
)

const periodOptions = computed(() => {
    const period = filters.value.report_period || 'monthly'
    switch (period) {
        case 'monthly':
            return MONTH_NAMES.map((name, i) => ({ value: i + 1, label: name }))
        case 'bi-monthly':
            return [
                { value: 1, label: 'Jan-Feb' }, { value: 3, label: 'Mar-Apr' }, { value: 5, label: 'May-Jun' },
                { value: 7, label: 'Jul-Aug' }, { value: 9, label: 'Sep-Oct' }, { value: 11, label: 'Nov-Dec' },
            ]
        case 'quarterly':
            return [
                { value: 1, label: 'Jan-Mar' }, { value: 4, label: 'Apr-Jun' },
                { value: 7, label: 'Jul-Sep' }, { value: 10, label: 'Oct-Dec' },
            ]
        case 'six_months':
            return [{ value: 1, label: 'Jan-Jun' }, { value: 7, label: 'Jul-Dec' }]
        case 'yearly':
            return [{ value: 1, label: 'Full year' }]
        default:
            return MONTH_NAMES.map((name, i) => ({ value: i + 1, label: name }))
    }
})

watch(() => filters.value.report_period, () => {
    const opts = periodOptions.value
    if (!opts.length) return
    const valid = opts.some(o => o.value === filters.value.periodValue)
    if (!valid) filters.value.periodValue = opts[0].value
}, { immediate: false })

const periodLabel = computed(() => {
    const p = filters.value.report_period || 'monthly'
    if (p === 'monthly') return 'Month'
    if (p === 'quarterly') return 'Quarter'
    if (p === 'bi-monthly' || p === 'six_months' || p === 'yearly') return 'Period'
    return 'Month'
})

const isFacilityLmisReport = computed(() => filters.value.report_type === 'facility_monthly_consumption')
const hasFacilityLmisRequiredFilters = computed(() => {
    if (!isFacilityLmisReport.value) return true
    return Boolean(filters.value.year && filters.value.periodValue)
})

const lmisReportPeriod = computed(() => {
    if (currentData.value?.report_period) return currentData.value.report_period
    const y = filters.value.year
    const m = filters.value.periodValue
    if (!y || !m) return '—'
    return `${y}-${String(m).padStart(2, '0')}`
})

const lmisItems = computed(() => currentData.value?.items ?? [])

function lmisAmc(item) {
    const amc = item?.amc ?? item?.average_monthly_consumption
    if (amc == null || amc === '') return '–'
    return formatNum(Math.round(Number(amc)))
}

function lmisMos(item) {
    const amc = Number(item?.amc ?? item?.average_monthly_consumption) || 0
    const totalClosing = Number(item?.closing_balance) || 0
    if (amc <= 0) return '–'
    const mos = totalClosing / amc
    return Number.isInteger(mos) ? mos : Math.round(mos * 10) / 10
}

const generating = ref(false)
const hasGenerated = ref(false)
const currentReportType = ref('')
const transferReportTab = ref('table')
const creatingLmis = ref(false)
const lmisWorkflowLoading = ref(false)
const currentData = ref(null)
const reportMessage = ref('No data found for the selected filters.')

let searchTimeout = null
const debounceGenerate = () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        if (hasGenerated.value) {
            generateReport()
        }
    }, 500)
}

function clearReportOnFilterChange() {
    currentData.value = null
    currentReportType.value = ''
    hasGenerated.value = false
}

watch(
    () => [
        filters.value.report_type,
        filters.value.report_period,
        filters.value.year,
        filters.value.periodValue,
    ],
    () => clearReportOnFilterChange(),
    { deep: false }
)

const expiredReportRows = computed(() => currentData.value?.rows ?? [])

const liquidationDisposalRows = computed(() => currentData.value?.aggregateByWarehouse ?? [])

const orderReportMetrics = computed(() =>
    currentReportType.value === 'order_report' && currentData.value ? currentData.value : null
)
const orderReportCompleted = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.completed_orders} (${m.completed_pct}%)`
})
const orderReportRejected = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.rejected_orders} (${m.rejected_pct}%)`
})
const orderReportOntime = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.delivery_ontime_count} (${m.delivery_ontime_pct}%)`
})
const orderReportLate = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.delivery_late_count} (${m.delivery_late_pct}%)`
})
const orderReportItemsGood = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.items_fulfillment_good_pct}%`
})
const orderReportItemsFair = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.items_fulfillment_fair_pct}%`
})
const orderReportItemsPoor = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.items_fulfillment_poor_pct}%`
})
const orderReportQtyGood = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.qty_fulfillment_good_pct}%`
})
const orderReportQtyFair = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.qty_fulfillment_fair_pct}%`
})
const orderReportQtyPoor = computed(() => {
    const m = orderReportMetrics.value
    if (!m) return '–'
    return `${m.qty_fulfillment_poor_pct}%`
})

const transferReportMetrics = computed(() =>
    currentReportType.value === 'transfer_report' && currentData.value ? currentData.value : null
)
const transferReportCompleted = computed(() => {
    const m = transferReportMetrics.value
    if (!m) return '–'
    return `${m.completed_transfers} (${m.completed_pct}%)`
})
const transferReportRejected = computed(() => {
    const m = transferReportMetrics.value
    if (!m) return '–'
    return `${m.rejected_transfers} (${m.rejected_pct}%)`
})

const transferReportChartTypeData = computed(() => {
    const m = transferReportMetrics.value
    if (!m) return null
    return {
        labels: ['To Warehouse', 'To Facility'],
        datasets: [{
            label: 'Count',
            data: [
                m.type_facility_to_warehouse ?? 0,
                m.type_facility_to_facility ?? 0,
            ],
            backgroundColor: ['#3b82f6', '#22c55e'],
        }],
    }
})

const transferReportChartStatusData = computed(() => {
    const m = transferReportMetrics.value
    if (!m) return null
    return {
        labels: ['Total Transfers', 'Received', 'Rejected'],
        datasets: [{
            label: 'Count',
            data: [m.total_transfers ?? 0, m.completed_transfers ?? 0, m.rejected_transfers ?? 0],
            backgroundColor: ['#3b82f6', '#22c55e', '#eab308'],
        }],
    }
})

const transferChartTypeOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
    },
    scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1 } },
    },
}

const transferChartStatusOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
    },
    scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1 } },
    },
}

const hasData = computed(() => {
    if (!currentData.value) return false
    if (currentReportType.value === 'facility_monthly_consumption') {
        return currentData.value.items && currentData.value.items.length > 0
    }
    if (currentReportType.value === 'expired_report') {
        return (currentData.value.rows && currentData.value.rows.length > 0)
    }
    if (currentReportType.value === 'order_report' || currentReportType.value === 'transfer_report' || currentReportType.value === 'liquidation_disposal_report') {
        return true
    }
    return currentData.value.data && currentData.value.data.length > 0
})

const generateReport = async () => {
    const reportType = filters.value.report_type

    generating.value = true
    hasGenerated.value = true
    currentReportType.value = reportType

    const payload = {
        report_type: reportType,
        report_period: filters.value.report_period,
        year: filters.value.year,
        month: filters.value.periodValue,
        search: filters.value.search,
        start_date: filters.value.start_date || undefined,
        end_date: filters.value.end_date || undefined,
    }
    // expired_report runs as summary without extra filters here

    try {
        const response = await axios.post('/reports/unified-data', payload)
        currentData.value = response.data.data
        if (!hasData.value) {
            reportMessage.value = response.data.message || 'No data found for the selected filters.'
        }
    } catch (error) {
        console.error('Error fetching report data:', error)
        toast.error('An error occurred while generating the report.')
        currentData.value = null
    } finally {
        generating.value = false
    }
}

const loadPage = async (url) => {
    if (!url) return
    generating.value = true
    try {
        const params = new URLSearchParams(new URL(url).search)
        const payload = {
            report_type: filters.value.report_type,
            report_period: filters.value.report_period,
            year: filters.value.year,
            month: filters.value.periodValue,
            search: filters.value.search,
            start_date: filters.value.start_date || undefined,
            end_date: filters.value.end_date || undefined,
            page: params.get('page'),
        }
        // expired_report runs as summary without extra filters here
        const response = await axios.post('/reports/unified-data', payload)
        currentData.value = response.data.data
    } catch (error) {
        console.error('Error fetching page:', error)
    } finally {
        generating.value = false
    }
}

function dateRangeFromPeriod(year, month, period) {
    const start = new Date(year, month - 1, 1)
    let end
    switch (period) {
        case 'bi-monthly': end = new Date(year, month, 0); break
        case 'quarterly': end = new Date(year, month + 2, 0); break
        case 'six_months': end = new Date(year, month + 5, 0); break
        case 'yearly': end = new Date(year, 11, 31); break
        default: end = new Date(year, month, 0); break
    }
    return {
        start: start.toISOString().slice(0, 10),
        end: end.toISOString().slice(0, 10),
    }
}

const exportCSV = (exportUrl) => {
    const params = new URLSearchParams()
    let start = filters.value.start_date
    let end = filters.value.end_date
    if (!start || !end) {
        const range = dateRangeFromPeriod(filters.value.year, filters.value.periodValue, filters.value.report_period)
        start = start || range.start
        end = end || range.end
    }
    if (start) params.append('start_date', start)
    if (end) params.append('end_date', end)
    if (filters.value.search) params.append('search', filters.value.search)

    window.location.href = `${exportUrl}?${params.toString()}`
}

const lmisMonthYear = computed(() => {
    const y = filters.value.year || currentYear
    const m = filters.value.periodValue ?? currentMonth
    return `${y}-${String(m).padStart(2, '0')}`
})

const createLmisReport = async () => {
    const month_year = lmisMonthYear.value
    if (!month_year) return
    creatingLmis.value = true
    try {
        const { data } = await axios.post(route('reports.create-lmis-report'), { month_year }, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (data?.success) {
            toast.success(data.message || 'LMIS report created.')
            await generateReport()
        }
    } catch (err) {
        toast.error(err?.response?.data?.message || 'Failed to create LMIS report.')
    } finally {
        creatingLmis.value = false
    }
}

async function submitLmisForReview() {
    if (!currentData.value?.id) return
    const result = await Swal.fire({ title: 'Submit for review?', text: 'You will not be able to edit after submission.', icon: 'question', showCancelButton: true, confirmButtonColor: '#2563eb', cancelButtonColor: '#6b7280', confirmButtonText: 'Yes, submit' })
    if (!result.isConfirmed) return
    lmisWorkflowLoading.value = true
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.submit'), { report_id: currentData.value.id })
        if (data?.success) { toast.success('Report submitted for review.'); await generateReport() }
        else toast.error(data?.message || 'Failed to submit.')
    } catch (e) { toast.error(e?.response?.data?.message || 'An error occurred.') }
    finally { lmisWorkflowLoading.value = false }
}

async function startLmisReview() {
    if (!currentData.value?.id) return
    const result = await Swal.fire({ title: 'Mark as reviewed?', icon: 'question', showCancelButton: true, confirmButtonColor: '#ca8a04', cancelButtonColor: '#6b7280', confirmButtonText: 'Yes, mark reviewed' })
    if (!result.isConfirmed) return
    lmisWorkflowLoading.value = true
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.start-review'), { report_id: currentData.value.id })
        if (data?.success) { toast.success('Report marked as reviewed.'); await generateReport() }
        else toast.error(data?.message || 'Failed to update.')
    } catch (e) { toast.error(e?.response?.data?.message || 'An error occurred.') }
    finally { lmisWorkflowLoading.value = false }
}

async function approveLmisReport() {
    if (!currentData.value?.id) return
    const result = await Swal.fire({ title: 'Approve report?', icon: 'question', showCancelButton: true, confirmButtonColor: '#16a34a', cancelButtonColor: '#6b7280', confirmButtonText: 'Yes, approve' })
    if (!result.isConfirmed) return
    lmisWorkflowLoading.value = true
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.approve'), { report_id: currentData.value.id })
        if (data?.success) { toast.success('Report approved.'); await generateReport() }
        else toast.error(data?.message || 'Failed to approve.')
    } catch (e) { toast.error(e?.response?.data?.message || 'An error occurred.') }
    finally { lmisWorkflowLoading.value = false }
}

async function rejectLmisReport() {
    if (!currentData.value?.id) return
    const { value: comments } = await Swal.fire({ title: 'Reject report', text: 'Please provide a reason:', input: 'textarea', inputPlaceholder: 'Rejection reason...', showCancelButton: true, confirmButtonColor: '#dc2626', cancelButtonColor: '#6b7280', confirmButtonText: 'Reject', inputValidator: (v) => (!v || !v.trim() ? 'A reason is required.' : null) })
    if (comments == null) return
    lmisWorkflowLoading.value = true
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.reject'), { report_id: currentData.value.id, comments })
        if (data?.success) { toast.success('Report rejected.'); await generateReport() }
        else toast.error(data?.message || 'Failed to reject.')
    } catch (e) { toast.error(e?.response?.data?.message || 'An error occurred.') }
    finally { lmisWorkflowLoading.value = false }
}

async function returnLmisToDraft() {
    if (!currentData.value?.id) return
    const result = await Swal.fire({ title: 'Return to draft?', text: 'The report will be editable again.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#6b7280', cancelButtonColor: '#6b7280', confirmButtonText: 'Yes, return to draft' })
    if (!result.isConfirmed) return
    lmisWorkflowLoading.value = true
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.return-to-draft'), { report_id: currentData.value.id })
        if (data?.success) { toast.success('Report returned to draft.'); await generateReport() }
        else toast.error(data?.message || 'Failed to return to draft.')
    } catch (e) { toast.error(e?.response?.data?.message || 'An error occurred.') }
    finally { lmisWorkflowLoading.value = false }
}

const exportLMIS = () => {
    if (!currentData.value || !currentData.value.items) return

    const excelData = []
    excelData.push([`LMIS Monthly Report - Period: ${currentData.value.report_period}`])
    excelData.push([])
    excelData.push([
        'Item',
        'Category',
        'UoM',
        'Batch No.',
        'Expiry Date',
        'Beginning Balance',
        'QTY Received',
        'QTY Issued',
        'Adjustment (-)',
        'Adjustment (+)',
        'Closing Balance',
        'Total Closing Balance',
        'AMC',
        'MOS',
        'Stockout Days'
    ])

    currentData.value.items.forEach(item => {
        excelData.push([
            item.product?.name || '',
            item.product?.category?.name || '–',
            item.product?.unit_of_measure || item.product?.uom || '–',
            '–',
            '–',
            item.opening_balance || 0,
            item.stock_received || 0,
            item.stock_issued || 0,
            item.negative_adjustments || 0,
            item.positive_adjustments || 0,
            item.closing_balance || 0,
            item.closing_balance || 0,
            (item?.amc ?? item?.average_monthly_consumption) != null ? (item?.amc ?? item?.average_monthly_consumption) : '–',
            lmisMos(item),
            item.stockout_days || 0
        ])
    })

    const ws = XLSX.utils.aoa_to_sheet(excelData)
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, ws, 'Monthly Report')
    XLSX.writeFile(wb, `LMIS_Report_${currentData.value.report_period}.xlsx`)
}

const formatNum = (val) => {
    if (val === null || val === undefined) return '0'
    return Number(val).toLocaleString()
}

const formatCost = (val) => {
    if (val === null || val === undefined) return '0.00'
    return Number(val).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const formatLiquidationValue = (amount) => {
    const n = amount != null ? Number(amount) : NaN
    if (n === 0 || Number.isNaN(n)) return '–$'
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const formatDate = (dateString) => {
    if (!dateString) return '-'
    const date = new Date(dateString)
    return date.toLocaleDateString('en-GB')
}

const getStatusColor = (status) => {
    const st = String(status).toLowerCase()
    if (['approved', 'completed', 'received', 'delivered'].includes(st)) return 'bg-green-100 text-green-800'
    if (['pending', 'draft'].includes(st)) return 'bg-yellow-100 text-yellow-800'
    if (['rejected', 'cancelled'].includes(st)) return 'bg-red-100 text-red-800'
    return 'bg-gray-100 text-gray-800'
}
</script>
