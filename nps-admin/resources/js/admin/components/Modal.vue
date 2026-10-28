<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10">
        <div class="w-full rounded-xl bg-white shadow-2xl" :class="wide ? 'max-w-4xl' : 'max-w-lg'">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                <h3 class="text-sm font-semibold text-slate-900">{{ title }}</h3>
                <button class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" @click="$emit('close')">✕</button>
            </div>
            <div class="px-5 py-4">
                <slot />
            </div>
            <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-slate-100 bg-slate-50 px-5 py-3">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    title: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});
defineEmits(['close']);
</script>
