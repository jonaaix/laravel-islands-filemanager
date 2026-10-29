<script setup>
import { ref } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Icon, IconButton } from '@aaix/laravel-islands/vue/helpers';
import { carriesFiles, carriesItems } from '../support/dragItems.js';

defineProps({
    tabs: { type: Array, required: true },
    active: { type: Number, required: true },
    labelOf: { type: Function, required: true },
});

const emit = defineEmits(['select', 'close', 'add', 'drop']);
const { t } = useTranslations();
const hovered = ref(null);

function over(event, index) {
    if (carriesItems(event) || carriesFiles(event)) {
        event.preventDefault();
        hovered.value = index;
    }
}

function drop(event, index) {
    hovered.value = null;
    emit('drop', event, index);
}
</script>

<template>
    <div class="flex h-8 shrink-0 items-stretch border-b border-gray-200 bg-gray-100/80 dark:border-white/10 dark:bg-white/[0.03]" role="tablist" :aria-label="t('Tabs')">
        <div
            v-for="(tab, index) in tabs"
            :key="index"
            role="tab"
            :aria-selected="index === active"
            class="group relative flex min-w-0 flex-1 cursor-default select-none items-center justify-center border-r border-gray-200 px-7 text-xs dark:border-white/10"
            :class="[
                index === active ? 'bg-white font-medium text-gray-900 dark:bg-zinc-800 dark:text-white' : 'text-gray-600 hover:bg-gray-200/60 dark:text-gray-400 dark:hover:bg-white/5',
                hovered === index ? 'ring-2 ring-inset ring-primary-500' : '',
            ]"
            @click="emit('select', index)"
            @mousedown.middle.prevent="emit('close', index)"
            @dragover="over($event, index)"
            @dragleave="hovered = null"
            @drop.prevent="drop($event, index)"
        >
            <span class="absolute inset-y-0 left-1 flex items-center opacity-0 transition-opacity duration-500 ease-out focus-within:opacity-100 group-hover:opacity-100">
                <IconButton :label="t('Close tab')" size="xs" @click.stop="emit('close', index)">
                    <Icon name="o-x-mark" />
                </IconButton>
            </span>
            <span class="truncate">{{ labelOf(tab) }}</span>
        </div>
        <IconButton :label="t('New tab')" size="xs" class="mx-1 self-center" @click="emit('add')">
            <Icon name="o-plus" />
        </IconButton>
    </div>
</template>
