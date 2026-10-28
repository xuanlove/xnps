<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">审批</h1>
                <p class="page-subtitle">客户端 / 隧道开通申请，自动审批未通过时进入人工队列</p>
            </div>
        </div>

        <div class="card">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
                <select v-model="filters.status" class="input max-w-[180px]" @change="reload">
                    <option value="">全部状态</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                </select>
                <select v-model="filters.type" class="input max-w-[160px]" @change="reload">
                    <option value="">全部类型</option>
                    <option value="client">client</option>
                    <option value="tunnel">tunnel</option>
                </select>
                <button class="btn-secondary btn-xs" @click="reload">刷新</button>
            </div>

            <div v-if="error" class="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-600">{{ error }}</div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                    <tr>
                        <th>ID / 类型</th>
                        <th>申请人</th>
                        <th>申请内容</th>
                        <th>状态</th>
                        <th>时间</th>
                        <th class="text-right">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="a in rows" :key="a.id">
                        <td class="text-sm font-medium">#{{ a.id }} <span class="badge-slate">{{ a.type }}</span></td>
                        <td class="text-xs text-slate-600">
                            {{ a.user?.name || '-' }}
                            <div class="text-slate-400">{{ a.user?.email }}</div>
                        </td>
                        <td>
                            <button class="text-left text-xs text-indigo-600 hover:underline" @click="showPayload(a)">
                                {{ summary(a.payload) }}
                            </button>
                        </td>
                        <td>
                            <span :class="statusBadge(a.status)">{{ a.status }}</span>
                            <div v-if="a.fail_reason" class="mt-1 max-w-[220px] truncate text-[11px] text-rose-500">{{ a.fail_reason }}</div>
                        </td>
                        <td class="text-xs text-slate-500">{{ timeAgo(a.created_at) }}</td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1.5">
                                <button v-if="a.status === 'submitted'" class="btn-primary btn-xs" @click="approve(a)">通过</button>
                                <button v-if="a.status === 'submitted'" class="btn-danger btn-xs" @click="reject(a)">驳回</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="6" class="empty">暂无申请</td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="page" :last-page="lastPage" :total="total" @change="go" />
        </div>

        <Modal v-if="payloadApproval" :title="`申请 #${payloadApproval.id} 详情`" wide @close="payloadApproval = null">
            <pre class="max-h-[50vh] overflow-auto rounded-lg bg-slate-950 p-4 text-xs text-slate-200">{{ prettyJson(payloadApproval.payload) }}</pre>
        </Modal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.vue';
import Pagination from '../components/Pagination.vue';
import { prettyJson, timeAgo } from '../utils';

const statuses = ['submitted', 'approved', 'provisioning', 'provisioned', 'rejected', 'expired', 'failed'];
const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const error = ref('');
const filters = reactive({ status: '', type: '' });
const payloadApproval = ref(null);

async function load() {
    error.value = '';
    try {
        const { data } = await api.get('/admin/approvals', {
            params: { page: page.value, status: filters.status || undefined, type: filters.type || undefined },
        });
        const p = data.data;
        rows.value = p.data || [];
        lastPage.value = p.last_page || 1;
        total.value = p.total || 0;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function reload() {
    page.value = 1;
    load();
}

function go(next) {
    page.value = next;
    load();
}

async function approve(approval) {
    error.value = '';
    try {
        await api.post(`/admin/approvals/${approval.id}/approve`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function reject(approval) {
    const reason = prompt('驳回原因：');
    if (!reason) return;
    try {
        await api.post(`/admin/approvals/${approval.id}/reject`, { reason });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function summary(payload) {
    if (!payload) return '-';
    if (payload.mode) return `${payload.mode} → ${payload.target || '-'}${payload.port ? ' :' + payload.port : ''}`;
    return payload.remark || payload.server_id || '-';
}

function statusBadge(status) {
    return {
        submitted: 'badge-amber',
        approved: 'badge-indigo',
        provisioning: 'badge-indigo',
        provisioned: 'badge-green',
        rejected: 'badge-slate',
        expired: 'badge-slate',
        failed: 'badge-red',
    }[status] || 'badge-slate';
}

function showPayload(approval) {
    payloadApproval.value = approval;
}

onMounted(load);
</script>
