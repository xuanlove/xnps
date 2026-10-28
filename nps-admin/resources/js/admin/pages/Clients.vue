<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">客户端</h1>
                <p class="page-subtitle">nps client 镜像与开通（vkey 由平台统一生成）</p>
            </div>
            <button class="btn-primary" @click="openCreate">＋ 开通 Client</button>
        </div>

        <div class="card">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3">
                <select v-model="filters.server_id" class="input max-w-[220px]" @change="reload">
                    <option value="">全部服务器</option>
                    <option v-for="s in servers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <input v-model="filters.search" class="input max-w-[260px]" placeholder="搜索备注 / vkey" @keyup.enter="reload">
                <button class="btn-secondary btn-xs" @click="reload">查询</button>
            </div>

            <div v-if="error" class="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs text-rose-600">{{ error }}</div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                    <tr>
                        <th>ID / vkey</th>
                        <th>服务器</th>
                        <th>备注</th>
                        <th>状态</th>
                        <th>限额</th>
                        <th>流量</th>
                        <th class="text-right">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr v-for="c in rows" :key="c.id">
                        <td>
                            <div class="text-sm font-medium">#{{ c.id }} <span class="text-xs text-slate-400">remote {{ c.remote_id }}</span></div>
                            <div class="flex items-center gap-1">
                                <code class="text-[11px] text-slate-500">{{ c.vkey }}</code>
                                <button class="text-[11px] text-indigo-500 hover:underline" @click="copy(c.vkey)">复制</button>
                            </div>
                        </td>
                        <td class="text-xs text-slate-500">{{ c.server?.name || c.server_id }}</td>
                        <td class="text-sm">{{ c.remark || '-' }}</td>
                        <td>
                            <span :class="c.is_connect ? 'badge-green' : 'badge-slate'">{{ c.is_connect ? '在线' : '离线' }}</span>
                            <span :class="c.status ? 'badge-indigo ml-1' : 'badge-red ml-1'">{{ c.status ? '启用' : '停用' }}</span>
                        </td>
                        <td class="text-xs text-slate-500">
                            <div>限速 {{ c.rate_limit || '不限' }}{{ c.rate_limit ? ' KB/s' : '' }}</div>
                            <div>连接 {{ c.max_conn || '不限' }} · 隧道 {{ c.max_tunnel || '不限' }}</div>
                        </td>
                        <td class="text-xs text-slate-500">
                            <div>↑ {{ formatBytes(c.flow_out) }} / ↓ {{ formatBytes(c.flow_in) }}</div>
                            <div class="text-slate-400">上限 {{ c.flow_limit || '不限' }}{{ c.flow_limit ? ' MB' : '' }}</div>
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1.5">
                                <button class="btn-secondary btn-xs" @click="openEdit(c)">编辑</button>
                                <button class="btn-secondary btn-xs" @click="toggle(c)">{{ c.status ? '停用' : '启用' }}</button>
                                <button class="btn-danger btn-xs" @click="remove(c)">删除</button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="7" class="empty">暂无客户端</td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :page="page" :last-page="lastPage" :total="total" @change="go" />
        </div>

        <Modal v-if="createOpen" title="开通 Client" @close="createOpen = false">
            <form class="grid grid-cols-2 gap-3" @submit.prevent="create">
                <div class="col-span-2">
                    <label class="label">服务器</label>
                    <select v-model="form.server_id" class="input" required>
                        <option value="" disabled>请选择</option>
                        <option v-for="s in activeServers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div class="col-span-2"><label class="label">备注</label><input v-model="form.remark" class="input"></div>
                <div><label class="label">限速（KB/s，0 不限）</label><input v-model.number="form.rate_limit" type="number" min="0" class="input"></div>
                <div><label class="label">流量上限（MB，0 不限）</label><input v-model.number="form.flow_limit" type="number" min="0" class="input"></div>
                <div><label class="label">最大连接数</label><input v-model.number="form.max_conn" type="number" min="0" class="input"></div>
                <div><label class="label">最大隧道数</label><input v-model.number="form.max_tunnel" type="number" min="0" class="input"></div>
                <div><label class="label">Basic Auth 用户</label><input v-model="form.basic_user" class="input"></div>
                <div><label class="label">Basic Auth 密码</label><input v-model="form.basic_pass" type="password" class="input"></div>
                <label class="flex items-center gap-2 text-xs text-slate-600"><input v-model="form.crypt" type="checkbox"> 加密传输</label>
                <label class="flex items-center gap-2 text-xs text-slate-600"><input v-model="form.compress" type="checkbox"> 压缩</label>
                <label class="col-span-2 flex items-center gap-2 text-xs text-slate-600"><input v-model="form.config_conn_allow" type="checkbox"> 允许客户端配置连接数</label>
            </form>
            <template #footer>
                <button class="btn-secondary" @click="createOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="create">{{ saving ? '开通中…' : '开通' }}</button>
            </template>
        </Modal>

        <Modal v-if="editing" :title="`编辑 Client #${editing.id}`" @close="editing = null">
            <form class="grid grid-cols-2 gap-3" @submit.prevent="update">
                <div class="col-span-2"><label class="label">备注</label><input v-model="editForm.remark" class="input"></div>
                <div><label class="label">限速（KB/s）</label><input v-model.number="editForm.rate_limit" type="number" min="0" class="input"></div>
                <div><label class="label">流量上限（MB）</label><input v-model.number="editForm.flow_limit" type="number" min="0" class="input"></div>
                <div><label class="label">最大连接数</label><input v-model.number="editForm.max_conn" type="number" min="0" class="input"></div>
                <div><label class="label">最大隧道数</label><input v-model.number="editForm.max_tunnel" type="number" min="0" class="input"></div>
                <label class="col-span-2 flex items-center gap-2 text-xs text-slate-600"><input v-model="editForm.status" type="checkbox"> 启用</label>
                <p class="col-span-2 text-xs text-slate-400">只提交修改字段；nps 侧不会清空未提交的 vkey / Basic 认证 / 密码（ext 0.2.0 语义）。</p>
            </form>
            <template #footer>
                <button class="btn-secondary" @click="editing = null">取消</button>
                <button class="btn-primary" :disabled="saving" @click="update">{{ saving ? '保存中…' : '保存' }}</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.vue';
import Pagination from '../components/Pagination.vue';
import { formatBytes } from '../utils';

const rows = ref([]);
const servers = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const error = ref('');
const filters = reactive({ server_id: '', search: '' });
const createOpen = ref(false);
const editing = ref(null);
const saving = ref(false);

const activeServers = computed(() => servers.value.filter((s) => s.status === 'active'));

const form = reactive({
    server_id: '', remark: '', rate_limit: 0, flow_limit: 0, max_conn: 0, max_tunnel: 0,
    basic_user: '', basic_pass: '', crypt: false, compress: false, config_conn_allow: false,
});
const editForm = reactive({ remark: '', rate_limit: 0, flow_limit: 0, max_conn: 0, max_tunnel: 0, status: true });

async function load() {
    error.value = '';
    try {
        const { data } = await api.get('/nps/clients', {
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
    Object.assign(form, {
        server_id: activeServers.value[0]?.id || '', remark: '', rate_limit: 0, flow_limit: 0,
        max_conn: 0, max_tunnel: 0, basic_user: '', basic_pass: '', crypt: false, compress: false, config_conn_allow: false,
    });
    createOpen.value = true;
}

async function create() {
    saving.value = true;
    error.value = '';
    try {
        await api.post('/nps/clients', form);
        createOpen.value = false;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

function openEdit(client) {
    Object.assign(editForm, {
        remark: client.remark, rate_limit: client.rate_limit, flow_limit: client.flow_limit,
        max_conn: client.max_conn, max_tunnel: client.max_tunnel, status: client.status,
    });
    editing.value = client;
}

async function update() {
    saving.value = true;
    error.value = '';
    try {
        await api.put(`/nps/clients/${editing.value.id}`, editForm);
        editing.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function toggle(client) {
    error.value = '';
    try {
        await api.put(`/nps/clients/${client.id}`, { status: !client.status });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function remove(client) {
    if (!confirm(`删除 client #${client.id}？nps 侧将级联删除其全部隧道与域名。`)) return;
    try {
        await api.delete(`/nps/clients/${client.id}`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // ignore
    }
}

onMounted(async () => {
    await load();
    try {
        const { data } = await api.get('/nps/servers');
        servers.value = data.data || [];
    } catch {
        // ignore
    }
});
</script>
