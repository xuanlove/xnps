<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">版本升级</h1>
                <p class="page-subtitle">制品库 + 灰度 canary + 分批滚动 + 失败回滚（ext 升级需 core 版本一致）</p>
            </div>
            <div class="flex gap-2">
                <button class="btn-secondary" @click="startOpen = true">启动升级</button>
                <button class="btn-primary" @click="uploadOpen = true">＋ 上传制品</button>
            </div>
        </div>

        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-600">{{ error }}</div>
        <div v-if="notice" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ notice }}</div>

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="card overflow-x-auto">
                <div class="card-header"><div class="card-title">制品库</div></div>
                <table class="table">
                    <thead><tr><th>版本</th><th>通道</th><th>兼容 core</th><th>大小</th><th>上传</th></tr></thead>
                    <tbody>
                    <tr v-for="a in artifacts" :key="a.id">
                        <td><div class="font-medium">{{ a.version }}</div><code class="text-[10px] text-slate-400">{{ a.sha256.slice(0, 16) }}…</code></td>
                        <td><span class="badge-slate">{{ a.channel }}</span></td>
                        <td class="text-xs">{{ a.compatible_core_version }}</td>
                        <td class="text-xs">{{ formatBytes(a.size_bytes) }}</td>
                        <td class="text-xs text-slate-500">{{ timeAgo(a.created_at) }}</td>
                    </tr>
                    <tr v-if="!artifacts.length"><td colspan="5" class="empty">暂无制品</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card overflow-x-auto">
                <div class="card-header"><div class="card-title">升级记录</div></div>
                <table class="table">
                    <thead><tr><th>Run</th><th>制品</th><th>状态</th><th>服务器</th><th>开始</th><th></th></tr></thead>
                    <tbody>
                    <tr v-for="r in runs" :key="r.id">
                        <td>#{{ r.id }}</td>
                        <td class="text-xs">{{ r.artifact?.version || '-' }}</td>
                        <td><span :class="runBadge(r.status)">{{ r.status }}</span></td>
                        <td class="text-xs">{{ (r.server_ids || []).length }} 台</td>
                        <td class="text-xs text-slate-500">{{ r.started_at ? timeAgo(r.started_at) : '-' }}</td>
                        <td class="text-right"><button class="btn-secondary btn-xs" @click="openRun(r)">详情</button></td>
                    </tr>
                    <tr v-if="!runs.length"><td colspan="6" class="empty">暂无升级记录</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Modal v-if="uploadOpen" title="上传升级制品" @close="uploadOpen = false">
            <form class="space-y-3" @submit.prevent="upload">
                <div><label class="label">版本签名（{core}-ext{ext}）</label><input v-model="uploadForm.version" class="input" placeholder="0.26.10-ext0.3.0" required></div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">通道</label>
                        <select v-model="uploadForm.channel" class="input"><option value="stable">stable</option><option value="canary">canary</option></select>
                    </div>
                    <div><label class="label">兼容 core 版本</label><input v-model="uploadForm.compatible_core_version" class="input" placeholder="0.26.0"></div>
                </div>
                <div><label class="label">二进制（nps / xnps，≤256MB）</label><input type="file" class="input" @change="onFile" required></div>
                <div><label class="label">变更说明</label><textarea v-model="uploadForm.notes" rows="2" class="input"></textarea></div>
                <p class="text-xs text-slate-400">版本号必须与 nps /ext/version 的 {version}-ext{ext_version} 完全一致，否则升级预检会拒绝。</p>
            </form>
            <template #footer>
                <button class="btn-secondary" @click="uploadOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="upload">{{ saving ? '上传中…' : '上传' }}</button>
            </template>
        </Modal>

        <Modal v-if="startOpen" title="启动滚动升级" wide @close="startOpen = false">
            <div class="space-y-3">
                <div>
                    <label class="label">制品</label>
                    <select v-model="startForm.artifact_id" class="input">
                        <option value="" disabled>请选择</option>
                        <option v-for="a in artifacts" :key="a.id" :value="a.id">{{ a.version }}（兼容 core {{ a.compatible_core_version }}）</option>
                    </select>
                </div>
                <div>
                    <label class="label">目标服务器</label>
                    <div class="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 p-3">
                        <label v-for="s in activeServers" :key="s.id" class="flex items-center gap-2 text-xs text-slate-600">
                            <input v-model="startForm.server_ids" type="checkbox" :value="s.id"> {{ s.name }}
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Canary 服务器</label>
                        <select v-model="startForm.canary_server_id" class="input">
                            <option value="">第一台</option>
                            <option v-for="s in activeServers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>
                    <div><label class="label">观察窗口（秒）</label><input v-model.number="startForm.observe_seconds" type="number" min="0" max="900" class="input"></div>
                </div>
                <p class="text-xs text-amber-600">
                    预检要求：core 版本与制品兼容、在线 npc 版本一致、旧版本制品已在库（否则无法回滚）。
                </p>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="startOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="startRun">{{ saving ? '提交中…' : '启动' }}</button>
            </template>
        </Modal>

        <Modal v-if="runDetail" :title="`升级 Run #${runDetail.id}`" wide @close="runDetail = null">
            <div class="space-y-3">
                <div class="flex flex-wrap gap-4 text-xs text-slate-600">
                    <span>状态：<strong>{{ runDetail.status }}</strong></span>
                    <span>制品：{{ runDetail.artifact?.version }}</span>
                    <span>开始：{{ formatDate(runDetail.started_at) }}</span>
                </div>
                <table class="table">
                    <thead><tr><th>服务器</th><th>状态</th><th>旧 → 新</th><th>错误</th></tr></thead>
                    <tbody>
                    <tr v-for="step in runDetail.steps" :key="step.id">
                        <td class="text-xs">{{ step.server?.name || step.server_id }}</td>
                        <td><span :class="stepBadge(step.status)">{{ step.status }}</span></td>
                        <td class="text-xs">{{ step.old_version || '-' }} → {{ step.new_version || '-' }}</td>
                        <td class="max-w-[280px] text-xs text-rose-500">{{ step.error || '-' }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.vue';
import { formatBytes, formatDate, timeAgo } from '../utils';

const artifacts = ref([]);
const runs = ref([]);
const servers = ref([]);
const error = ref('');
const notice = ref('');
const saving = ref(false);

const uploadOpen = ref(false);
const uploadForm = reactive({ version: '', channel: 'stable', compatible_core_version: '0.26.0', notes: '', binary: null });

const startOpen = ref(false);
const startForm = reactive({ artifact_id: '', server_ids: [], canary_server_id: '', observe_seconds: 0 });

const runDetail = ref(null);
const activeServers = computed(() => servers.value.filter((s) => s.status === 'active'));

async function load() {
    error.value = '';
    try {
        const [artifactRes, runRes, serverRes] = await Promise.all([
            api.get('/admin/upgrades/artifacts'),
            api.get('/admin/upgrades/runs'),
            api.get('/nps/servers'),
        ]);
        artifacts.value = artifactRes.data.data || [];
        runs.value = runRes.data.data || [];
        servers.value = serverRes.data.data || [];
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function onFile(event) {
    uploadForm.binary = event.target.files[0] || null;
}

async function upload() {
    if (!uploadForm.binary) return;
    saving.value = true;
    error.value = '';
    try {
        const fd = new FormData();
        Object.entries(uploadForm).forEach(([key, value]) => {
            if (value !== null) fd.append(key, value);
        });
        await api.post('/admin/upgrades/artifacts', fd);
        uploadOpen.value = false;
        Object.assign(uploadForm, { version: '', channel: 'stable', compatible_core_version: '0.26.0', notes: '', binary: null });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function startRun() {
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.post(`/admin/upgrades/start/${startForm.artifact_id}`, {
            server_ids: startForm.server_ids,
            canary_server_id: startForm.canary_server_id || undefined,
            observe_seconds: startForm.observe_seconds,
        });
        startOpen.value = false;
        notice.value = `升级 Run #${data.data.id} 已启动（异步），可在右侧查看进度`;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function openRun(run) {
    try {
        const { data } = await api.get(`/admin/upgrades/runs/${run.id}`);
        runDetail.value = data.data;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function runBadge(status) {
    return { running: 'badge-amber', succeeded: 'badge-green', failed: 'badge-red', aborted: 'badge-slate' }[status] || 'badge-slate';
}

function stepBadge(status) {
    return {
        pending: 'badge-slate', prechecking: 'badge-amber', upgrading: 'badge-indigo',
        verifying: 'badge-amber', succeeded: 'badge-green', rolled_back: 'badge-amber', failed: 'badge-red',
    }[status] || 'badge-slate';
}

onMounted(load);
</script>
