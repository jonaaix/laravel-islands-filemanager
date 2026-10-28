<script setup>
import { computed, ref, watch } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Combobox, FormModal } from '@aaix/laravel-islands/vue/helpers';
import { httpClient } from '@aaix/laravel-islands-datagrid/vue';
import FormField from './FormField.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    items: { type: Array, default: () => [] },
    foldersUrl: { type: String, required: true },
    error: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'submit']);
const { t } = useTranslations();
const folders = ref([]);
const targetId = ref(0);

const options = computed(() => folders.value.map((folder) => ({ value: folder.id, label: folder.name, depth: folder.depth })));

watch(() => props.open, async (open) => {
    if (!open) {
        return;
    }

    targetId.value = 0;
    const { data } = await httpClient.get(props.foldersUrl);
    const movedFolders = new Set(props.items.filter((item) => item.type === 'folder').map((item) => item.id));
    folders.value = data.data.folders.filter((folder) => !movedFolders.has(folder.id));
});
</script>

<template>
    <FormModal
        :open="open"
        :title="items.length === 1 ? t('Move :name', { name: items[0].name }) : t('Move :count items', { count: items.length })"
        :busy="busy"
        :cancel-label="t('Cancel')"
        :submit-label="t('Move')"
        @cancel="emit('close')"
        @submit="emit('submit', targetId || null)"
    >
        <FormField :label="t('Destination')" :error="error">
            <Combobox
                v-model="targetId"
                :options="options"
                :all-label="t('Files (top level)')"
                :placeholder="t('Files (top level)')"
                :search-placeholder="t('Search folders…')"
                :empty-label="t('No folder found')"
                :clearable="false"
            />
        </FormField>
    </FormModal>
</template>
