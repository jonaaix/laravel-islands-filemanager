<script setup>
import { ref } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Icon, IconButton, PersonChip } from '@aaix/laravel-islands/vue/helpers';
import { formatDayAndTime, formatFileSize } from '../support/format.js';
import { itemCount } from '../support/counts.js';
import { carriesFiles, carriesItems, fileIcon, itemKey } from '../support/dragItems.js';
import InlineName from './InlineName.vue';
import SearchSnippet from './SearchSnippet.vue';

const INDENT = 18;

defineProps({
    rows: { type: Array, required: true },
    showLocation: { type: Boolean, default: false },
    searchTerm: { type: String, default: '' },
    selected: { type: Set, required: true },
    expanded: { type: Set, required: true },
    renamingKey: { type: String, default: null },
    renameError: { type: String, default: '' },
    sortBy: { type: String, required: true },
    sortDirection: { type: String, required: true },
});

const emit = defineEmits(['select', 'open', 'open-tab', 'toggle', 'contextmenu', 'actions', 'dragstart', 'drop', 'rename-save', 'rename-cancel', 'sort']);
const { t } = useTranslations();
const hoveredFolder = ref(null);

const COLUMNS = [
    { key: 'name', label: 'Name', class: 'w-full pl-3 pr-3 text-left' },
    { key: 'owner', label: 'Owner', class: 'px-3 text-left' },
    { key: 'modified', label: 'Modified', class: 'px-3 text-left' },
    { key: 'added', label: 'Added', class: 'px-3 text-left' },
    { key: 'size', label: 'Size', class: 'px-3 text-right' },
];

function over(event, item) {
    if (item.type === 'folder' && (carriesItems(event) || carriesFiles(event))) {
        event.preventDefault();
        event.stopPropagation();
        hoveredFolder.value = item.id;
    }
}

function drop(event, item) {
    if (item.type === 'folder') {
        event.preventDefault();
        event.stopPropagation();
        hoveredFolder.value = null;
        emit('drop', event, item.id);
    }
}

function rowClass(item, isSelected) {
    if (item.type === 'folder' && hoveredFolder.value === item.id) {
        return 'bg-primary-50 ring-2 ring-inset ring-primary-500 dark:bg-primary-500/10';
    }

    return isSelected ? 'bg-primary-600 dark:bg-primary-500' : 'odd:bg-gray-50/60 hover:bg-gray-100 dark:odd:bg-white/[0.02] dark:hover:bg-white/5';
}

const cellTone = (isSelected) => (isSelected ? 'text-white' : 'text-gray-500 dark:text-gray-400');
</script>

<template>
    <table class="files-list w-full select-none text-sm">
        <thead class="sticky top-0 z-10 bg-white dark:bg-zinc-800">
            <tr class="border-b border-gray-200 dark:border-white/10">
                <th v-for="column in COLUMNS" :key="column.key" class="h-8 font-normal" :class="column.class">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                        :class="sortBy === column.key ? 'text-gray-900 dark:text-white' : ''"
                        @click.stop="emit('sort', column.key)"
                    >
                        {{ t(column.label) }}
                        <Icon v-if="sortBy === column.key" :name="sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down'" class="h-3 w-3" />
                    </button>
                </th>
                <th class="w-10 pr-2"><span class="sr-only">{{ t('Actions') }}</span></th>
            </tr>
        </thead>
        <tbody>
            <template v-for="{ item, depth, path, group, groupStart } in rows" :key="itemKey(item)">
                <tr v-if="searchTerm && groupStart" class="files-list__group">
                    <td colspan="6" class="border-b border-gray-200 bg-gray-50 px-3 pb-1 pt-3 text-xs font-semibold text-gray-600 dark:border-white/10 dark:bg-white/[0.03] dark:text-gray-300">
                        {{ group === 'content' ? t('Found inside files') : t('Name matches') }}
                        <span class="font-normal tabular-nums text-gray-400">· {{ rows.filter((row) => row.group === group).length }}</span>
                    </td>
                </tr>
                <tr
                    :data-item-key="itemKey(item)"
                    draggable="true"
                    class="group cursor-default"
                    :class="rowClass(item, selected.has(itemKey(item)))"
                    @click.stop="emit('select', $event, item)"
                    @dblclick.stop="emit('open', item)"
                @mousedown.middle.prevent="item.type === 'folder' && emit('open-tab', item)"
                    @contextmenu.prevent.stop="emit('contextmenu', $event, item)"
                    @dragstart="emit('dragstart', $event, item)"
                    @dragover="over($event, item)"
                    @dragleave="hoveredFolder = null"
                    @drop="drop($event, item)"
                >
                    <td class="min-w-40 py-1 pl-1.5 pr-3" :class="selected.has(itemKey(item)) ? 'text-white' : 'text-gray-900 dark:text-white'">
                        <span class="flex items-center gap-1.5" :style="{ paddingLeft: `${depth * INDENT}px` }">
                            <button
                                v-if="item.type === 'folder'"
                                type="button"
                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded"
                                :class="selected.has(itemKey(item)) ? 'text-white/80 hover:text-white' : 'text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                :aria-label="expanded.has(item.id) ? t('Collapse') : t('Expand')"
                                :aria-expanded="expanded.has(item.id)"
                                @click.stop="emit('toggle', item)"
                                @dblclick.stop
                            >
                                <Icon :name="expanded.has(item.id) ? 'o-chevron-down' : 'o-chevron-right'" class="h-3.5 w-3.5" />
                            </button>
                            <span v-else class="w-5 shrink-0"></span>
                            <Icon :name="fileIcon(item)" class="h-[18px] w-[18px] shrink-0" :class="selected.has(itemKey(item)) ? 'text-white' : item.type === 'folder' ? 'text-primary-500' : 'text-gray-400'" />
                            <InlineName v-if="renamingKey === itemKey(item)" :value="item.name" :select-base-name="item.type === 'file'" :error="renameError" @save="emit('rename-save', item, $event)" @cancel="emit('rename-cancel')" />
                            <span v-else-if="showLocation" class="min-w-0 py-1">
                                <span class="block break-words">
                                    <span class="text-xs" :class="selected.has(itemKey(item)) ? 'text-white/80' : 'text-gray-500 dark:text-gray-400'">{{ [t('Files'), ...path].join(' › ') }} › </span>
                                    <SearchSnippet class="!text-sm font-semibold" :class="selected.has(itemKey(item)) ? '!text-white' : '!text-gray-900 dark:!text-white'" :text="item.name" :term="searchTerm" :inverted="selected.has(itemKey(item))" />
                                </span>
                                <SearchSnippet
                                    v-if="item.snippet"
                                    class="mt-1 line-clamp-2 border-l-2 pl-2"
                                    :class="selected.has(itemKey(item)) ? 'border-white/40' : 'border-gray-200 dark:border-white/15'"
                                    :text="item.snippet"
                                    :term="searchTerm"
                                    :inverted="selected.has(itemKey(item))"
                                />
                            </span>
                            <span v-else class="min-w-0 break-words">{{ item.name }}</span>
                            <span v-if="item.hasNotes || item.commentCount" class="flex shrink-0 items-center gap-1.5 text-xs tabular-nums opacity-70">
                                <Icon v-if="item.hasNotes" name="o-document-text" class="h-3.5 w-3.5" :aria-label="t('Has notes')" />
                                <span v-if="item.commentCount" class="inline-flex items-center gap-0.5"><Icon name="o-chat-bubble-left" class="h-3.5 w-3.5" />{{ item.commentCount }}</span>
                            </span>
                        </span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-1" :class="cellTone(selected.has(itemKey(item)))">
                        <PersonChip v-if="item.owner" :name="item.owner" :image="item.ownerAvatar" />
                        <template v-else>–</template>
                    </td>
                    <td class="whitespace-nowrap px-3 py-1 tabular-nums" :class="cellTone(selected.has(itemKey(item)))">{{ formatDayAndTime(item.updatedAt) }}</td>
                    <td class="whitespace-nowrap px-3 py-1 tabular-nums" :class="cellTone(selected.has(itemKey(item)))">{{ formatDayAndTime(item.createdAt) }}</td>
                    <td class="whitespace-nowrap px-3 py-1 text-right tabular-nums" :class="cellTone(selected.has(itemKey(item)))">
                        {{ item.type === 'folder' ? itemCount(t, item.itemCount) : formatFileSize(item.size) }}
                    </td>
                    <td class="py-0.5 pr-2 text-right">
                        <IconButton
                            :label="t('Actions')"
                            size="xs"
                            class="opacity-0 transition-opacity duration-500 ease-out focus-within:opacity-100 group-hover:opacity-100"
                            :class="selected.has(itemKey(item)) ? '!text-white opacity-100 hover:!bg-white/20 focus-visible:!bg-white/20' : 'hover:!bg-gray-200 dark:hover:!bg-white/10'"
                            @click.stop="emit('actions', $event, item)"
                            @dblclick.stop
                        >
                            <Icon name="o-ellipsis-horizontal" />
                        </IconButton>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>
</template>
