<script setup>
import { FieldCaption } from '@aaix/laravel-islands/vue/helpers';

defineProps({
    label: { type: String, required: true },
    error: { type: String, default: '' },
});
</script>

<template>
    <label class="block space-y-1">
        <FieldCaption class="block">{{ label }}</FieldCaption>
        <slot />
        <span v-if="error" class="block text-xs text-red-600 dark:text-red-400">{{ error }}</span>
    </label>
</template>
