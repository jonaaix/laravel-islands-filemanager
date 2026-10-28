<script setup>
import { nextTick, onMounted, ref } from 'vue';

const props = defineProps({
    value: { type: String, required: true },
    selectBaseName: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['save', 'cancel']);
const input = ref(null);
const draft = ref(props.value);
let finished = false;

onMounted(async () => {
    await nextTick();
    input.value.focus();
    const dot = props.selectBaseName ? props.value.lastIndexOf('.') : -1;
    input.value.setSelectionRange(0, dot > 0 ? dot : props.value.length);
});

function save() {
    if (finished) {
        return;
    }

    finished = true;
    const name = draft.value.trim();

    if (!name || name === props.value) {
        emit('cancel');
    } else {
        emit('save', name);
    }
}

function cancel() {
    finished = true;
    emit('cancel');
}
</script>

<template>
    <span class="inline-flex min-w-0 flex-col">
        <input
            ref="input"
            v-model="draft"
            type="text"
            class="h-7 w-full min-w-40 rounded-md border border-primary-500 bg-white px-1.5 text-sm text-gray-900 outline-none ring-2 ring-primary-500/30 dark:bg-gray-900 dark:text-white"
            @click.stop
            @dblclick.stop
            @keydown.stop.enter.prevent="save"
            @keydown.stop.esc.prevent="cancel"
            @keydown.stop
            @blur="save"
        />
        <span v-if="error" class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ error }}</span>
    </span>
</template>
