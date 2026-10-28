<script setup>
import { computed, ref } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Icon } from '@aaix/laravel-islands/vue/helpers';
import { itemCount } from '../support/counts.js';
import { carriesFiles, carriesItems, fileIcon, itemKey } from '../support/dragItems.js';
import InlineName from './InlineName.vue';
import SearchSnippet from './SearchSnippet.vue';

const props = defineProps({
    rows: { type: Array, required: true },
    searchTerm: { type: String, default: '' },
    selected: { type: Set, required: true },
    renamingKey: { type: String, default: null },
    renameError: { type: String, default: '' },
    tileSize: { type: Number, required: true },
});

const emit = defineEmits(['select', 'open', 'contextmenu', 'dragstart', 'drop', 'rename-save', 'rename-cancel']);
const { t } = useTranslations();
const hoveredFolder = ref(null);

const gridStyle = computed(() => ({ gridTemplateColumns: `repeat(auto-fill, minmax(${props.tileSize}px, 1fr))` }));
const glyphStyle = computed(() => ({ width: `${Math.round(props.tileSize * 0.42)}px`, height: `${Math.round(props.tileSize * 0.42)}px` }));

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
</script>

<template>
    <div class="grid select-none gap-2 p-3" :style="gridStyle">
        <template v-for="{ item, group, groupStart } in rows" :key="itemKey(item)">
            <div v-if="searchTerm && groupStart" class="col-span-full border-b border-gray-200 pb-1 pt-2 text-xs font-semibold text-gray-600 dark:border-white/10 dark:text-gray-300">
                {{ group === 'content' ? t('Found inside files') : t('Name matches') }}
                <span class="font-normal tabular-nums text-gray-400">· {{ rows.filter((row) => row.group === group).length }}</span>
            </div>
            <div
                :data-item-key="itemKey(item)"
                draggable="true"
                class="flex cursor-default flex-col items-center gap-1.5 rounded-lg p-2"
                :class="item.type === 'folder' && hoveredFolder === item.id ? 'bg-primary-50 ring-2 ring-primary-500 dark:bg-primary-500/10' : ''"
                @click.stop="emit('select', $event, item)"
                @dblclick.stop="emit('open', item)"
                @contextmenu.prevent.stop="emit('contextmenu', $event, item)"
                @dragstart="emit('dragstart', $event, item)"
                @dragover="over($event, item)"
                @dragleave="hoveredFolder = null"
                @drop="drop($event, item)"
            >
                <div
                    class="flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-md"
                    :class="[
                        selected.has(itemKey(item)) ? 'bg-gray-200/80 ring-2 ring-primary-500 dark:bg-white/10' : '',
                        item.thumbnailUrl ? 'bg-gray-50 ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10' : '',
                    ]"
                >
                    <img v-if="item.thumbnailUrl" :src="item.thumbnailUrl" :alt="item.name" loading="lazy" draggable="false" class="h-full w-full object-contain" />
                    <div v-else class="flex flex-col items-center gap-1">
                        <Icon :name="fileIcon(item)" :class="item.type === 'folder' ? 'text-primary-500' : 'text-gray-400'" :style="glyphStyle" />
                        <span v-if="item.type === 'file' && item.extension" class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ item.extension }}</span>
                    </div>
                </div>
                <InlineName v-if="renamingKey === itemKey(item)" :value="item.name" :select-base-name="item.type === 'file'" :error="renameError" @save="emit('rename-save', item, $event)" @cancel="emit('rename-cancel')" />
                <span
                    v-else
                    class="max-w-full break-all rounded px-1.5 text-center text-sm"
                    :class="[selected.has(itemKey(item)) ? 'bg-primary-600 text-white dark:bg-primary-500' : 'text-gray-900 dark:text-white', searchTerm ? 'font-medium' : '']"
                >{{ item.name }}</span>
                <SearchSnippet v-if="item.snippet" class="line-clamp-2 text-center" :text="item.snippet" :term="searchTerm" />
                <span v-if="item.type === 'folder'" class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ itemCount(t, item.itemCount) }}</span>
                <span v-else-if="item.hasNotes || item.commentCount" class="flex items-center gap-2 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                    <Icon v-if="item.hasNotes" name="o-document-text" class="h-3.5 w-3.5" :aria-label="t('Has notes')" />
                    <span v-if="item.commentCount" class="inline-flex items-center gap-0.5"><Icon name="o-chat-bubble-left" class="h-3.5 w-3.5" />{{ item.commentCount }}</span>
                </span>
            </div>
        </template>
    </div>
</template>
