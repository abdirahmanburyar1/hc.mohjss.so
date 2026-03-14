<template>
    <Head title="Facility LMIS Report" />
    <AuthenticatedLayout
        title="Facility LMIS Report"
        description="View LMIS report for your facility by period"
        img="/assets/images/report.png"
    >
        <div class="mb-[80px]">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="text-gray-900">
                    <div class="flex justify-between items-center mb-4 px-4 py-3">
                        <div class="flex items-center space-x-2">
                            <Link :href="route('reports.index')" class="text-blue-600 hover:text-blue-800">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                            </Link>
                            <h2 class="text-lg font-bold text-gray-900">Facility LMIS Report</h2>
                        </div>
                        <div class="flex space-x-2">
                            <button
                                v-if="month_year && (!reports || !reports.items || !reports.items.length)"
                                @click="createLmisReport"
                                :disabled="creating"
                                class="inline-flex items-center px-3 py-1.5 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {{ creating ? 'Creating...' : 'Create LMIS Report' }}
                            </button>
                            <button
                                v-if="reports && reports.items && reports.items.length"
                                @click="exportToExcel"
                                :disabled="isExporting"
                                class="inline-flex items-center px-3 py-1.5 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 disabled:opacity-50"
                            >
                                {{ isExporting ? 'Exporting...' : 'Export Excel' }}
                            </button>
                        </div>
                    </div>

                    <div class="bg-gray-50 p-3 mx-4 rounded-lg mb-4">
                        <p class="text-xs text-gray-600 mb-3">Select report period to view LMIS data for {{ facility?.name }}.</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Report Period</label>
                                <input
                                    v-model="month_year"
                                    type="month"
                                    class="w-full text-sm border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    @change="applyFilters"
                                />
                            </div>
                        </div>
                    </div>

                    <div v-if="reports && reports.items && reports.items.length > 0" class="mx-4 mb-6">
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-4">
                            <div class="flex flex-wrap justify-between items-center gap-4">
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900">LMIS Report</h3>
                                    <p class="text-sm text-gray-600 mt-1">Facility: {{ reports.facility?.name }} | Period: {{ formatReportPeriod(reports.report_period) }} | Status: <span class="font-medium capitalize">{{ reports.status }}</span></p>
                                </div>
                                <!-- Facility LMIS Report: only Submit for review and Return to draft (no Mark as reviewed / Reject / Approve) -->
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        v-if="reports.status === 'draft'"
                                        type="button"
                                        @click="submitForReview"
                                        :disabled="workflowLoading"
                                        class="inline-flex items-center px-3 py-1.5 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 disabled:opacity-50"
                                    >
                                        Submit for review
                                    </button>
                                    <button
                                        v-if="reports.status === 'submitted'"
                                        type="button"
                                        @click="returnToDraft"
                                        :disabled="workflowLoading"
                                        class="inline-flex items-center px-3 py-1.5 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 disabled:opacity-50"
                                    >
                                        Return to draft
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Opening</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Received</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Issued</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Pos. Adj.</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Neg. Adj.</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Closing</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">AMC</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">MoS</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Stockout Days</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="item in reports.items" :key="item.id" class="hover:bg-gray-50">
                                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ item.product?.name || 'N/A' }}</td>
                                            <td class="px-6 py-4 text-sm text-right text-gray-900">{{ formatNumber(item.opening_balance) }}</td>
                                            <td class="px-6 py-4 text-sm text-right text-green-600">{{ formatNumber(item.stock_received) }}</td>
                                            <td class="px-6 py-4 text-sm text-right text-red-600">{{ formatNumber(item.stock_issued) }}</td>
                                            <td class="px-6 py-4 text-sm text-right text-gray-900">{{ formatNumber(item.positive_adjustments) }}</td>
                                            <td class="px-6 py-4 text-sm text-right text-gray-900">{{ formatNumber(item.negative_adjustments) }}</td>
                                            <td class="px-6 py-4 text-sm text-right font-semibold text-blue-600">{{ formatNumber(item.closing_balance) }}</td>
                                            <td class="px-6 py-4 text-sm text-right text-gray-900">{{ formatAmc(item) }}</td>
                                            <td class="px-6 py-4 text-sm text-right text-gray-900">{{ formatMos(item) }}</td>
                                            <td class="px-6 py-4 text-sm text-center text-gray-900">{{ item.stockout_days || 0 }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="month_year && (!reports || !reports.items || !reports.items.length)" class="mx-4 py-12 text-center">
                        <p class="text-gray-600 mb-4">No LMIS report found for {{ formatReportPeriod(month_year) }}.</p>
                        <p class="text-sm text-gray-500 mb-4">Create a report from facility inventory movements using the button above.</p>
                        <button
                            @click="createLmisReport"
                            :disabled="creating"
                            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {{ creating ? 'Creating...' : 'Create LMIS Report' }}
                        </button>
                    </div>

                    <div v-else class="mx-4 py-12 text-center">
                        <p class="text-gray-600">Select a report period to view or create LMIS data.</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import * as XLSX from 'xlsx';
import axios from 'axios';
import Swal from 'sweetalert2';
import { useToast } from 'vue-toastification';

const toast = useToast();
const props = defineProps({
    reports: { type: Object, default: null },
    facility: { type: Object, required: true },
    products: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const month_year = ref(props.filters?.month_year || new Date().toISOString().slice(0, 7));
const isExporting = ref(false);
const creating = ref(false);
const workflowLoading = ref(false);

function applyFilters() {
    router.get(route('reports.facility-lmis-report'), { month_year: month_year.value }, {
        preserveState: true,
        only: ['reports', 'filters'],
    });
}

async function submitForReview() {
    if (!props.reports?.id) return;
    const result = await Swal.fire({
        title: 'Submit for review?',
        text: 'You will not be able to edit this report after submission.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, submit',
    });
    if (!result.isConfirmed) return;
    workflowLoading.value = true;
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.submit'), { report_id: props.reports.id });
        if (data.success) {
            toast.success('Report submitted for review.');
            applyFilters();
        } else {
            toast.error(data.message || 'Failed to submit.');
        }
    } catch (e) {
        toast.error(e?.response?.data?.message || 'An error occurred.');
    } finally {
        workflowLoading.value = false;
    }
}

async function startReview() {
    if (!props.reports?.id) return;
    const result = await Swal.fire({
        title: 'Mark as reviewed?',
        text: 'This will mark the review as complete.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#ca8a04',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, mark reviewed',
    });
    if (!result.isConfirmed) return;
    workflowLoading.value = true;
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.start-review'), { report_id: props.reports.id });
        if (data.success) {
            toast.success('Report marked as reviewed.');
            applyFilters();
        } else {
            toast.error(data.message || 'Failed to update.');
        }
    } catch (e) {
        toast.error(e?.response?.data?.message || 'An error occurred.');
    } finally {
        workflowLoading.value = false;
    }
}

async function approveReport() {
    if (!props.reports?.id) return;
    const result = await Swal.fire({
        title: 'Approve report?',
        text: 'This report will be marked as approved.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#16a34a',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, approve',
    });
    if (!result.isConfirmed) return;
    workflowLoading.value = true;
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.approve'), { report_id: props.reports.id });
        if (data.success) {
            toast.success('Report approved.');
            applyFilters();
        } else {
            toast.error(data.message || 'Failed to approve.');
        }
    } catch (e) {
        toast.error(e?.response?.data?.message || 'An error occurred.');
    } finally {
        workflowLoading.value = false;
    }
}

async function rejectReport() {
    if (!props.reports?.id) return;
    const { value: comments } = await Swal.fire({
        title: 'Reject report',
        text: 'Please provide a reason for rejection:',
        input: 'textarea',
        inputPlaceholder: 'Rejection reason...',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Reject',
        inputValidator: (v) => (!v || !v.trim() ? 'A reason is required.' : null),
    });
    if (comments == null) return;
    workflowLoading.value = true;
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.reject'), { report_id: props.reports.id, comments: comments });
        if (data.success) {
            toast.success('Report rejected.');
            applyFilters();
        } else {
            toast.error(data.message || 'Failed to reject.');
        }
    } catch (e) {
        toast.error(e?.response?.data?.message || 'An error occurred.');
    } finally {
        workflowLoading.value = false;
    }
}

async function returnToDraft() {
    if (!props.reports?.id) return;
    const result = await Swal.fire({
        title: 'Return to draft?',
        text: 'The report will be editable again.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#6b7280',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, return to draft',
    });
    if (!result.isConfirmed) return;
    workflowLoading.value = true;
    try {
        const { data } = await axios.post(route('reports.monthly-inventory.return-to-draft'), { report_id: props.reports.id });
        if (data.success) {
            toast.success('Report returned to draft.');
            applyFilters();
        } else {
            toast.error(data.message || 'Failed to return to draft.');
        }
    } catch (e) {
        toast.error(e?.response?.data?.message || 'An error occurred.');
    } finally {
        workflowLoading.value = false;
    }
}

function createLmisReport() {
    if (!month_year.value) return;
    creating.value = true;
    router.post(route('reports.create-lmis-report'), { month_year: month_year.value }, {
        preserveScroll: true,
        onFinish: () => { creating.value = false; },
        onSuccess: () => {
            applyFilters();
        },
    });
}

function formatNumber(v) {
    return parseFloat(v || 0).toLocaleString();
}

function formatAmc(item) {
    const amc = item?.amc ?? item?.average_monthly_consumption;
    if (amc == null || amc === '') return '–';
    return formatNumber(Math.round(Number(amc)));
}

function formatMos(item) {
    const amc = Number(item?.amc ?? item?.average_monthly_consumption) || 0;
    const totalClosing = Number(item?.closing_balance) || 0;
    if (amc <= 0) return '–';
    const mos = totalClosing / amc;
    return Number.isInteger(mos) ? mos : Math.round(mos * 10) / 10;
}

function formatReportPeriod(period) {
    if (!period) return 'N/A';
    const [y, m] = period.split('-');
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    return `${months[parseInt(m, 10) - 1] || m} ${y}`;
}

function exportToExcel() {
    if (!props.reports?.items?.length) return;
    isExporting.value = true;
    try {
        const headers = ['Product', 'Opening', 'Received', 'Issued', 'Pos. Adj.', 'Neg. Adj.', 'Closing', 'AMC', 'MoS', 'Stockout Days'];
        const rows = props.reports.items.map(item => {
            const rawAmc = item.amc ?? item.average_monthly_consumption ?? 0;
            const amc = Math.round(Number(rawAmc));
            const closing = Number(item.closing_balance) || 0;
            const mos = amc > 0 ? (Number.isInteger(closing / amc) ? closing / amc : Math.round((closing / amc) * 10) / 10) : '';
            return [
                item.product?.name || '',
                item.opening_balance ?? 0,
                item.stock_received ?? 0,
                item.stock_issued ?? 0,
                item.positive_adjustments ?? 0,
                item.negative_adjustments ?? 0,
                item.closing_balance ?? 0,
                amc || '',
                mos,
                item.stockout_days ?? 0,
            ];
        });
        const ws = XLSX.utils.aoa_to_sheet([['LMIS Report', ''], ['Facility:', props.reports.facility?.name], ['Period:', formatReportPeriod(props.reports.report_period)], [], headers, ...rows]);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'LMIS');
        XLSX.writeFile(wb, `LMIS_${props.reports.report_period}.xlsx`);
    } finally {
        isExporting.value = false;
    }
}
</script>
