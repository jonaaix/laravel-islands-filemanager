<script setup>
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Button, Icon, Modal } from '@aaix/laravel-islands/vue/helpers';
import { fileIcon } from '../support/dragItems.js';

defineProps({
    file: { type: Object, default: null },
});

const emit = defineEmits(['close']);
const { t } = useTranslations();
</script>

<template>
    <Modal :open="file !== null" :title="file?.name ?? ''" size="xl" :close-label="t('Close')" @close="emit('close')">
        <div v-if="file" class="flex h-full items-center justify-center overflow-auto bg-gray-50 p-4 dark:bg-white/5">
            <img v-if="file.isImage" :src="file.url" :alt="file.name" class="max-h-full max-w-full object-contain" />
            <iframe v-else-if="file.isPdf" :src="file.url" :title="file.name" class="h-full w-full rounded-md bg-white" />
            <div v-else class="flex flex-col items-center gap-3">
                <Icon :name="fileIcon(file)" class="h-20 w-20 text-gray-400" />
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('No preview for this file type') }}</p>
                <Button tone="secondary" :href="file.downloadUrl">
                    <template #icon><Icon name="o-arrow-down-tray" class="h-4 w-4" /></template>
                    {{ t('Download') }}
                </Button>
            </div>
        </div>
    </Modal>
</template>
