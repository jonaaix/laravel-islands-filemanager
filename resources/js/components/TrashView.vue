<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Button, Icon, IconButton, PersonChip, Skeleton, useConfirm, useToast } from '@aaix/laravel-islands/vue/helpers';
import { httpClient, sendJson } from '@aaix/laravel-islands-datagrid/vue';
import { formatDayAndTime, formatFileSize } from '../support/format.js';
import { firstError } from '../support/http.js';
import { fileIcon, itemKey, toPayload } from '../support/dragItems.js';
import { hasMod, isTyping } from '../support/shortcuts.js';
import ContextMenu from './ContextMenu.vue';

const props = defineProps({
    volume: { type: String, required: true },
    endpoints: { type: Object, required: true },
});

const emit = defineEmits(['restored', 'count']);
const { t } = useTranslations();
const confirm = useConfirm();
const toast = useToast();

const entries = ref([]);
const retentionDays = ref(null);
const loading = ref(true);
const failed = ref(false);
const busy = ref(false);
const selected = ref(new Set());
const anchorKey = ref(null);
const menu = ref(null);

const chosen = computed(() => entries.value.filter((entry) => selected.value.has(itemKey(entry))));

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        const { data } = await httpClient.get(props.endpoints.trash, { params: { volume: props.volume } });
        entries.value = data.data.entries;
        retentionDays.value = data.data.retentionDays;
        selected.value = new Set([...selected.value].filter((key) => entries.value.some((entry) => itemKey(entry) === key)));
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(() => props.volume, () => {
    selected.value = new Set();
    load();
}, { immediate: true });

watch(() => entries.value.length, (count) => emit('count', count), { immediate: true });

function onRowClick(event, entry) {
    const key = itemKey(entry);

    if (event.shiftKey && anchorKey.value) {
        const keys = entries.value.map(itemKey);
        const [start, end] = [keys.indexOf(anchorKey.value), keys.indexOf(key)].sort((a, b) => a - b);
        selected.value = new Set(keys.slice(start, end + 1));

        return;
    }

    if (hasMod(event)) {
        const next = new Set(selected.value);
        next.has(key) ? next.delete(key) : next.add(key);
        selected.value = next;
    } else {
        selected.value = new Set([key]);
    }

    anchorKey.value = key;
}

function openMenu(event, entry, alignRight = false) {
    if (!selected.value.has(itemKey(entry))) {
        selected.value = new Set([itemKey(entry)]);
        anchorKey.value = itemKey(entry);
    }

    const count = chosen.value.length;
    const position = alignRight ? { x: event.currentTarget.getBoundingClientRect().right, y: event.currentTarget.getBoundingClientRect().bottom, alignRight: true } : { x: event.clientX, y: event.clientY };

    menu.value = {
        position,
        entries: [
            { label: count === 1 ? t('Put back') : t('Put back :count items', { count }), icon: 'o-arrow-uturn-left', action: () => restore(chosen.value) },
            { divider: true },
            { label: count === 1 ? t('Delete permanently') : t('Delete :count items permanently', { count }), icon: 'o-trash', tone: 'danger', action: () => purge(chosen.value) },
        ],
    };
}

async function restore(items) {
    if (!items.length || busy.value) {
        return;
    }

    busy.value = true;

    try {
        await sendJson(props.endpoints.trashRestore, 'POST', toPayload(items));
        toast.success(items.length === 1 ? t('Put back') : t(':count items put back', { count: items.length }));
        selected.value = new Set();
        emit('restored');
        await load();
    } catch (error) {
        toast.danger(firstError(error, t('Could not put the items back')));
    } finally {
        busy.value = false;
    }
}

async function purge(items) {
    if (!items.length || busy.value) {
        return;
    }

    const single = items.length === 1 ? items[0] : null;
    const ok = await confirm({
        title: single ? t('Delete :name permanently?', { name: single.name }) : t('Delete :count items permanently?', { count: items.length }),
        message: t('This cannot be undone.'),
        tone: 'danger',
        confirmLabel: t('Delete permanently'),
        cancelLabel: t('Cancel'),
    });

    if (!ok) {
        return;
    }

    await runPurge(() => sendJson(props.endpoints.trashPurge, 'DELETE', toPayload(items)));
}

async function emptyTrash() {
    const ok = await confirm({
        title: t('Empty the trash?'),
        message: t(':count items are deleted permanently. This cannot be undone.', { count: entries.value.length }),
        tone: 'danger',
        confirmLabel: t('Empty trash'),
        cancelLabel: t('Cancel'),
    });

    if (ok) {
        await runPurge(() => sendJson(`${props.endpoints.trashEmpty}?volume=${encodeURIComponent(props.volume)}`, 'DELETE'));
    }
}

async function runPurge(request) {
    busy.value = true;

    try {
        await request();
        toast.success(t('Deleted permanently'));
        selected.value = new Set();
        await load();
    } catch (error) {
        toast.danger(firstError(error, t('Could not delete the items')));
    } finally {
        busy.value = false;
    }
}

function onKeydown(event) {
    if (menu.value || isTyping(event) || document.querySelector('[role="dialog"]')) {
        return;
    }

    if ((hasMod(event) && event.key === 'Backspace') || event.key === 'Delete') {
        event.preventDefault();
        purge(chosen.value);
    } else if (hasMod(event) && event.key.toLowerCase() === 'a') {
        event.preventDefault();
        selected.value = new Set(entries.value.map(itemKey));
    } else if (event.key === 'Escape') {
        selected.value = new Set();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

const cellTone = (entry) => (selected.value.has(itemKey(entry)) ? 'text-white' : 'text-gray-500 dark:text-gray-400');
</script>

<template>
    <div class="flex min-h-full flex-col" @click="selected = new Set()">
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/[0.03]">
            <p class="text-gray-600 dark:text-gray-300">
                {{ retentionDays === null ? t('Items stay here until the trash is emptied.') : t('Items are deleted permanently after :days days.', { days: retentionDays }) }}
            </p>
            <Button tone="secondary" size="sm" :disabled="!entries.length || busy" @click.stop="emptyTrash">{{ t('Empty trash') }}</Button>
        </div>

        <div v-if="loading && !entries.length" class="space-y-1 p-3">
            <Skeleton v-for="row in 6" :key="row" variant="block" height="28px" />
        </div>

        <div v-else-if="failed" class="flex flex-1 flex-col items-center justify-center gap-1.5 p-6 text-center">
            <p class="font-medium text-gray-900 dark:text-white">{{ t('The trash could not be loaded') }}</p>
            <button type="button" class="text-sm text-primary-600 hover:underline dark:text-primary-400" @click.stop="load">{{ t('Try again') }}</button>
        </div>

        <div v-else-if="!entries.length" class="flex flex-1 flex-col items-center justify-center gap-1.5 p-6 text-center">
            <Icon name="o-trash" class="h-10 w-10 text-gray-300 dark:text-gray-600" />
            <p class="font-medium text-gray-900 dark:text-white">{{ t('The trash is empty') }}</p>
        </div>

        <table v-else class="files-list w-full select-none text-sm">
            <thead class="sticky top-0 z-10 bg-white dark:bg-zinc-800">
                <tr class="whitespace-nowrap border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400 [&>th]:font-medium [&>th]:normal-case [&>th]:tracking-normal">
                    <th class="h-8 w-full pl-3 pr-3 font-medium">{{ t('Name') }}</th>
                    <th class="px-3 font-medium">{{ t('Original location') }}</th>
                    <th class="px-3 font-medium">{{ t('Deleted') }}</th>
                    <th class="px-3 font-medium">{{ t('Deleted by') }}</th>
                    <th class="px-3 text-right font-medium">{{ t('Size') }}</th>
                    <th class="w-10 pr-2"><span class="sr-only">{{ t('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="entry in entries"
                    :key="itemKey(entry)"
                    class="group cursor-default"
                    :class="selected.has(itemKey(entry)) ? 'bg-primary-600 dark:bg-primary-500' : 'odd:bg-gray-50/60 hover:bg-gray-100 dark:odd:bg-white/[0.02] dark:hover:bg-white/5'"
                    @click.stop="onRowClick($event, entry)"
                    @dblclick.stop="restore([entry])"
                    @contextmenu.prevent.stop="openMenu($event, entry)"
                >
                    <td class="min-w-40 py-1 pl-3 pr-3" :class="selected.has(itemKey(entry)) ? 'text-white' : 'text-gray-900 dark:text-white'">
                        <span class="flex items-center gap-1.5">
                            <Icon :name="fileIcon(entry)" class="h-[18px] w-[18px] shrink-0" :class="selected.has(itemKey(entry)) ? 'text-white' : entry.type === 'folder' ? 'text-primary-500' : 'text-gray-400'" />
                            <span class="min-w-0 break-words">{{ entry.name }}</span>
                        </span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-1" :class="cellTone(entry)">{{ entry.originalPath.join(' › ') }}</td>
                    <td class="whitespace-nowrap px-3 py-1 tabular-nums" :class="cellTone(entry)">{{ formatDayAndTime(entry.deletedAt) }}</td>
                    <td class="whitespace-nowrap px-3 py-1" :class="cellTone(entry)">
                        <PersonChip v-if="entry.deletedBy" :name="entry.deletedBy" :image="entry.deletedByAvatar" />
                        <template v-else>–</template>
                    </td>
                    <td class="whitespace-nowrap px-3 py-1 text-right tabular-nums" :class="cellTone(entry)">{{ entry.size === null ? '–' : formatFileSize(entry.size) }}</td>
                    <td class="py-0.5 pr-2 text-right">
                        <IconButton
                            :label="t('Actions')"
                            size="xs"
                            class="opacity-0 transition-opacity duration-500 ease-out focus-within:opacity-100 group-hover:opacity-100"
                            :class="selected.has(itemKey(entry)) ? '!text-white opacity-100 hover:!bg-white/20 focus-visible:!bg-white/20' : 'hover:!bg-gray-200 dark:hover:!bg-white/10'"
                            @click.stop="openMenu($event, entry, true)"
                            @dblclick.stop
                        >
                            <Icon name="o-ellipsis-horizontal" />
                        </IconButton>
                    </td>
                </tr>
            </tbody>
        </table>

        <ContextMenu :position="menu?.position ?? null" :entries="menu?.entries ?? []" @close="menu = null" />
    </div>
</template>
