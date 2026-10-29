<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { useTranslations } from '@aaix/laravel-islands/vue';
import { Icon } from '@aaix/laravel-islands/vue/helpers';
import { KEYS } from '../support/shortcuts.js';

const VISIBLE_FOR = 8000;

const props = defineProps({
    notice: { type: Object, default: null },
});

const emit = defineEmits(['undo', 'close']);
const { t } = useTranslations();
const hovered = ref(false);
let timer = null;

function schedule() {
    clearTimeout(timer);

    if (props.notice && !hovered.value) {
        timer = setTimeout(() => emit('close'), VISIBLE_FOR);
    }
}

watch(() => props.notice?.id, schedule);
watch(hovered, schedule);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <Transition
        enter-active-class="transition duration-[180ms] ease-out"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition duration-[160ms] ease-in"
        leave-to-class="translate-y-2 opacity-0"
    >
        <div
            v-if="notice"
            :key="notice.id"
            role="status"
            class="pointer-events-auto flex items-center gap-3 rounded-full bg-gray-900 py-1.5 pl-4 pr-1.5 text-sm text-white shadow-lg dark:bg-zinc-700"
            @mouseenter="hovered = true"
            @mouseleave="hovered = false"
        >
            <span class="whitespace-nowrap">{{ notice.message }}</span>
            <button type="button" class="inline-flex h-7 items-center gap-2 rounded-full bg-white/15 px-3 font-medium hover:bg-white/25" @click="emit('undo')">
                {{ t('Undo') }}
                <kbd class="rounded bg-white/15 px-1 font-sans text-xs text-white/80">{{ KEYS.undo }}</kbd>
            </button>
            <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full text-white/70 hover:bg-white/15 hover:text-white" :aria-label="t('Close')" @click="emit('close')">
                <Icon name="o-x-mark" class="h-4 w-4" />
            </button>
        </div>
    </Transition>
</template>
