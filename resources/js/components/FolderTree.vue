<script setup>
import { computed, ref, watch } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Icon } from '@aaix/laravel-islands/vue/helpers';
import { carriesFiles, carriesItems } from '../support/dragItems.js';
import InlineName from './InlineName.vue';

const props = defineProps({
    volumes: { type: Array, required: true },
    currentVolume: { type: String, required: true },
    currentId: { type: Number, default: null },
    path: { type: Array, required: true },
    renamingId: { type: Number, default: null },
    trashOpen: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'open-tab', 'drop', 'contextmenu', 'rename-save', 'rename-cancel', 'open-trash', 'drop-trash']);
const { t } = useTranslations();
const expanded = ref(new Set());
const hovered = ref(null);

const byId = computed(() => new Map(props.volumes.flatMap((volume) => volume.folders.map((folder) => [folder.id, folder]))));
const parentsWithChildren = computed(() => new Set([...byId.value.values()].map((folder) => folder.parentId).filter((id) => id !== null)));

function visibleFolders(volume) {
    return volume.folders.filter((folder) => {
        for (let parentId = folder.parentId; parentId !== null; parentId = byId.value.get(parentId)?.parentId ?? null) {
            if (!expanded.value.has(parentId)) {
                return false;
            }
        }

        return true;
    });
}

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

const targetKey = (volume, id) => `${volume}:${id ?? 'root'}`;

function over(event, volume, id) {
    if (carriesItems(event) || carriesFiles(event)) {
        event.preventDefault();
        event.stopPropagation();
        hovered.value = targetKey(volume, id);
    }
}

function drop(event, volume, id) {
    hovered.value = null;
    emit('drop', event, id, volume);
}

const hasWritableVolume = computed(() => props.volumes.some((volume) => volume.canWrite));

function overTrash(event) {
    if (carriesItems(event)) {
        event.preventDefault();
        event.stopPropagation();
        hovered.value = 'trash';
    }
}

function dropOnTrash(event) {
    hovered.value = null;
    emit('drop-trash', event);
}

const rowClass = (volume, id) => [
    'flex min-h-7 w-full cursor-default select-none items-center gap-1.5 rounded-md pr-2 text-left text-sm',
    hovered.value === targetKey(volume, id) ? 'bg-primary-50 ring-2 ring-inset ring-primary-500 dark:bg-primary-500/10' : '',
    !props.trashOpen && props.currentVolume === volume && props.currentId === id ? 'bg-gray-200/70 font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5',
];
</script>

<template>
    <nav class="space-y-3 p-2" :aria-label="t('Folders')">
        <div v-for="volume in volumes" :key="volume.key" class="space-y-0.5">
            <button
                type="button"
                :class="rowClass(volume.key, null)"
                class="pl-2"
                @click="emit('open', null, volume.key)"
                @contextmenu.prevent.stop
                @dragover="over($event, volume.key, null)"
                @dragleave="hovered = null"
                @drop.stop.prevent="drop($event, volume.key, null)"
            >
                <Icon name="o-server-stack" class="h-4 w-4 shrink-0 text-gray-400" />
                <span class="break-words">{{ volume.name }}</span>
            </button>
            <div
                v-for="folder in visibleFolders(volume)"
                :key="folder.id"
                role="button"
                tabindex="-1"
                :class="rowClass(volume.key, folder.id)"
                :style="{ paddingLeft: `${22 + folder.depth * 14}px` }"
                @click="emit('open', folder.id, volume.key)"
                @mousedown.middle.prevent="emit('open-tab', folder.id, volume.key)"
                @contextmenu.prevent.stop="emit('contextmenu', $event, folder)"
                @dragover="over($event, volume.key, folder.id)"
                @dragleave="hovered = null"
                @drop.stop.prevent="drop($event, volume.key, folder.id)"
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
        </div>
        <button
            v-if="hasWritableVolume"
            type="button"
            class="flex min-h-7 w-full cursor-default select-none items-center gap-1.5 rounded-md pl-2 pr-2 text-left text-sm"
            :class="[
                hovered === 'trash' ? 'bg-primary-50 ring-2 ring-inset ring-primary-500 dark:bg-primary-500/10' : '',
                trashOpen ? 'bg-gray-200/70 font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5',
            ]"
            @click="emit('open-trash')"
            @contextmenu.prevent.stop
            @dragover="overTrash"
            @dragleave="hovered = null"
            @drop.stop.prevent="dropOnTrash"
        >
            <Icon name="o-trash" class="h-4 w-4 shrink-0 text-gray-400" />
            <span class="break-words">{{ t('Trash') }}</span>
        </button>
    </nav>
</template>
