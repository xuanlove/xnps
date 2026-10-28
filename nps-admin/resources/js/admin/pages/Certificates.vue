<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">证书</h1>
                <p class="page-subtitle">证书库唯一 SoT：人工上传 / certd / 内置 ACME，部署与指纹对账</p>
            </div>
            <div class="flex gap-2">
                <button class="btn-secondary" @click="verifyAll">全平台对账</button>
                <button class="btn-secondary" @click="issueOpen = true">ACME 签发</button>
                <button class="btn-primary" @click="uploadOpen = true">＋ 上传证书</button>
            </div>
        </div>

        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-600">{{ error }}</div>
        <div v-if="notice" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ notice }}</div>

        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                <tr>
                    <th>名称 / 域名</th>
                    <th>来源</th>
                    <th>到期</th>
                    <th>指纹</th>
                    <th>部署</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="c in certificates" :key="c.id">
                    <td>
                        <div class="font-medium text-slate-900">#{{ c.id }} {{ c.name }}</div>
                        <div class="text-xs text-slate-400">{{ (c.domains || []).join(', ') }}</div>
                    </td>
                    <td><span class="badge-slate">{{ c.source }}</span></td>
                    <td>
                        <span :class="c.days_until_expiry <= 7 ? 'badge-red' : (c.days_until_expiry <= 30 ? 'badge-amber' : 'badge-green')">
                            {{ c.days_until_expiry }} 天
                        </span>
                        <div class="text-[11px] text-slate-400">{{ formatDate(c.not_after) }}</div>
                    </td>
                    <td><code class="text-[11px] text-slate-500">{{ c.sha256 }}</code></td>
                    <td class="text-xs text-slate-500">
                        <div v-for="d in c.deployments" :key="d.id">
                            {{ d.server?.name }} · {{ d.status }}
                            <span v-if="d.channel" class="text-slate-400">({{ d.channel }})</span>
                        </div>
                        <div v-if="!c.deployments?.length" class="text-slate-400">未部署</div>
                    </td>
                    <td class="text-right">
                        <div class="flex flex-wrap justify-end gap-1.5">
                            <button class="btn-secondary btn-xs" @click="openDeploy(c, 'deploy')">部署</button>
                            <button class="btn-secondary btn-xs" @click="openDeploy(c, 'enable')">启用 HTTPS</button>
                            <button class="btn-secondary btn-xs" @click="openDeploy(c, 'rotate')">轮换</button>
                        </div>
                    </td>
                </tr>
                <tr v-if="!certificates.length"><td colspan="6" class="empty">证书库为空</td></tr>
                </tbody>
            </table>
        </div>

        <!-- 上传 -->
        <Modal v-if="uploadOpen" title="上传证书" wide @close="uploadOpen = false">
            <div class="space-y-3">
                <div><label class="label">名称</label><input v-model="uploadForm.name" class="input" placeholder="mgmt-wildcard"></div>
                <div><label class="label">证书 PEM</label><textarea v-model="uploadForm.cert_pem" rows="7" class="input font-mono text-xs" placeholder="-----BEGIN CERTIFICATE-----"></textarea></div>
                <div><label class="label">私钥 PEM</label><textarea v-model="uploadForm.key_pem" rows="7" class="input font-mono text-xs" placeholder="-----BEGIN PRIVATE KEY-----"></textarea></div>
                <p class="text-xs text-slate-400">服务端会校验证书与私钥匹配后入库；私钥加密存储。</p>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="uploadOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="upload">{{ saving ? '上传中…' : '上传' }}</button>
            </template>
        </Modal>

        <!-- ACME 签发 -->
        <Modal v-if="issueOpen" title="ACME 签发" @close="issueOpen = false">
            <div class="space-y-3">
                <div>
                    <label class="label">签发方式</label>
                    <select v-model="issueForm.mode" class="input">
                        <option value="builtin">内置 ACME（nps 侧 HTTP-01，不支持通配符）</option>
                        <option value="certd">certd（DNS-01，支持通配符）</option>
                    </select>
                </div>
                <div><label class="label">域名（逗号分隔）</label><input v-model="issueForm.domains" class="input" placeholder="nps1.example.com,nps2.example.com"></div>
                <div v-if="issueForm.mode === 'builtin'">
                    <label class="label">挑战应答服务器（HTTP 80 需公网可达）</label>
                    <select v-model="issueForm.server_id" class="input">
                        <option value="">自动选择第一台 active 服务器</option>
                        <option v-for="s in activeServers" :key="s.id" :value="s.id">{{ s.name }} · {{ s.api_url }}</option>
                    </select>
                </div>
                <p class="text-xs text-slate-400">
                    内置 ACME 依赖 NPS_ACME_DIRECTORY_URL（空=Let's Encrypt 生产）；首次使用建议先配 staging 验证。
                </p>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="issueOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="issue">{{ saving ? '签发中（最长 3 分钟）…' : '签发' }}</button>
            </template>
        </Modal>

        <!-- 部署 -->
        <Modal v-if="deployCert" :title="deployTitle" @close="deployCert = null">
            <div class="space-y-3">
                <div>
                    <label class="label">目标服务器</label>
                    <select v-model="deployServerId" class="input">
                        <option value="" disabled>请选择</option>
                        <option v-for="s in activeServers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div v-if="deployAction === 'deploy'">
                    <label class="label">通道</label>
                    <select v-model="deployChannel" class="input">
                        <option value="native">native（Laravel 直推 /ext/cert）</option>
                        <option value="certd">certd（流水线触发）</option>
                    </select>
                </div>
                <p v-if="deployAction === 'enable'" class="text-xs text-amber-600">
                    启用 HTTPS 会依次推证书 → 写 web_open_ssl → 重启 → 探活 → 切换 api_url，过程中管理面短暂不可达。
                </p>
                <p v-if="deployAction === 'rotate'" class="text-xs text-amber-600">
                    轮换会部署新证书并重启 nps，随后做 HTTPS 探活与指纹核验。
                </p>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="deployCert = null">取消</button>
                <button class="btn-primary" :disabled="saving" @click="runDeploy">{{ saving ? '执行中…' : '执行' }}</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.vue';
import { formatDate } from '../utils';

const certificates = ref([]);
const servers = ref([]);
const error = ref('');
const notice = ref('');
const saving = ref(false);

const uploadOpen = ref(false);
const uploadForm = reactive({ name: '', cert_pem: '', key_pem: '' });

const issueOpen = ref(false);
const issueForm = reactive({ mode: 'builtin', domains: '', server_id: '' });

const deployCert = ref(null);
const deployAction = ref('deploy');
const deployServerId = ref('');
const deployChannel = ref('native');

const activeServers = computed(() => servers.value.filter((s) => s.status === 'active'));
const deployTitle = computed(() => {
    const map = { deploy: '部署证书', enable: '启用 HTTPS', rotate: '证书轮换' };
    return `${map[deployAction.value]} · ${deployCert.value?.name || ''}`;
});

async function load() {
    error.value = '';
    try {
        const [certRes, serverRes] = await Promise.all([
            api.get('/admin/certificates'),
            api.get('/nps/servers'),
        ]);
        certificates.value = certRes.data.data || [];
        servers.value = serverRes.data.data || [];
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function upload() {
    saving.value = true;
    error.value = '';
    try {
        await api.post('/admin/certificates', uploadForm);
        uploadOpen.value = false;
        Object.assign(uploadForm, { name: '', cert_pem: '', key_pem: '' });
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function issue() {
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const domains = issueForm.domains.split(',').map((d) => d.trim()).filter(Boolean);
        const { data } = await api.post('/admin/certificates/issue', {
            domains,
            mode: issueForm.mode,
            server_id: issueForm.server_id || undefined,
        });
        issueOpen.value = false;
        notice.value = `已签发：${data.data.name}（到期 ${formatDate(data.data.not_after)}）`;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

function openDeploy(cert, action) {
    deployCert.value = cert;
    deployAction.value = action;
    deployServerId.value = activeServers.value[0]?.id || '';
}

async function runDeploy() {
    if (!deployServerId.value) return;
    saving.value = true;
    error.value = '';
    notice.value = '';
    const id = deployCert.value.id;
    const serverId = deployServerId.value;
    try {
        if (deployAction.value === 'deploy') {
            await api.post(`/admin/certificates/${id}/deploy/${serverId}`, { channel: deployChannel.value });
            notice.value = '证书已部署';
        } else if (deployAction.value === 'enable') {
            await api.post(`/admin/certificates/${id}/enable-https/${serverId}`);
            notice.value = 'HTTPS 已启用并切换 api_url';
        } else {
            await api.post(`/admin/certificates/${id}/rotate/${serverId}`);
            notice.value = '证书轮换完成并通过指纹核验';
        }
        deployCert.value = null;
        await load();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function verifyAll() {
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.post('/admin/certificates/verify-all');
        notice.value = `对账完成：${data.data.ok}/${data.data.servers} 一致`;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

onMounted(load);
</script>
