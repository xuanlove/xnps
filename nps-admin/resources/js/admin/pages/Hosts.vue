<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">域名解析</h1>
                <p class="page-subtitle">HTTP/HTTPS 域名隧道（host / location / scheme）</p>
            </div>
            <button class="btn-primary" @click="openCreate">＋ 新建域名</button>
        </div>

        <div class="card">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
                <select v-model="filters.server_id" class="input max-w-[200px]" @change="reload">
                    <option value="">全部服务器</option>
                    <option v-for="s in servers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <input v-model="filters.search" class="input max-w-[260px]" placeholder="搜索域名 / 备注" @keyup.enter="reload">
                <button class="btn-secondary btn-xs" @click="reload">查询</button>
            </div>

            <div v-if="error" class="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-600">{{ error }}</div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                    <tr>
                        <th>ID / 域名</th>
                        <th>Client</th>
                        <th>目标</th>
                        <th>Scheme / Location</th>
                        <th>流量</th>
                        <th class="text-right">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="h in rows" :key="h.id">
                        <td>
                            <div class="text-sm font-medium">#{{ h.id }} {{ h.host }}</div>
                            <div class="text-[11px] text-slate-400">{{ h.remark || '无备注' }}</div>
                        </td>
                        <td class="text-xs text-slate-500">
                            #{{ h.client_id }}
                            <div class="text-slate-400">{{ h.client?.remark || h.client?.vkey }}</div>
                        </td>
                        <td class="text-xs text-slate-600">{{ h.target || '-' }}</td>
                        <td class="text-xs text-slate-500">{{ h.scheme }} · {{ h.location }}</td>
                        <td class="text-xs text-slate-500">↑ {{ formatBytes(h.flow_out) }} / ↓ {{ formatBytes(h.flow_in) }}</td>
                        <td class="text-right">
                            <button class="btn-danger btn-xs" @click="remove(h)">删除</button>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="6" class="empty">暂无域名</td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="page" :last-page="lastPage" :total="total" @change="go" />
        </div>

        <Modal v-if="createOpen" title="新建域名解析" @close="createOpen = false">
            <form class="grid grid-cols-2 gap-3" @submit.prevent="create">
                <div class="col-span-2">
                    <label class="label">Client</label>
                    <select v-model="form.client_id" class="input" required>
                        <option value="" disabled>请选择</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">#{{ c.id }} {{ c.remark || c.vkey }}</option>
                    </select>
                </div>
                <div class="col-span-2"><label class="label">域名</label><input v-model="form.host" class="input" placeholder="app.example.com" required></div>
                <div class="col-span-2"><label class="label">内网目标</label><input v-model="form.target" class="input" placeholder="127.0.0.1:8080" required></div>
                <div>
                    <label class="label">Scheme</label>
                    <select v-model="form.scheme" class="input">
                        <option value="all">all</option>
                        <option value="http">http</option>
                        <option value="https">https</option>
                    </select>
                </div>
                <div><label class="label">Location</label><input v-model="form.location" class="input" placeholder="/"></div>
                <div class="col-span-2"><label class="label">备注</label><input v-model="form.remark" class="input"></div>
                <div class="col-span-2"><label class="label">自定义 Header（可选）</label><input v-model="form.header" class="input" placeholder="X-Forwarded-For: $remote_addr"></div>
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

const rows = ref([]);
const servers = ref([]);
const clients = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const error = ref('');
const filters = reactive({ server_id: '', search: '' });
const createOpen = ref(false);
const saving = ref(false);
const form = reactive({ client_id: '', host: '', target: '', scheme: 'all', location: '/', remark: '', header: '' });

async function load() {
    error.value = '';
    try {
        const { data } = await api.get('/nps/hosts', {
            params: { page: page.value, per_page: 20, server_id: filters.server_id || undefined, search: filters.search || undefined },
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
    Object.assign(form, { client_id: clients.value[0]?.id || '', host: '', target: '', scheme: 'all', location: '/', remark: '', header: '' });
    createOpen.value = true;
}

async function create() {
    saving.value = true;
    error.value = '';
    try {
        await api.post('/nps/hosts', form);
        createOpen.value = false;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function remove(host) {
    if (!confirm(`删除域名 ${host.host}？`)) return;
    try {
        await api.delete(`/nps/hosts/${host.id}`);
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
