<script setup>
import { computed } from 'vue';
import { Icon, MenuItem, Popover } from '@aaix/laravel-islands/vue/helpers';

const props = defineProps({
    position: { type: Object, default: null },
    entries: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const MENU_WIDTH = 272;

// As wide as the menu, so every placement rule of the popover lands the menu's left edge on the pointer.
const anchor = computed(() => (props.position ? { getBoundingClientRect: () => new DOMRect(props.position.alignRight ? props.position.x - MENU_WIDTH : props.position.x, props.position.y, MENU_WIDTH, 0) } : null));

function run(entry) {
    emit('close');
    entry.action();
}
</script>

<template>
    <Popover :anchor="anchor" :open="position !== null" :width="MENU_WIDTH" :offset="2" :z-index="61" @close="emit('close')">
        <div class="py-1" role="menu" @contextmenu.prevent="emit('close')">
            <template v-for="(entry, index) in entries" :key="index">
                <div v-if="entry.divider" class="my-1 border-t border-gray-100 dark:border-white/10"></div>
                <MenuItem v-else role="menuitem" :tone="entry.tone ?? null" :disabled="entry.disabled ?? false" @click="run(entry)">
                    <template #icon>
                        <span class="flex h-4 w-4 shrink-0 items-center justify-center">
                            <Icon v-if="entry.icon" :name="entry.icon" class="h-4 w-4" />
                            <span v-else-if="entry.checked" class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        </span>
                    </template>
                    <span class="flex-1">{{ entry.label }}</span>
                    <kbd v-if="entry.shortcut" class="font-sans text-xs text-gray-400 dark:text-gray-500">{{ entry.shortcut }}</kbd>
                </MenuItem>
            </template>
        </div>
    </Popover>
</template>
