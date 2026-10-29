<script setup>
import { computed, ref, watch } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Button, FieldCaption, Icon, IconButton, PersonChip, Skeleton, TextArea, useToast } from '@aaix/laravel-islands/vue/helpers';
import { httpClient, sendJson } from '@aaix/laravel-islands-datagrid/vue';
import { formatDayAndTime, formatFileSize, formatRelative } from '../support/format.js';
import { firstError } from '../support/http.js';
import { itemCount } from '../support/counts.js';
import { fileIcon } from '../support/dragItems.js';
import { MOD } from '../support/shortcuts.js';

const props = defineProps({
    items: { type: Array, required: true },
    folderName: { type: String, required: true },
    folderItemCount: { type: Number, required: true },
    location: { type: String, required: true },
    version: { type: Number, default: 0 },
    endpoints: { type: Object, required: true },
    canWrite: { type: Boolean, default: true },
});

const emit = defineEmits(['changed', 'quick-look']);
const { t } = useTranslations();
const toast = useToast();
const withId = (url, id) => url.replace('__ID__', id);

const detail = ref(null);
const notes = ref('');
const savingNotes = ref(false);
const comment = ref('');
const sendingComment = ref(false);

const single = computed(() => (props.items.length === 1 ? props.items[0] : null));
const file = computed(() => (single.value?.type === 'file' ? single.value : null));
const totalSize = computed(() => props.items.reduce((sum, item) => sum + (item.type === 'file' ? item.size : 0), 0));
const notesDirty = computed(() => detail.value !== null && (notes.value ?? '') !== (detail.value.notes ?? ''));

function apply(fresh) {
    detail.value = fresh;
    notes.value = fresh.notes ?? '';
    emit('changed', fresh);
}

watch(() => [file.value?.id, props.version], async ([id]) => {
    comment.value = '';

    if (!id) {
        detail.value = null;

        return;
    }

    if (detail.value?.id !== id) {
        detail.value = null;
    }

    try {
        const { data } = await httpClient.get(withId(props.endpoints.filesShow, id));

        if (file.value?.id === id) {
            apply(data.data.file);
        }
    } catch (error) {
        toast.danger(firstError(error, t('Could not load the file')));
    }
}, { immediate: true });

async function saveNotes() {
    if (!notesDirty.value) {
        return;
    }

    savingNotes.value = true;

    try {
        const { data } = await sendJson(withId(props.endpoints.filesUpdate, detail.value.id), 'PUT', { notes: notes.value || null });
        apply(data.data.file);
    } catch (error) {
        toast.danger(firstError(error, t('Could not save the notes')));
    } finally {
        savingNotes.value = false;
    }
}

async function sendComment() {
    if (!comment.value.trim()) {
        return;
    }

    sendingComment.value = true;

    try {
        const { data } = await sendJson(withId(props.endpoints.commentsStore, detail.value.id), 'POST', { body: comment.value });
        apply(data.data.file);
        comment.value = '';
    } catch (error) {
        toast.danger(firstError(error, t('Could not add the comment')));
    } finally {
        sendingComment.value = false;
    }
}

async function deleteComment(entry) {
    try {
        const { data } = await sendJson(withId(props.endpoints.commentsDestroy, entry.id), 'DELETE');
        apply(data.data.file);
    } catch (error) {
        toast.danger(firstError(error, t('Could not delete the comment')));
    }
}

function onNotesKey(event) {
    if (event.key === 'Escape') {
        notes.value = detail.value?.notes ?? '';
        event.target.blur();
    } else if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        event.target.blur();
    }
}

function onCommentKey(event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        sendComment();
    }
}
</script>

<template>
    <div class="space-y-4 p-4 text-sm">
        <template v-if="!items.length">
            <div class="flex flex-col items-center gap-2 pt-4 text-center">
                <Icon name="o-folder" class="h-16 w-16 text-primary-500" />
                <p class="break-all font-semibold text-gray-900 dark:text-white">{{ folderName }}</p>
                <p class="tabular-nums text-gray-500 dark:text-gray-400">{{ itemCount(t, folderItemCount) }}</p>
            </div>
        </template>

        <template v-else-if="items.length > 1">
            <div class="flex flex-col items-center gap-2 pt-4 text-center">
                <Icon name="o-document" class="h-16 w-16 text-gray-400" />
                <p class="font-semibold text-gray-900 dark:text-white">{{ t(':count items selected', { count: items.length }) }}</p>
                <p v-if="totalSize" class="tabular-nums text-gray-500 dark:text-gray-400">{{ formatFileSize(totalSize) }}</p>
            </div>
        </template>

        <template v-else>
            <button
                type="button"
                class="flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-lg"
                :class="single.thumbnailUrl ? 'bg-gray-50 ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10' : ''"
                :aria-label="t('Preview')"
                @click="single.type === 'file' && emit('quick-look')"
            >
                <img v-if="single.thumbnailUrl" :src="single.thumbnailUrl" :alt="single.name" class="h-full w-full object-contain" />
                <Icon v-else :name="fileIcon(single)" class="h-20 w-20" :class="single.type === 'folder' ? 'text-primary-500' : 'text-gray-400'" />
            </button>

            <div class="space-y-0.5">
                <p class="break-all font-semibold text-gray-900 dark:text-white">{{ single.name }}</p>
                <p class="text-gray-500 dark:text-gray-400">
                    {{ single.type === 'folder' ? t('Folder') : single.extension ? t(':kind file', { kind: single.extension }) : t('File') }}
                    <span v-if="single.width && single.height" class="tabular-nums"> · {{ single.width }} × {{ single.height }}</span>
                    · <span class="tabular-nums">{{ single.type === 'folder' ? itemCount(t, single.itemCount) : formatFileSize(single.size) }}</span>
                </p>
            </div>

            <div v-if="file" class="flex items-center gap-1">
                <Button tone="secondary" size="sm" :href="file.downloadUrl">
                    <template #icon><Icon name="o-arrow-down-tray" class="h-4 w-4" /></template>
                    {{ t('Download') }}
                </Button>
                <IconButton :label="t('Preview')" size="sm" @click="emit('quick-look')"><Icon name="o-eye" /></IconButton>
                <IconButton :label="t('Open in new tab')" size="sm" :href="file.url" target="_blank"><Icon name="o-arrow-top-right-on-square" /></IconButton>
            </div>

            <div class="space-y-1.5 border-t border-gray-200 pt-3 dark:border-white/10">
                <FieldCaption class="block">{{ t('Information') }}</FieldCaption>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                    <template v-if="file">
                        <dt class="text-gray-500 dark:text-gray-400">{{ t('Uploaded') }}</dt>
                        <dd class="text-gray-900 dark:text-white">{{ formatDayAndTime(file.createdAt) }}</dd>
                        <template v-if="detail?.uploadedBy">
                            <dt class="text-gray-500 dark:text-gray-400">{{ t('By') }}</dt>
                            <dd><PersonChip :name="detail.uploadedBy" :image="single.ownerAvatar" /></dd>
                        </template>
                    </template>
                    <template v-if="file?.takenAt">
                        <dt class="text-gray-500 dark:text-gray-400">{{ t('Taken') }}</dt>
                        <dd class="tabular-nums text-gray-900 dark:text-white">{{ formatDayAndTime(file.takenAt) }}</dd>
                    </template>
                    <template v-if="file?.camera">
                        <dt class="text-gray-500 dark:text-gray-400">{{ t('Camera') }}</dt>
                        <dd class="text-gray-900 dark:text-white">{{ file.camera }}</dd>
                    </template>
                    <dt class="text-gray-500 dark:text-gray-400">{{ t('Modified') }}</dt>
                    <dd class="text-gray-900 dark:text-white">{{ formatDayAndTime(single.updatedAt) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ t('Where') }}</dt>
                    <dd class="break-all text-gray-900 dark:text-white">{{ location }}</dd>
                </dl>
            </div>

            <template v-if="file">
                <div class="space-y-1.5 border-t border-gray-200 pt-3 dark:border-white/10">
                    <div class="flex items-center justify-between">
                        <FieldCaption>{{ t('Notes') }}</FieldCaption>
                        <span v-if="savingNotes" class="text-xs text-gray-400">{{ t('Saving…') }}</span>
                    </div>
                    <Skeleton v-if="!detail" variant="block" height="72px" />
                    <TextArea v-else-if="canWrite" v-model="notes" :rows="3" :placeholder="t('Add notes')" @blur="saveNotes" @keydown="onNotesKey" />
                    <p v-else class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ detail.notes || '–' }}</p>
                </div>

                <div class="space-y-3 border-t border-gray-200 pt-3 dark:border-white/10">
                    <FieldCaption class="block">{{ t('Comments') }}<template v-if="detail?.comments.length"> · <span class="tabular-nums">{{ detail.comments.length }}</span></template></FieldCaption>
                    <Skeleton v-if="!detail" variant="text" :lines="2" />
                    <template v-else>
                        <ul v-if="detail.comments.length" class="space-y-3">
                            <li v-for="entry in detail.comments" :key="entry.id" class="group space-y-0.5">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="text-xs">
                                        <PersonChip :name="entry.author" :image="entry.authorAvatar" />
                                        <span class="text-gray-500 dark:text-gray-400"> · {{ formatRelative(entry.createdAt) }}</span>
                                    </div>
                                    <IconButton v-if="entry.canDelete && canWrite" :label="t('Delete comment')" size="xs" class="opacity-0 transition-opacity duration-500 ease-out focus-within:opacity-100 group-hover:opacity-100" @click="deleteComment(entry)">
                                        <Icon name="o-x-mark" />
                                    </IconButton>
                                </div>
                                <p class="whitespace-pre-line text-gray-700 dark:text-gray-300">{{ entry.body }}</p>
                            </li>
                        </ul>
                        <TextArea v-if="canWrite" v-model="comment" :rows="2" :placeholder="t('Write a comment')" @keydown="onCommentKey" />
                        <div v-if="canWrite" class="flex items-center justify-between gap-2">
                            <span class="text-xs text-gray-400">{{ MOD }}Enter</span>
                            <Button tone="primary" size="sm" :disabled="!comment.trim()" :loading="sendingComment" @click="sendComment">
                                <template #icon><Icon name="o-paper-airplane" class="h-4 w-4" /></template>
                                {{ t('Comment') }}
                            </Button>
                        </div>
                    </template>
                </div>
            </template>
        </template>
    </div>
</template>
