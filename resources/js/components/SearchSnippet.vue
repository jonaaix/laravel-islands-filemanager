<script setup>
import { computed } from 'vue';
import { highlightParts } from '../support/highlight.js';

const props = defineProps({
    text: { type: String, required: true },
    term: { type: String, required: true },
    inverted: { type: Boolean, default: false },
});

const parts = computed(() => highlightParts(props.text, props.term));
</script>

<template>
    <span class="text-xs leading-snug" :class="inverted ? 'text-white/85' : 'text-gray-500 dark:text-gray-400'">
        <template v-for="(part, index) in parts" :key="index">
            <mark v-if="part.match" class="rounded-sm px-0.5" :class="inverted ? 'bg-white/25 text-white' : 'bg-amber-100 text-gray-900 dark:bg-amber-400/25 dark:text-white'">{{ part.text }}</mark>
            <template v-else>{{ part.text }}</template>
        </template>
    </span>
</template>
