<template>
    <div class="flex min-h-screen">
        <aside
            class="fixed inset-y-0 left-0 z-40 w-60 transform bg-slate-900 transition-transform lg:static lg:translate-x-0"
            :class="open ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 items-center gap-2.5 border-b border-slate-800 px-5">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">X</div>
                <div>
                    <div class="text-sm font-semibold text-white">XNPS</div>
                    <div class="text-[10px] uppercase tracking-wider text-slate-400">Admin Console</div>
                </div>
            </div>

            <nav class="space-y-5 overflow-y-auto px-3 py-4" style="max-height: calc(100vh - 4rem)">
                <div>
                    <div class="px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500">概览</div>
                    <RouterLink to="/" class="nav-link" exact-active-class="nav-link-active">
                        <span class="text-base">◉</span> 仪表盘
                    </RouterLink>
                    <RouterLink to="/alerts" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">⚑</span> 监控告警
                    </RouterLink>
                </div>
                <div>
                    <div class="px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500">纳管</div>
                    <RouterLink to="/servers" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">▤</span> 服务器
                    </RouterLink>
                    <RouterLink to="/clients" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">◫</span> 客户端
                    </RouterLink>
                    <RouterLink to="/tunnels" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">⇄</span> 隧道
                    </RouterLink>
                    <RouterLink to="/hosts" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">⌂</span> 域名解析
                    </RouterLink>
                </div>
                <div>
                    <div class="px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500">运营</div>
                    <RouterLink to="/approvals" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">✓</span> 审批
                    </RouterLink>
                    <RouterLink to="/billing" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">¥</span> 计费与支付
                    </RouterLink>
                    <RouterLink to="/certificates" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">⛨</span> 证书
                    </RouterLink>
                    <RouterLink to="/upgrades" class="nav-link" active-class="nav-link-active">
                        <span class="text-base">↥</span> 版本升级
                    </RouterLink>
                </div>
            </nav>
        </aside>

        <div v-if="open" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="open = false"></div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 lg:px-8">
                <div class="flex items-center gap-3">
                    <button class="btn-ghost lg:hidden" @click="open = !open">☰</button>
                    <div class="text-sm text-slate-500">
                        <span class="font-medium text-slate-800">集中管理平台</span>
                        <span class="mx-1.5">/</span>
                        <span>{{ route.meta.title || route.name }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden text-xs text-slate-400 sm:inline">xnps ext 0.3.0</span>
                    <div class="flex items-center gap-2 rounded-lg bg-slate-100 px-2.5 py-1.5">
                        <div class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-bold text-white">
                            {{ initials }}
                        </div>
                        <span class="max-w-[160px] truncate text-xs text-slate-600">{{ user?.email || 'admin' }}</span>
                    </div>
                    <button class="btn-secondary btn-xs" @click="logout">退出</button>
                </div>
            </header>

            <main class="flex-1 p-4 lg:p-8">
                <router-view />
            </main>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api, { clearToken, getToken } from '../api';

const route = useRoute();
const router = useRouter();
const open = ref(false);
const user = ref(null);

const initials = computed(() => (user.value?.name || user.value?.email || 'A').slice(0, 1).toUpperCase());

onMounted(async () => {
    if (!getToken()) return;
    try {
        const { data } = await api.get('/admin/me');
        user.value = data.data;
    } catch {
        // 401 已由拦截器处理
    }
});

async function logout() {
    try {
        await api.post('/admin/logout');
    } catch {
        // ignore
    }
    clearToken();
    router.push('/login');
}
</script>
