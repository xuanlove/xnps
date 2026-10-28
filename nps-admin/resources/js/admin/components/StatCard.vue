<template>
    <div class="card p-4">
        <div class="text-xs font-medium text-slate-500">{{ label }}</div>
        <div class="mt-1 flex items-baseline gap-2">
            <div class="text-2xl font-semibold" :class="toneClass">{{ value }}</div>
            <span v-if="hint" class="text-xs text-slate-400">{{ hint }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: String,
    value: [String, Number],
    hint: String,
    tone: { type: String, default: 'slate' },
});

const toneClass = computed(() => ({
    slate: 'text-slate-900',
    indigo: 'text-indigo-600',
    green: 'text-emerald-600',
    red: 'text-rose-600',
    amber: 'text-amber-600',
}[props.tone] || 'text-slate-900'));
</script>
