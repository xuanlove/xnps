<template>
    <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-600 text-2xl font-bold text-white shadow-lg shadow-indigo-600/30">
                    X
                </div>
                <h1 class="mt-4 text-2xl font-semibold text-white">XNPS 管理控制台</h1>
                <p class="mt-1 text-sm text-slate-400">集中纳管 · 审批计费 · 证书与升级</p>
            </div>

            <form class="card space-y-4 p-6" @submit.prevent="submit">
                <div>
                    <label class="label">管理员邮箱</label>
                    <input v-model="form.email" type="email" class="input" autocomplete="username" required autofocus>
                </div>
                <div>
                    <label class="label">密码</label>
                    <input v-model="form.password" type="password" class="input" autocomplete="current-password" required>
                </div>

                <div v-if="error" class="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-600">{{ error }}</div>

                <button type="submit" class="btn-primary w-full" :disabled="loading">
                    {{ loading ? '登录中…' : '登录' }}
                </button>

                <p class="text-center text-[11px] leading-relaxed text-slate-400">
                    仅限 <code class="rounded bg-slate-100 px-1">is_admin</code> 用户；登录凭证有效期 12 小时
                </p>
            </form>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import api, { errorMessage, setToken } from '../api';

const router = useRouter();
const form = reactive({ email: '', password: '' });
const loading = ref(false);
const error = ref('');

async function submit() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.post('/admin/login', form);
        setToken(data.data.token);
        router.push('/');
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
}
</script>
