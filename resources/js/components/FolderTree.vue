<script setup>
import { computed, ref, watch } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Icon } from '@aaix/laravel-islands/vue/helpers';
import { carriesFiles, carriesItems } from '../support/dragItems.js';
import InlineName from './InlineName.vue';

const props = defineProps({
    folders: { type: Array, required: true },
    currentId: { type: Number, default: null },
    path: { type: Array, required: true },
    renamingId: { type: Number, default: null },
});

const emit = defineEmits(['open', 'drop', 'contextmenu', 'rename-save', 'rename-cancel']);
const { t } = useTranslations();
const expanded = ref(new Set());
const hovered = ref(undefined);

const parentsWithChildren = computed(() => new Set(props.folders.map((folder) => folder.parentId).filter((id) => id !== null)));
const byId = computed(() => new Map(props.folders.map((folder) => [folder.id, folder])));

const visible = computed(() => props.folders.filter((folder) => {
    for (let parentId = folder.parentId; parentId !== null; parentId = byId.value.get(parentId)?.parentId ?? null) {
        if (!expanded.value.has(parentId)) {
            return false;
        }
    }

    return true;
}));

watch(() => props.path, (path) => {
    const next = new Set(expanded.value);
    path.forEach((step) => next.add(step.id));
    expanded.value = next;
}, { immediate: true });

function toggle(folder) {
    const next = new Set(expanded.value);
    next.has(folder.id) ? next.delete(folder.id) : next.add(folder.id);
    expanded.value = next;
}

function over(event, id) {
    if (carriesItems(event) || carriesFiles(event)) {
        event.preventDefault();
        event.stopPropagation();
        hovered.value = id;
    }
}

function drop(event, id) {
    hovered.value = undefined;
    emit('drop', event, id);
}

const rowClass = (id) => [
    'flex min-h-7 w-full cursor-default select-none items-center gap-1.5 rounded-md pr-2 text-left text-sm',
    hovered.value === id ? 'bg-primary-50 ring-2 ring-inset ring-primary-500 dark:bg-primary-500/10' : '',
    props.currentId === id ? 'bg-gray-200/70 font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5',
];
</script>

<template>
    <nav class="space-y-0.5 p-2" :aria-label="t('Folders')">
        <button type="button" :class="rowClass(null)" class="pl-2" @click="emit('open', null)" @contextmenu.prevent.stop @dragover="over($event, null)" @dragleave="hovered = undefined" @drop.stop.prevent="drop($event, null)">
            <Icon name="o-home" class="h-4 w-4 shrink-0 text-gray-400" />
            {{ t('Files') }}
        </button>
        <div
            v-for="folder in visible"
            :key="folder.id"
            role="button"
            tabindex="-1"
            :class="rowClass(folder.id)"
            :style="{ paddingLeft: `${8 + folder.depth * 14}px` }"
            @click="emit('open', folder.id)"
            @contextmenu.prevent.stop="emit('contextmenu', $event, folder)"
            @dragover="over($event, folder.id)"
            @dragleave="hovered = undefined"
            @drop.stop.prevent="drop($event, folder.id)"
        >
            <button
                v-if="parentsWithChildren.has(folder.id)"
                type="button"
                class="-ml-1 flex h-5 w-5 shrink-0 items-center justify-center rounded text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                :aria-label="expanded.has(folder.id) ? t('Collapse') : t('Expand')"
                @click.stop="toggle(folder)"
            >
                <Icon :name="expanded.has(folder.id) ? 'o-chevron-down' : 'o-chevron-right'" class="h-3.5 w-3.5" />
            </button>
            <span v-else class="-ml-1 w-5 shrink-0"></span>
            <Icon name="o-folder" class="h-4 w-4 shrink-0 text-primary-500" />
            <InlineName v-if="renamingId === folder.id" :value="folder.name" @save="emit('rename-save', folder, $event)" @cancel="emit('rename-cancel')" />
            <span v-else class="min-w-0 break-words py-1">{{ folder.name }}</span>
        </div>
    </nav>
</template>
