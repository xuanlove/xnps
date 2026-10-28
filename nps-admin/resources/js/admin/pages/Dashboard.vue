<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">仪表盘</h1>
                <p class="page-subtitle">平台总览：服务器健康、同步状态与待办</p>
            </div>
            <button class="btn-secondary" :disabled="loading" @click="load">刷新</button>
        </div>

        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-600">{{ error }}</div>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
            <StatCard label="服务器" :value="stats.servers_active ?? '-'" :hint="`共 ${stats.servers ?? 0}`" tone="indigo" />
            <StatCard label="客户端" :value="stats.clients ?? '-'" :hint="`在线 ${stats.clients_online ?? 0}`" />
            <StatCard label="隧道" :value="stats.tunnels ?? '-'" :hint="`运行 ${stats.tunnels_running ?? 0}`" tone="green" />
            <StatCard label="待办审批" :value="stats.approvals_pending ?? '-'" tone="amber" />
            <StatCard label="24h 告警" :value="stats.alerts_24h ?? '-'" tone="red" />
            <StatCard label="30 天收款" :value="formatCents(stats.payments_paid_30d_cents)" tone="green" />
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="card xl:col-span-2">
                <div class="card-header">
                    <div class="card-title">服务器健康</div>
                    <RouterLink to="/servers" class="text-xs text-indigo-600 hover:underline">全部服务器 →</RouterLink>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>名称</th>
                            <th>状态</th>
                            <th>版本</th>
                            <th>Client</th>
                            <th>同步延迟</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-for="s in servers" :key="s.id">
                            <td>
                                <div class="font-medium text-slate-900">{{ s.name }}</div>
                                <div class="text-xs text-slate-400">{{ s.api_url }}</div>
                            </td>
                            <td>
                                <span :class="s.is_reachable ? 'badge-green' : 'badge-red'">
                                    {{ s.is_reachable ? '可达' : '不可达' }}
                                </span>
                                <span v-if="s.status !== 'active'" class="badge-slate ml-1">停用</span>
                            </td>
                            <td class="text-xs text-slate-500">
                                {{ s.nps_version || '-' }}
                                <span class="text-slate-300">/ ext {{ s.ext_version || '-' }}</span>
                            </td>
                            <td class="text-sm">{{ s.client_count }} <span class="text-xs text-slate-400">(在线 {{ s.client_online }})</span></td>
                            <td>
                                <span :class="lagClass(s.sync_lag_seconds)">{{ lagText(s.sync_lag_seconds) }}</span>
                            </td>
                        </tr>
                        <tr v-if="!servers.length">
                            <td colspan="5" class="empty">暂无服务器，先去「服务器」页注册</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">最近告警</div>
                        <RouterLink to="/alerts" class="text-xs text-indigo-600 hover:underline">更多 →</RouterLink>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="a in alerts" :key="a.id" class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span :class="alertBadge(a.level)">{{ a.level }}</span>
                                <span class="truncate text-xs text-slate-700">{{ a.message }}</span>
                            </div>
                            <div class="mt-1 text-[11px] text-slate-400">{{ timeAgo(a.created_at) }}</div>
                        </li>
                        <li v-if="!alerts.length" class="empty">暂无告警</li>
                    </ul>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">待处理审批</div>
                        <RouterLink to="/approvals" class="text-xs text-indigo-600 hover:underline">去处理 →</RouterLink>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="a in approvals" :key="a.id" class="flex items-center justify-between px-4 py-3">
                            <div class="min-w-0">
                                <div class="truncate text-xs font-medium text-slate-800">
                                    #{{ a.id }} {{ a.type }} · {{ a.user?.name || a.user?.email }}
                                </div>
                                <div class="text-[11px] text-slate-400">{{ timeAgo(a.created_at) }}</div>
                            </div>
                            <span class="badge-indigo">{{ a.status }}</span>
                        </li>
                        <li v-if="!approvals.length" class="empty">暂无待办</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api, { errorMessage } from '../api';
import StatCard from '../components/StatCard.vue';
import { formatCents, timeAgo } from '../utils';

const stats = ref({});
const servers = ref([]);
const alerts = ref([]);
const approvals = ref([]);
const loading = ref(false);
const error = ref('');

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/admin/dashboard');
        stats.value = data.data.stats || {};
        servers.value = data.data.servers || [];
        alerts.value = data.data.alerts || [];
        approvals.value = data.data.approvals || [];
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}

function lagText(seconds) {
    if (seconds === null || seconds === undefined) return '未同步';
    if (seconds < 60) return seconds + 's';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
    return Math.floor(seconds / 3600) + 'h';
}

function lagClass(seconds) {
    if (seconds === null || seconds === undefined || seconds > 300) return 'badge-red';
    if (seconds > 60) return 'badge-amber';
    return 'badge-green';
}

function alertBadge(level) {
    return { critical: 'badge-red', warning: 'badge-amber', info: 'badge-slate' }[level] || 'badge-slate';
}

onMounted(load);
</script>
