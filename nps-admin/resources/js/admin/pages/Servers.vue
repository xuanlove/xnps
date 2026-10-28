<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">服务器</h1>
                <p class="page-subtitle">纳管 nps 实例：探活、同步、配置与证书</p>
            </div>
            <button class="btn-primary" @click="openRegister">＋ 注册服务器</button>
        </div>

        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-600">{{ error }}</div>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                <tr>
                    <th>名称 / API</th>
                    <th>状态</th>
                    <th>版本</th>
                    <th>规模</th>
                    <th>同步</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="s in servers" :key="s.id">
                    <td>
                        <div class="font-medium text-slate-900">#{{ s.id }} {{ s.name }}</div>
                        <div class="text-xs text-slate-400">{{ s.api_url }}</div>
                    </td>
                    <td>
                        <span :class="s.is_reachable ? 'badge-green' : 'badge-red'">{{ s.is_reachable ? '可达' : '不可达' }}</span>
                        <span :class="s.status === 'active' ? 'badge-indigo ml-1' : 'badge-slate ml-1'">{{ s.status }}</span>
                    </td>
                    <td class="text-xs text-slate-500">
                        <div>{{ s.nps_version || '-' }} / ext {{ s.ext_version || '-' }}</div>
                        <div class="text-slate-400">seq {{ s.change_seq }} → {{ s.last_synced_seq }}</div>
                    </td>
                    <td class="text-xs text-slate-500">
                        Client {{ s.client_count }} · 隧道 {{ s.tunnel_count }} · Host {{ s.host_count }}
                    </td>
                    <td class="text-xs">
                        <div>{{ s.last_synced_at ? timeAgo(s.last_synced_at) : '未同步' }}</div>
                        <div class="text-slate-400">对账 {{ s.last_reconciled_at ? timeAgo(s.last_reconciled_at) : '-' }}</div>
                    </td>
                    <td class="text-right">
                        <div class="flex flex-wrap justify-end gap-1.5">
                            <button class="btn-secondary btn-xs" @click="sync(s)">同步</button>
                            <button class="btn-secondary btn-xs" @click="openConfig(s)">配置</button>
                            <button class="btn-secondary btn-xs" @click="openCert(s)">证书</button>
                            <button class="btn-secondary btn-xs" :disabled="s.status !== 'active'" @click="disable(s)">停用</button>
                        </div>
                    </td>
                </tr>
                <tr v-if="!servers.length">
                    <td colspan="6" class="empty">暂无服务器</td>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- 注册 -->
        <Modal v-if="registerOpen" title="注册 nps 服务器" @close="registerOpen = false">
            <form class="space-y-3" @submit.prevent="register">
                <div><label class="label">名称</label><input v-model="form.name" class="input" required></div>
                <div><label class="label">API 地址（webapi 基址）</label><input v-model="form.api_url" class="input" placeholder="http://1.2.3.4:8080" required></div>
                <div><label class="label">auth_key（nps.conf）</label><input v-model="form.auth_key" class="input" required></div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Bridge 类型</label>
                        <select v-model="form.bridge_type" class="input">
                            <option value="tcp">tcp</option>
                            <option value="kcp">kcp</option>
                        </select>
                    </div>
                    <div><label class="label">Bridge 端口</label><input v-model.number="form.bridge_port" type="number" class="input"></div>
                </div>
                <p class="text-xs text-slate-400">注册时会立即探活并执行首次全量对账，失败将自动回滚。</p>
            </form>
            <template #footer>
                <button class="btn-secondary" @click="registerOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="register">{{ saving ? '提交中…' : '注册' }}</button>
            </template>
        </Modal>

        <!-- 配置 -->
        <Modal v-if="configServer" :title="`nps.conf · ${configServer.name}`" wide @close="configServer = null">
            <div v-if="configLoading" class="py-8 text-center text-sm text-slate-400">加载中…</div>
            <div v-else class="max-h-[60vh] space-y-2 overflow-y-auto pr-1">
                <div v-for="entry in entries" :key="entry.key" class="grid grid-cols-3 items-center gap-3">
                    <div class="text-xs font-medium text-slate-600">
                        {{ entry.key }}
                        <span v-if="entry.sensitive" class="badge-amber ml-1">敏感</span>
                    </div>
                    <input v-model="entry.value" class="input col-span-2" :placeholder="entry.sensitive ? '****** 表示不修改' : ''">
                </div>
                <p class="pt-2 text-xs text-slate-400">写入后需重启 nps 生效（白名单键，敏感值保持 ****** 即不修改）。</p>
            </div>
            <template #footer>
                <label class="mr-auto flex items-center gap-2 text-xs text-slate-600">
                    <input v-model="restartAfterSave" type="checkbox" class="rounded border-slate-300"> 保存后重启
                </label>
                <button class="btn-secondary" @click="configServer = null">取消</button>
                <button class="btn-primary" :disabled="configSaving" @click="saveConfig">{{ configSaving ? '保存中…' : '保存配置' }}</button>
            </template>
        </Modal>

        <!-- 证书 -->
        <Modal v-if="certServer" :title="`当前证书 · ${certServer.name}`" @close="certServer = null">
            <div v-if="certInfo" class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">SHA256</span><code class="text-xs">{{ certInfo.sha256 }}</code></div>
                <div class="flex justify-between"><span class="text-slate-500">到期</span><span>{{ certInfo.not_after ? formatDate(certInfo.not_after * 1000) : '-' }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">证书文件</span><span class="text-xs">{{ certInfo.cert_file }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">域名</span><span class="text-xs">{{ (certInfo.dns_names || []).join(', ') || '-' }}</span></div>
            </div>
            <div v-else class="py-6 text-center text-sm text-slate-400">{{ certError || '加载中…' }}</div>
        </Modal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.vue';
import { formatDate, timeAgo } from '../utils';

const servers = ref([]);
const error = ref('');
const registerOpen = ref(false);
const saving = ref(false);
const form = reactive({ name: '', api_url: '', auth_key: '', bridge_type: 'tcp', bridge_port: 8024 });

const configServer = ref(null);
const configLoading = ref(false);
const configSaving = ref(false);
const entries = ref([]);
const restartAfterSave = ref(false);

const certServer = ref(null);
const certInfo = ref(null);
const certError = ref('');

async function load() {
    error.value = '';
    try {
        const { data } = await api.get('/nps/servers');
        servers.value = data.data || [];
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function openRegister() {
    Object.assign(form, { name: '', api_url: '', auth_key: '', bridge_type: 'tcp', bridge_port: 8024 });
    registerOpen.value = true;
}

async function register() {
    saving.value = true;
    error.value = '';
    try {
        await api.post('/nps/servers', form);
        registerOpen.value = false;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function sync(server) {
    error.value = '';
    try {
        await api.post(`/nps/servers/${server.id}/sync`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function disable(server) {
    if (!confirm(`停用服务器 ${server.name}？同步任务将停止。`)) return;
    try {
        await api.delete(`/nps/servers/${server.id}`);
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function openConfig(server) {
    configServer.value = server;
    configLoading.value = true;
    entries.value = [];
    restartAfterSave.value = false;
    try {
        const { data } = await api.get(`/nps/servers/${server.id}/config`);
        entries.value = (data.data.entries || []).map((entry) => ({ ...entry }));
    } catch (e) {
        error.value = errorMessage(e);
        configServer.value = null;
    } finally {
        configLoading.value = false;
    }
}

async function saveConfig() {
    configSaving.value = true;
    error.value = '';
    try {
        const payload = {};
        entries.value.forEach((entry) => { payload[entry.key] = entry.value; });
        await api.post(`/nps/servers/${configServer.value.id}/config`, {
            entries: payload,
            restart: restartAfterSave.value,
        });
        configServer.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        configSaving.value = false;
    }
}

async function openCert(server) {
    certServer.value = server;
    certInfo.value = null;
    certError.value = '';
    try {
        const { data } = await api.get(`/nps/servers/${server.id}/cert`);
        certInfo.value = data.data;
    } catch (e) {
        certError.value = errorMessage(e);
    }
}

onMounted(load);
</script>
