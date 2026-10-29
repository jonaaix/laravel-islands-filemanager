<script setup>
import { computed } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Modal } from '@aaix/laravel-islands/vue/helpers';
import { KEYS, MOD } from '../support/shortcuts.js';

defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const { t } = useTranslations();

const rows = computed(() => [
    [t('Move the selection'), '↑ ↓ ← →'],
    [t('Extend the selection'), 'Shift + ↑ ↓ ← →'],
    [t('Add to the selection'), `${MOD}${t('Click')}`],
    [t('Select all'), KEYS.selectAll],
    [t('Open'), `${KEYS.open} · ${t('Double-click')}`],
    [t('Enclosing folder'), KEYS.enclosing],
    [t('Preview'), KEYS.quickLook],
    [t('Rename'), KEYS.rename],
    [t('Move to trash'), KEYS.remove],
    [t('Undo'), KEYS.undo],
    [t('New folder'), `${KEYS.newFolder} · ${KEYS.newFolderFallback}`],
    [t('New folder with the selection'), KEYS.newFolderWithItems],
    [t('New tab / close tab'), `${KEYS.newTab} / ${KEYS.closeTab}`],
    [t('Open a folder in a new tab'), t('Middle-click')],
    [t('Copy'), KEYS.copy],
    [t('Paste a copy'), KEYS.paste],
    [t('Move here'), KEYS.moveHere],
    [t('Upload files'), KEYS.upload],
    [t('View as list'), KEYS.list],
    [t('View as thumbnails'), KEYS.grid],
    [t('Search in this folder'), KEYS.search],
    [t('Jump to a name'), t('Type its first letters')],
    [t('Context menu'), t('Right-click')],
]);
</script>

<template>
    <Modal :open="open" :title="t('Keyboard shortcuts')" size="sm" :close-label="t('Close')" @close="emit('close')">
        <dl class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-2 text-sm">
            <template v-for="[label, keys] in rows" :key="label">
                <dt class="text-gray-700 dark:text-gray-300">{{ label }}</dt>
                <dd><kbd class="rounded bg-gray-100 px-1.5 py-0.5 font-sans text-xs text-gray-700 ring-1 ring-gray-200 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10">{{ keys }}</kbd></dd>
            </template>
        </dl>
    </Modal>
</template>
