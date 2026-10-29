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
    currentVolume: { type: String, required: true },
    error: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'submit']);
const { t } = useTranslations();
const volumes = ref([]);
const target = ref('');

const encode = (volume, folderId) => `${volume}:${folderId ?? ''}`;

// Em spaces survive HTML whitespace collapsing, so the picker shows the tree without markup of its own.
const indent = (depth) => '\u2003'.repeat(depth);

const options = computed(() => {
    const moved = new Set(props.items.filter((item) => item.type === 'folder').map((item) => item.id));

    return volumes.value.filter((volume) => volume.key === props.currentVolume).flatMap((volume) => [
        { value: encode(volume.key, null), label: volume.name, depth: 0 },
        ...volume.folders.filter((folder) => !moved.has(folder.id)).map((folder) => ({ value: encode(volume.key, folder.id), label: indent(folder.depth + 1) + folder.name, depth: folder.depth + 1 })),
    ]);
});

function submit() {
    const [volume, folderId] = target.value.split(':');
    emit('submit', { volume, folderId: folderId ? Number(folderId) : null });
}

watch(() => props.open, async (open) => {
    if (!open) {
        return;
    }

    target.value = encode(props.currentVolume, null);
    const { data } = await httpClient.get(props.foldersUrl);
    volumes.value = data.data.volumes;
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
        @submit="submit"
    >
        <FormField :label="t('Destination')" :error="error">
            <Combobox
                v-model="target"
                :options="options"
                :search-placeholder="t('Search folders…')"
                :empty-label="t('No folder found')"
                :clear-option="false"
                :clearable="false"
            />
        </FormField>
    </FormModal>
</template>
