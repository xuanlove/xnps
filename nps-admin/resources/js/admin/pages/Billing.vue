<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="page-title">计费与支付</h1>
                <p class="page-subtitle">套餐、钱包、月度账单与支付宝 / 微信支付流水</p>
            </div>
            <div class="flex gap-2">
                <button class="btn-secondary" @click="settle">结算上月</button>
                <button class="btn-primary" @click="openPlan">＋ 新建套餐</button>
            </div>
        </div>

        <div v-if="error" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-600">{{ error }}</div>
        <div v-if="notice" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ notice }}</div>

        <div class="card">
            <div class="flex gap-1 border-b border-slate-100 px-3 pt-2">
                <button v-for="t in tabs" :key="t.key" class="rounded-t-lg px-4 py-2 text-sm font-medium"
                        :class="tab === t.key ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        @click="switchTab(t.key)">{{ t.label }}</button>
            </div>

            <!-- 套餐 -->
            <div v-if="tab === 'plans'" class="overflow-x-auto">
                <table class="table">
                    <thead>
                    <tr><th>ID</th><th>名称</th><th>月费</th><th>流量</th><th>隧道数</th><th>限速</th><th>连接数</th></tr>
                    </thead>
                    <tbody>
                    <tr v-for="p in plans" :key="p.id">
                        <td>#{{ p.id }}</td>
                        <td class="font-medium">{{ p.name }}</td>
                        <td>{{ formatCents(p.monthly_fee_cents) }}</td>
                        <td>{{ p.monthly_flow_mb }} MB</td>
                        <td>{{ p.max_tunnels || '不限' }}</td>
                        <td>{{ p.rate_limit_kb || '不限' }} KB/s</td>
                        <td>{{ p.max_conn || '不限' }}</td>
                    </tr>
                    <tr v-if="!plans.length"><td colspan="7" class="empty">暂无套餐</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- 用户 -->
            <div v-if="tab === 'users'">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                        <tr><th>用户</th><th>套餐</th><th>余额</th><th>Client</th><th>注册</th><th class="text-right">操作</th></tr>
                        </thead>
                        <tbody>
                        <tr v-for="u in users" :key="u.id">
                            <td>
                                <div class="font-medium">{{ u.name }}</div>
                                <div class="text-xs text-slate-400">{{ u.email }} <span v-if="u.is_admin" class="badge-indigo ml-1">admin</span></div>
                            </td>
                            <td class="text-xs">{{ u.active_subscription?.plan?.name || '-' }}</td>
                            <td>{{ formatCents(u.wallet?.balance_cents) }}</td>
                            <td>{{ u.nps_clients_count ?? '-' }}</td>
                            <td class="text-xs text-slate-500">{{ timeAgo(u.created_at) }}</td>
                            <td class="text-right">
                                <button class="btn-secondary btn-xs" @click="openRecharge(u)">充值</button>
                            </td>
                        </tr>
                        <tr v-if="!users.length"><td colspan="6" class="empty">暂无用户</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :page="userPage" :last-page="userLastPage" :total="userTotal" @change="goUsers" />
            </div>

            <!-- 账单 -->
            <div v-if="tab === 'bills'">
                <div class="flex items-center gap-3 px-4 py-3">
                    <select v-model="billStatus" class="input max-w-[180px]" @change="loadBills(1)">
                        <option value="">全部状态</option>
                        <option value="pending">pending</option>
                        <option value="paid">paid</option>
                        <option value="unpaid">unpaid</option>
                    </select>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                        <tr><th>ID</th><th>用户</th><th>账期</th><th>用量</th><th>套餐费</th><th>超额费</th><th>合计</th><th>状态</th></tr>
                        </thead>
                        <tbody>
                        <tr v-for="b in bills" :key="b.id">
                            <td>#{{ b.id }}</td>
                            <td class="text-xs">{{ b.user?.name || b.user?.email }}</td>
                            <td>{{ b.period }}</td>
                            <td class="text-xs">{{ formatBytes(b.used_bytes) }}</td>
                            <td>{{ formatCents(b.plan_fee_cents) }}</td>
                            <td>{{ formatCents(b.overage_fee_cents) }}</td>
                            <td class="font-medium">{{ formatCents(b.total_fee_cents) }}</td>
                            <td><span :class="billBadge(b.status)">{{ b.status }}</span></td>
                        </tr>
                        <tr v-if="!bills.length"><td colspan="8" class="empty">暂无账单</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :page="billPage" :last-page="billLastPage" :total="billTotal" @change="loadBills" />
            </div>

            <!-- 支付流水 -->
            <div v-if="tab === 'payments'">
                <div class="flex items-center gap-3 px-4 py-3">
                    <select v-model="payChannel" class="input max-w-[160px]" @change="loadPayments(1)">
                        <option value="">全部渠道</option>
                        <option value="alipay">支付宝</option>
                        <option value="wechat">微信</option>
                        <option value="mock">mock</option>
                    </select>
                    <select v-model="payStatus" class="input max-w-[160px]" @change="loadPayments(1)">
                        <option value="">全部状态</option>
                        <option value="pending">pending</option>
                        <option value="paid">paid</option>
                        <option value="failed">failed</option>
                        <option value="expired">expired</option>
                    </select>
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                        <tr><th>订单号</th><th>用户</th><th>渠道</th><th>金额</th><th>状态</th><th>渠道交易号</th><th>创建</th></tr>
                        </thead>
                        <tbody>
                        <tr v-for="p in payments" :key="p.id">
                            <td class="text-xs"><code>{{ p.out_trade_no }}</code></td>
                            <td class="text-xs">{{ p.user?.name || p.user?.email }}</td>
                            <td><span class="badge-slate">{{ p.channel }}</span></td>
                            <td class="font-medium">{{ formatCents(p.amount_cents) }}</td>
                            <td><span :class="payBadge(p.status)">{{ p.status }}</span></td>
                            <td class="max-w-[180px] truncate text-xs text-slate-400">{{ p.transaction_id || '-' }}</td>
                            <td class="text-xs text-slate-500">{{ timeAgo(p.created_at) }}</td>
                        </tr>
                        <tr v-if="!payments.length"><td colspan="7" class="empty">暂无支付流水</td></tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :page="payPage" :last-page="payLastPage" :total="payTotal" @change="loadPayments" />
            </div>
        </div>

        <Modal v-if="rechargeUser" :title="`充值 · ${rechargeUser.email}`" @close="rechargeUser = null">
            <div class="space-y-3">
                <div><label class="label">金额（分）</label><input v-model.number="rechargeForm.amount_cents" type="number" min="1" class="input"></div>
                <div><label class="label">备注</label><input v-model="rechargeForm.remark" class="input" placeholder="管理员充值"></div>
                <p class="text-xs text-slate-400">当前余额 {{ formatCents(rechargeUser.wallet?.balance_cents) }}</p>
            </div>
            <template #footer>
                <button class="btn-secondary" @click="rechargeUser = null">取消</button>
                <button class="btn-primary" :disabled="saving" @click="recharge">{{ saving ? '提交中…' : '确认充值' }}</button>
            </template>
        </Modal>

        <Modal v-if="planOpen" title="新建套餐" @close="planOpen = false">
            <form class="grid grid-cols-2 gap-3" @submit.prevent="createPlan">
                <div class="col-span-2"><label class="label">名称</label><input v-model="planForm.name" class="input" required></div>
                <div><label class="label">月费（分）</label><input v-model.number="planForm.monthly_fee_cents" type="number" min="0" class="input"></div>
                <div><label class="label">月流量（MB，0 不限）</label><input v-model.number="planForm.monthly_flow_mb" type="number" min="0" class="input"></div>
                <div><label class="label">最大隧道数（0 不限）</label><input v-model.number="planForm.max_tunnels" type="number" min="0" class="input"></div>
                <div><label class="label">限速（KB/s）</label><input v-model.number="planForm.rate_limit_kb" type="number" min="0" class="input"></div>
                <div><label class="label">最大连接数</label><input v-model.number="planForm.max_conn" type="number" min="0" class="input"></div>
            </form>
            <template #footer>
                <button class="btn-secondary" @click="planOpen = false">取消</button>
                <button class="btn-primary" :disabled="saving" @click="createPlan">{{ saving ? '保存中…' : '创建' }}</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import api, { errorMessage } from '../api';
import Modal from '../components/Modal.vue';
import Pagination from '../components/Pagination.vue';
import { formatBytes, formatCents, timeAgo } from '../utils';

const tabs = [
    { key: 'plans', label: '套餐' },
    { key: 'users', label: '用户与钱包' },
    { key: 'bills', label: '账单' },
    { key: 'payments', label: '支付流水' },
];
const tab = ref('plans');
const error = ref('');
const notice = ref('');
const saving = ref(false);

const plans = ref([]);
const planOpen = ref(false);
const planForm = reactive({ name: '', monthly_fee_cents: 0, monthly_flow_mb: 0, max_tunnels: 0, rate_limit_kb: 0, max_conn: 0 });

const users = ref([]);
const userPage = ref(1);
const userLastPage = ref(1);
const userTotal = ref(0);
const rechargeUser = ref(null);
const rechargeForm = reactive({ amount_cents: 1000, remark: '' });

const bills = ref([]);
const billPage = ref(1);
const billLastPage = ref(1);
const billTotal = ref(0);
const billStatus = ref('');

const payments = ref([]);
const payPage = ref(1);
const payLastPage = ref(1);
const payTotal = ref(0);
const payChannel = ref('');
const payStatus = ref('');

function switchTab(key) {
    tab.value = key;
    loadTab();
}

async function loadTab() {
    error.value = '';
    if (tab.value === 'plans') await loadPlans();
    if (tab.value === 'users') await loadUsers(1);
    if (tab.value === 'bills') await loadBills(1);
    if (tab.value === 'payments') await loadPayments(1);
}

async function loadPlans() {
    try {
        const { data } = await api.get('/admin/plans');
        plans.value = data.data || [];
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function loadUsers(page) {
    userPage.value = page;
    try {
        const { data } = await api.get('/admin/users', { params: { page } });
        const p = data.data;
        users.value = p.data || [];
        userLastPage.value = p.last_page || 1;
        userTotal.value = p.total || 0;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function goUsers(page) {
    loadUsers(page);
}

async function loadBills(page) {
    billPage.value = page;
    try {
        const { data } = await api.get('/admin/bills', {
            params: { page, status: billStatus.value || undefined },
        });
        const p = data.data;
        bills.value = p.data || [];
        billLastPage.value = p.last_page || 1;
        billTotal.value = p.total || 0;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

async function loadPayments(page) {
    payPage.value = page;
    try {
        const { data } = await api.get('/admin/payments', {
            params: { page, channel: payChannel.value || undefined, status: payStatus.value || undefined },
        });
        const p = data.data;
        payments.value = p.data || [];
        payLastPage.value = p.last_page || 1;
        payTotal.value = p.total || 0;
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function openRecharge(user) {
    rechargeUser.value = user;
    Object.assign(rechargeForm, { amount_cents: 1000, remark: '管理员充值' });
}

async function recharge() {
    saving.value = true;
    error.value = '';
    try {
        await api.post(`/admin/users/${rechargeUser.value.id}/recharge`, rechargeForm);
        rechargeUser.value = null;
        await loadUsers(userPage.value);
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

function openPlan() {
    Object.assign(planForm, { name: '', monthly_fee_cents: 0, monthly_flow_mb: 0, max_tunnels: 0, rate_limit_kb: 0, max_conn: 0 });
    planOpen.value = true;
}

async function createPlan() {
    saving.value = true;
    error.value = '';
    try {
        await api.post('/admin/plans', planForm);
        planOpen.value = false;
        await loadPlans();
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function settle() {
    if (!confirm('结算上一个账期？将为所有有订阅/用量的用户生成账单并扣款。')) return;
    error.value = '';
    notice.value = '';
    try {
        const { data } = await api.post('/admin/settle');
        const d = data.data;
        notice.value = `结算完成：用户 ${d.users}，账单 ${d.bills}，未付 ${d.unpaid}（账期 ${d.period}）`;
        await loadTab();
    } catch (e) {
        error.value = errorMessage(e);
    }
}

function billBadge(status) {
    return { paid: 'badge-green', unpaid: 'badge-red', pending: 'badge-amber' }[status] || 'badge-slate';
}

function payBadge(status) {
    return { paid: 'badge-green', pending: 'badge-amber', failed: 'badge-red', expired: 'badge-slate' }[status] || 'badge-slate';
}

onMounted(loadTab);
</script>
