<template>
    <div v-if="lastPage > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3">
        <div class="text-xs text-slate-500">共 {{ total }} 条 · 第 {{ page }} / {{ lastPage }} 页</div>
        <div class="flex gap-2">
            <button class="btn-secondary btn-xs" :disabled="page <= 1" @click="$emit('change', page - 1)">上一页</button>
            <button class="btn-secondary btn-xs" :disabled="page >= lastPage" @click="$emit('change', page + 1)">下一页</button>
        </div>
    </div>
</template>

<script setup>
defineProps({
    page: { type: Number, default: 1 },
    lastPage: { type: Number, default: 1 },
    total: { type: Number, default: 0 },
});
defineEmits(['change']);
</script>
