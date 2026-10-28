<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">监控告警</h1>
                <p class="page-subtitle">在线数 / CPU / 内存 / 同步延迟 / 隧道异常，同一 server+type 1 小时内去抖</p>
            </div>
            <button class="btn-secondary" @click="check">立即检查</button>
        </div>

        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-600">{{ error }}</div>
        <div v-if="notice" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ notice }}</div>

        <div class="grid gap-6 xl:grid-cols-5">
            <div class="card xl:col-span-3">
                <div class="card-header">
                    <div class="card-title">告警列表</div>
                    <div class="flex gap-2">
                        <select v-model="filters.level" class="input max-w-[140px]" @change="reload">
                            <option value="">全部级别</option>
                            <option value="critical">critical</option>
                            <option value="warning">warning</option>
                            <option value="info">info</option>
                        </select>
                        <select v-model="filters.type" class="input max-w-[180px]" @change="reload">
                            <option value="">全部类型</option>
                            <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>级别</th><th>类型</th><th>服务器</th><th>内容</th><th>时间</th></tr></thead>
                        <tbody>
                        <tr v-for="a in alerts" :key="a.id">
                            <td><span :class="levelBadge(a.level)">{{ a.level }}</span></td>
                            <td class="text-xs">{{ a.type }}</td>
                            <td class="text-xs">{{ a.server?.name || '平台' }}</td>
                            <td class="max-w-[320px] truncate text-xs text-slate-600" :title="a.message">{{ a.message }}</td>
                            <td class="text-xs text-slate-500">{{ timeAgo(a.created_at) }}</td>
                        </tr>
                        <tr v-if="!alerts.length"><td colspan="5" class="empty">暂无告警</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :page="page" :last-page="lastPage" :total="total" @change="go" />
            </div>

            <div class="card xl:col-span-2">
                <div class="card-header">
                    <div class="card-title">指标曲线</div>
                    <div class="flex gap-2">
                        <select v-model="metricServer" class="input max-w-[150px]" @change="loadMetrics">
                            <option value="">选择服务器</option>
                            <option v-for="s in servers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <select v-model.number="hours" class="input max-w-[100px]" @change="loadMetrics">
                            <option :value="6">6h</option>
                            <option :value="24">24h</option>
                            <option :value="72">3d</option>
                        </select>
                    </div>
                </div>
                <div class="space-y-5 p-4">
                    <div v-if="latest" class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-slate-50 py-2">
                            <div class="text-[10px] text-slate-400">CPU</div>
                            <div class="text-sm font-semibold">{{ latest.cpu_percent?.toFixed(1) ?? '-' }}%</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 py-2">
                            <div class="text-[10px] text-slate-400">内存</div>
                            <div class="text-sm font-semibold">{{ latest.mem_used_percent?.toFixed(1) ?? '-' }}%</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 py-2">
                            <div class="text-[10px] text-slate-400">在线</div>
                            <div class="text-sm font-semibold">{{ latest.client_online ?? 0 }}</div>
                        </div>
                    </div>

                    <div>
                        <div class="mb-1 flex justify-between text-[11px] text-slate-400"><span>CPU %</span><span>{{ points.cpu.max }}%</span></div>
                        <svg viewBox="0 0 300 60" class="h-16 w-full rounded bg-slate-50">
                            <polyline :points="points.cpu.line" fill="none" stroke="#6366f1" stroke-width="1.5" />
                        </svg>
                    </div>
                    <div>
                        <div class="mb-1 flex justify-between text-[11px] text-slate-400"><span>内存 %</span><span>{{ points.mem.max }}%</span></div>
                        <svg viewBox="0 0 300 60" class="h-16 w-full rounded bg-slate-50">
                            <polyline :points="points.mem.line" fill="none" stroke="#10b981" stroke-width="1.5" />
                        </svg>
                    </div>
                    <div>
                        <div class="mb-1 flex justify-between text-[11px] text-slate-400"><span>在线 client</span><span>{{ points.online.max }}</span></div>
                        <svg viewBox="0 0 300 60" class="h-16 w-full rounded bg-slate-50">
                            <polyline :points="points.online.line" fill="none" stroke="#f59e0b" stroke-width="1.5" />
                        </svg>
                    </div>
                    <div v-if="!latest" class="py-6 text-center text-xs text-slate-400">选择服务器查看指标</div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Pagination from '../components/Pagination.vue';
import { timeAgo } from '../utils';

const types = ['unreachable', 'cpu_high', 'mem_high', 'sync_lag', 'tunnel_down', 'cert_expiring', 'cert_mismatch', 'upgrade_failed'];
const alerts = ref([]);
const servers = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const error = ref('');
const notice = ref('');
const filters = reactive({ level: '', type: '' });

const metricServer = ref('');
const hours = ref(24);
const latest = ref(null);
const history = ref([]);

const maxValue = (values) => Math.max(1, ...values);

const points = computed(() => {
    const build = (key, ceiling) => {
        const values = history.value.map((row) => Number(row[key] ?? 0));
        const max = ceiling ?? maxValue(values);
        if (!values.length) return { line: '', max: 0 };
        const line = values.map((value, index) => {
            const x = values.length === 1 ? 150 : (index / (values.length - 1)) * 298 + 1;
            const y = 59 - Math.min(1, value / (max || 1)) * 58;
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        }).join(' ');
        return { line, max: Math.round(max) };
    };
    return {
        cpu: build('cpu_percent', 100),
        mem: build('mem_used_percent', 100),
        online: build('client_online'),
    };
});

async function loadAlerts() {
    error.value = '';
    try {
        const { data } = await api.get('/admin/monitor/alerts', {
            params: { page: page.value, level: filters.level || undefined, type: filters.type || undefined },
        });
        const p = data.data;
        alerts.value = p.data || [];
        lastPage.value = p.last_page || 1;
        total.value = p.total || 0;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function reload() {
    page.value = 1;
    loadAlerts();
}

function go(next) {
    page.value = next;
    loadAlerts();
}

async function loadMetrics() {
    if (!metricServer.value) return;
    error.value = '';
    try {
        const { data } = await api.get(`/admin/monitor/${metricServer.value}/metrics`, { params: { hours: hours.value } });
        latest.value = data.data.latest;
        history.value = data.data.history || [];
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function check() {
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.post('/admin/monitor/check');
        notice.value = `检查完成，新增告警 ${data.data.new_alerts} 条`;
        await loadAlerts();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function levelBadge(level) {
    return { critical: 'badge-red', warning: 'badge-amber', info: 'badge-slate' }[level] || 'badge-slate';
}

onMounted(async () => {
    await loadAlerts();
    try {
        const { data } = await api.get('/nps/servers');
        servers.value = data.data || [];
        if (servers.value.length) {
            metricServer.value = servers.value[0].id;
            await loadMetrics();
        }
    } catch {
        // ignore
    }
});
</script>
