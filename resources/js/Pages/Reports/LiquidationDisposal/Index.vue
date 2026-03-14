<template>
    <Head title="Liquidation & Disposals Report" />
    <AuthenticatedLayout
        title="Liquidation & Disposals Report"
        description="Track product liquidations and disposals"
        img="/assets/images/report.png"
    >
        <!-- Tabs: Summary (by warehouse) | Liquidation list | Disposals list -->
        <div class="mb-4 flex rounded-lg border border-gray-200 bg-gray-100 p-0.5">
            <button
                type="button"
                :class="activeTab === 'summary' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                class="rounded-md px-4 py-2 text-sm font-medium transition"
                @click="activeTab = 'summary'"
            >
                Summary
            </button>
            <button
                type="button"
                :class="activeTab === 'liquidation' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                class="rounded-md px-4 py-2 text-sm font-medium transition"
                @click="activeTab = 'liquidation'"
            >
                Liquidation
            </button>
            <button
                type="button"
                :class="activeTab === 'disposal' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                class="rounded-md px-4 py-2 text-sm font-medium transition"
                @click="activeTab = 'disposal'"
            >
                Disposals
            </button>
        </div>

        <!-- Summary table by warehouse (design: Warehouse | Total Liquidated | Total Disposed | Reasons) -->
        <div v-show="activeTab === 'summary'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Liquidations and Disposals by Warehouse</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse border border-gray-300">
                    <thead class="bg-gray-50">
                        <tr>
                            <th rowspan="2" class="px-3 py-2 text-left text-xs font-bold text-gray-700 border border-gray-300 align-middle">Warehouse Name</th>
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
                        <tr v-for="(row, idx) in (aggregateByWarehouse || [])" :key="idx" class="hover:bg-gray-50">
                            <td class="px-3 py-2 text-sm text-gray-900 border border-gray-300">{{ row.warehouse_name || '–' }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.liquidated_item_no ?? '–' }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ formatValue(row.liquidated_total_value) }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.disposed_item_no ?? '–' }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-right border border-gray-300">{{ formatValue(row.disposed_total_value) }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.liquidation_reason_missing ?? '–' }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.liquidation_reason_lost ?? '–' }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.disposal_reason_damage ?? '–' }}</td>
                            <td class="px-3 py-2 text-sm text-gray-900 text-center border border-gray-300">{{ row.disposal_reason_expired ?? '–' }}</td>
                        </tr>
                        <tr v-if="!aggregateByWarehouse?.length" class="bg-gray-50">
                            <td colspan="9" class="px-3 py-6 text-sm text-center text-gray-500 border border-gray-300">No warehouse data.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Summary Cards (both) - shown when on Liquidation or Disposals tab -->
        <div v-show="activeTab !== 'summary'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4 mb-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Liquidations (Total)</p>
                <p class="text-2xl font-bold text-gray-900">{{ liquidationSummary?.total_liquidations ?? 0 }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Liquidations (Approved)</p>
                <p class="text-2xl font-bold text-green-600">{{ liquidationSummary?.approved_count ?? 0 }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Liquidations (Pending)</p>
                <p class="text-2xl font-bold text-yellow-600">{{ liquidationSummary?.pending_count ?? 0 }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Disposals (Total)</p>
                <p class="text-2xl font-bold text-gray-900">{{ disposalSummary?.total_disposals ?? 0 }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Disposals (Approved)</p>
                <p class="text-2xl font-bold text-green-600">{{ disposalSummary?.approved_count ?? 0 }}</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Disposals (Pending)</p>
                <p class="text-2xl font-bold text-yellow-600">{{ disposalSummary?.pending_count ?? 0 }}</p>
            </div>
        </div>

        <!-- Shared Filters (apply to Summary and Detail) -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-4">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Filters</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select v-model="status" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                    <input v-model="date_from" type="date" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                    <input v-model="date_to" type="date" class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <input v-model="search" type="text" placeholder="ID or notes..." class="w-full border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" />
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button @click="applyFilters()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Apply</button>
                <button @click="clearFilters" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Clear</button>
            </div>
        </div>

        <!-- Tab: Liquidation -->
        <div v-show="activeTab === 'liquidation'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Liquidations List</h3>
                <div class="flex items-center gap-3">
                    <button @click="exportLiquidationExcel" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md text-sm font-medium hover:bg-green-700">
                        Export to Excel
                    </button>
                    <span class="text-sm text-gray-500">Results: {{ liquidations?.total ?? 0 }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Liquidation ID</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Source</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Facility</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Liquidated By</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Items</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Total Value</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="!liquidations?.data?.length">
                            <td colspan="9" class="px-6 py-12 text-center text-gray-500">No liquidations found.</td>
                        </tr>
                        <tr v-for="liquidation in liquidations?.data ?? []" :key="liquidation.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">{{ liquidation.liquidate_id }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ liquidation.source_display || liquidation.source || 'N/A' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ liquidation.facility || 'N/A' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap"><span :class="getStatusClass(liquidation.status)">{{ getStatusLabel(liquidation.status) }}</span></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ liquidation.liquidated_by?.name || 'N/A' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ formatDate(liquidation.liquidated_at) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ liquidation.items?.length || 0 }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">${{ formatCurrency(calculateTotalValue(liquidation.items)) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="openLiquidationModal(liquidation)" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">View Items</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-200 flex justify-end">
                <TailwindPagination :data="liquidations" :limit="2" @pagination-change-page="(page) => getLiquidationResults(page)" />
            </div>
        </div>

        <!-- Tab: Disposals -->
        <div v-show="activeTab === 'disposal'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Disposals List</h3>
                <div class="flex items-center gap-3">
                    <button @click="exportDisposalExcel" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md text-sm font-medium hover:bg-green-700">
                        Export to Excel
                    </button>
                    <span class="text-sm text-gray-500">Results: {{ disposals?.total ?? 0 }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Disposal ID</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Source</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Disposed By</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Items</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Total Value</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="!disposals?.data?.length">
                            <td colspan="8" class="px-6 py-12 text-center text-gray-500">No disposals found.</td>
                        </tr>
                        <tr v-for="disposal in disposals?.data ?? []" :key="disposal.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900">{{ disposal.disposal_id }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ disposal.source_display || disposal.source || 'N/A' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap"><span :class="getStatusClass(disposal.status)">{{ getStatusLabel(disposal.status) }}</span></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ disposal.disposed_by?.name || 'N/A' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ formatDate(disposal.disposed_at) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">{{ disposal.items?.length || 0 }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">${{ formatCurrency(calculateTotalValue(disposal.items)) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="openDisposalModal(disposal)" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">View Items</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-200 flex justify-end">
                <TailwindPagination :data="disposals" :limit="2" @pagination-change-page="(page) => getDisposalResults(page)" />
            </div>
        </div>

        <!-- Liquidation Items Modal -->
        <div v-if="showLiquidationModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="closeLiquidationModal">
            <div class="bg-white rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between p-4 border-b">
                    <h3 class="text-lg font-semibold">Liquidation Items - {{ selectedLiquidation?.liquidate_id }}</h3>
                    <button @click="closeLiquidationModal" class="text-gray-400 hover:text-gray-600 p-1">✕</button>
                </div>
                <div class="flex-1 overflow-auto p-4">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Product</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Category</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Quantity</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Unit Cost</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Total Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="item in selectedLiquidation?.items ?? []" :key="item.id">
                                <td class="px-4 py-2">{{ item.product?.name || 'N/A' }}</td>
                                <td class="px-4 py-2">{{ item.product?.category?.name || 'N/A' }}</td>
                                <td class="px-4 py-2">{{ item.quantity }}</td>
                                <td class="px-4 py-2">${{ formatCurrency(item.unit_cost) }}</td>
                                <td class="px-4 py-2">${{ formatCurrency(item.total_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Disposal Items Modal -->
        <div v-if="showDisposalModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="closeDisposalModal">
            <div class="bg-white rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between p-4 border-b">
                    <h3 class="text-lg font-semibold">Disposal Items - {{ selectedDisposal?.disposal_id }}</h3>
                    <button @click="closeDisposalModal" class="text-gray-400 hover:text-gray-600 p-1">✕</button>
                </div>
                <div class="flex-1 overflow-auto p-4">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Product</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Category</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Quantity</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Unit Cost</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-600">Total Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="item in selectedDisposal?.items ?? []" :key="item.id">
                                <td class="px-4 py-2">{{ item.product?.name || 'N/A' }}</td>
                                <td class="px-4 py-2">{{ item.product?.category?.name || 'N/A' }}</td>
                                <td class="px-4 py-2">{{ item.quantity }}</td>
                                <td class="px-4 py-2">${{ formatCurrency(item.unit_cost) }}</td>
                                <td class="px-4 py-2">${{ formatCurrency(item.total_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { TailwindPagination } from 'laravel-vue-pagination'
import * as XLSX from 'xlsx'

const props = defineProps({
    liquidations: Object,
    disposals: Object,
    liquidationSummary: Object,
    disposalSummary: Object,
    aggregateByWarehouse: Array,
    filters: Object,
    sources: Array,
})

const activeTab = ref('summary')
const status = ref(props.filters?.status ?? '')
const date_from = ref(props.filters?.date_from ?? '')
const date_to = ref(props.filters?.date_to ?? '')
const search = ref(props.filters?.search ?? '')
const per_page = ref(props.filters?.per_page ?? 25)
const showLiquidationModal = ref(false)
const showDisposalModal = ref(false)
const selectedLiquidation = ref(null)
const selectedDisposal = ref(null)

function buildFilters(liquidPageOverride, dispPageOverride) {
    const f = {}
    if (status.value) f.status = status.value
    if (date_from.value) f.date_from = date_from.value
    if (date_to.value) f.date_to = date_to.value
    if (search.value) f.search = search.value
    if (per_page.value && per_page.value !== 25) f.per_page = per_page.value
    f.liquid_page = liquidPageOverride ?? props.filters?.liquid_page ?? 1
    f.disp_page = dispPageOverride ?? props.filters?.disp_page ?? 1
    return f
}

function applyFilters(liquidPageOverride, dispPageOverride) {
    router.get(route('reports.liquidation-disposal.index'), buildFilters(liquidPageOverride, dispPageOverride), {
        preserveState: true,
        preserveScroll: true,
        only: ['liquidations', 'disposals', 'liquidationSummary', 'disposalSummary', 'aggregateByWarehouse', 'filters'],
    })
}

function getLiquidationResults(page = 1) {
    applyFilters(page, undefined)
}

function getDisposalResults(page = 1) {
    applyFilters(undefined, page)
}

function clearFilters() {
    status.value = ''
    date_from.value = ''
    date_to.value = ''
    search.value = ''
    per_page.value = 25
    applyFilters(1, 1)
}

function formatDate(dateString) {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleDateString()
}

function formatCurrency(amount) {
    return parseFloat(amount || 0).toFixed(2)
}

function formatValue(amount) {
    const n = parseFloat(amount)
    if (n === 0 || Number.isNaN(n)) return '–$'
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function calculateTotalValue(items) {
    if (!items || !Array.isArray(items)) return 0
    return items.reduce((sum, item) => sum + (parseFloat(item.total_cost) || 0), 0)
}

function getStatusClass(s) {
    const classes = {
        pending: 'inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800',
        approved: 'inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800',
        rejected: 'inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800',
    }
    return classes[s] || classes.pending
}

function getStatusLabel(s) {
    const labels = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' }
    return labels[s] || 'Pending'
}

function openLiquidationModal(row) {
    selectedLiquidation.value = row
    showLiquidationModal.value = true
}

function closeLiquidationModal() {
    showLiquidationModal.value = false
    selectedLiquidation.value = null
}

function openDisposalModal(row) {
    selectedDisposal.value = row
    showDisposalModal.value = true
}

function closeDisposalModal() {
    showDisposalModal.value = false
    selectedDisposal.value = null
}

function exportLiquidationExcel() {
    const headers = ['Liquidation ID', 'Source', 'Facility', 'Status', 'Liquidated By', 'Liquidation Date', 'Items Count', 'Total Value']
    const data = (props.liquidations?.data ?? []).map((l) => ({
        'Liquidation ID': l.liquidate_id,
        Source: l.source || 'N/A',
        Facility: l.facility || 'N/A',
        Status: getStatusLabel(l.status),
        'Liquidated By': l.liquidated_by?.name || 'N/A',
        'Liquidation Date': formatDate(l.liquidated_at),
        'Items Count': l.items?.length || 0,
        'Total Value': '$' + formatCurrency(calculateTotalValue(l.items)),
    }))
    const ws = XLSX.utils.json_to_sheet(data.length ? data : [{}], { header: headers })
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, ws, 'Liquidations')
    XLSX.writeFile(wb, `liquidations_${new Date().toISOString().slice(0, 10)}.xlsx`)
}

function exportDisposalExcel() {
    const headers = ['Disposal ID', 'Source', 'Status', 'Disposed By', 'Disposal Date', 'Items Count', 'Total Value']
    const data = (props.disposals?.data ?? []).map((d) => ({
        'Disposal ID': d.disposal_id,
        Source: d.source || 'N/A',
        Status: getStatusLabel(d.status),
        'Disposed By': d.disposed_by?.name || 'N/A',
        'Disposal Date': formatDate(d.disposed_at),
        'Items Count': d.items?.length || 0,
        'Total Value': '$' + formatCurrency(calculateTotalValue(d.items)),
    }))
    const ws = XLSX.utils.json_to_sheet(data.length ? data : [{}], { header: headers })
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, ws, 'Disposals')
    XLSX.writeFile(wb, `disposals_${new Date().toISOString().slice(0, 10)}.xlsx`)
}
</script>
