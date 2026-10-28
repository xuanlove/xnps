<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">隧道</h1>
                <p class="page-subtitle">端口映射 / 域名隧道，创建采用端口池 + run_status 回读校验</p>
            </div>
            <button class="btn-primary" @click="openCreate">＋ 新建隧道</button>
        </div>

        <div class="card">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
                <select v-model="filters.server_id" class="input max-w-[200px]" @change="reload">
                    <option value="">全部服务器</option>
                    <option v-for="s in servers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <select v-model="filters.mode" class="input max-w-[160px]" @change="reload">
                    <option value="">全部模式</option>
                    <option v-for="m in modes" :key="m" :value="m">{{ m }}</option>
                </select>
                <input v-model="filters.search" class="input max-w-[240px]" placeholder="搜索备注" @keyup.enter="reload">
                <button class="btn-secondary btn-xs" @click="reload">查询</button>
            </div>

            <div v-if="error" class="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-600">{{ error }}</div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                    <tr>
                        <th>ID / 模式</th>
                        <th>Client</th>
                        <th>端口 / 目标</th>
                        <th>状态</th>
                        <th>流量</th>
                        <th class="text-right">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="t in rows" :key="t.id">
                        <td>
                            <div class="text-sm font-medium">#{{ t.id }} <span class="badge-slate">{{ t.mode }}</span></div>
                            <div class="text-[11px] text-slate-400">{{ t.remark || '无备注' }}</div>
                        </td>
                        <td class="text-xs text-slate-500">
                            #{{ t.client_id }}
                            <div class="text-slate-400">{{ t.client?.remark || t.client?.vkey }}</div>
                        </td>
                        <td class="text-xs text-slate-600">
                            <div class="font-medium">{{ t.port || '-' }}</div>
                            <div class="truncate text-slate-400">{{ t.target || '-' }}</div>
                        </td>
                        <td>
                            <span :class="t.run_status ? 'badge-green' : 'badge-red'">{{ t.run_status ? '运行' : '未运行' }}</span>
                            <span :class="t.status ? 'badge-indigo ml-1' : 'badge-slate ml-1'">{{ t.status ? '启用' : '停用' }}</span>
                        </td>
                        <td class="text-xs text-slate-500">
                            ↑ {{ formatBytes(t.flow_out) }} / ↓ {{ formatBytes(t.flow_in) }}
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1.5">
                                <button class="btn-secondary btn-xs" :disabled="t.run_status" @click="start(t)">启动</button>
                                <button class="btn-secondary btn-xs" :disabled="!t.run_status && !t.status" @click="stop(t)">停止</button>
                                <button class="btn-danger btn-xs" @click="remove(t)">删除</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="6" class="empty">暂无隧道</td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="page" :last-page="lastPage" :total="total" @change="go" />
        </div>

        <Modal v-if="createOpen" title="新建隧道" @close="createOpen = false">
            <form class="grid grid-cols-2 gap-3" @submit.prevent="create">
                <div class="col-span-2">
                    <label class="label">Client</label>
                    <select v-model="form.client_id" class="input" required>
                        <option value="" disabled>请选择</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">
                            #{{ c.id }} {{ c.remark || c.vkey }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="label">模式</label>
                    <select v-model="form.mode" class="input">
                        <option v-for="m in modes" :key="m" :value="m">{{ m }}</option>
                    </select>
                </div>
                <div>
                    <label class="label">端口（空=自动分配）</label>
                    <input v-model.number="form.port" type="number" min="1" max="65535" class="input" placeholder="自动">
                </div>
                <div class="col-span-2">
                    <label class="label">内网目标（ip:port / 域名）</label>
                    <input v-model="form.target" class="input" placeholder="127.0.0.1:80" :required="!['socks5','secret','p2p'].includes(form.mode)">
                </div>
                <div class="col-span-2"><label class="label">备注</label><input v-model="form.remark" class="input"></div>
                <div v-if="['secret','p2p'].includes(form.mode)" class="col-span-2">
                    <label class="label">密钥 / 密码</label>
                    <input v-model="form.password" class="input">
                </div>
                <label class="col-span-2 flex items-center gap-2 text-xs text-slate-600">
                    <input v-model="form.local_proxy" type="checkbox"> 本地代理模式
                </label>
            </form>
            <template #footer>
                <button class="btn-secondary" @click="createOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="create">{{ saving ? '创建中…' : '创建' }}</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.vue';
import Pagination from '../components/Pagination.vue';
import { formatBytes } from '../utils';

const modes = ['tcp', 'udp', 'socks5', 'httpProxy', 'secret', 'p2p', 'file'];
const rows = ref([]);
const servers = ref([]);
const clients = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const error = ref('');
const filters = reactive({ server_id: '', mode: '', search: '' });
const createOpen = ref(false);
const saving = ref(false);
const form = reactive({ client_id: '', mode: 'tcp', target: '', port: null, remark: '', password: '', local_proxy: false });

async function load() {
    error.value = '';
    try {
        const { data } = await api.get('/nps/tunnels', {
            params: { page: page.value, per_page: 20, server_id: filters.server_id || undefined, mode: filters.mode || undefined, search: filters.search || undefined },
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

function openCreate() {
    Object.assign(form, { client_id: clients.value[0]?.id || '', mode: 'tcp', target: '', port: null, remark: '', password: '', local_proxy: false });
    createOpen.value = true;
}

async function create() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...form };
        if (!payload.port) delete payload.port;
        await api.post('/nps/tunnels', payload);
        createOpen.value = false;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function start(tunnel) {
    error.value = '';
    try {
        await api.post(`/nps/tunnels/${tunnel.id}/start`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function stop(tunnel) {
    try {
        await api.post(`/nps/tunnels/${tunnel.id}/stop`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function remove(tunnel) {
    if (!confirm(`删除隧道 #${tunnel.id}？`)) return;
    try {
        await api.delete(`/nps/tunnels/${tunnel.id}`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

onMounted(async () => {
    await load();
    try {
        const [serverRes, clientRes] = await Promise.all([
            api.get('/nps/servers'),
            api.get('/nps/clients', { params: { per_page: 100 } }),
        ]);
        servers.value = serverRes.data.data || [];
        clients.value = clientRes.data.data.data || [];
    } catch {
        // ignore
    }
});
</script>
