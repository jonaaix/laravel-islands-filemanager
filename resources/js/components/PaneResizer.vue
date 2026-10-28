<script setup>
import { ref, toRef } from 'vue';

const props = defineProps({
    modelValue: { type: Number, required: true },
    min: { type: Number, required: true },
    max: { type: Number, required: true },
    reset: { type: Number, required: true },
    label: { type: String, required: true },
    edge: { type: String, default: 'left' },
});

const emit = defineEmits(['update:modelValue', 'commit']);
const STEP = 16;
const edge = toRef(props, 'edge');
const dragging = ref(false);
let startX = 0;
let startWidth = 0;

const clamp = (width) => Math.round(Math.min(props.max, Math.max(props.min, width)));

function start(event) {
    dragging.value = true;
    startX = event.clientX;
    startWidth = props.modelValue;
    event.target.setPointerCapture(event.pointerId);
}

function move(event) {
    if (dragging.value) {
        const delta = event.clientX - startX;
        emit('update:modelValue', clamp(edge.value === 'left' ? startWidth - delta : startWidth + delta));
    }
}

function end() {
    if (dragging.value) {
        dragging.value = false;
        emit('commit');
    }
}

function nudge(event) {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    const grows = (event.key === 'ArrowLeft') === (edge.value === 'left');
    emit('update:modelValue', clamp(props.modelValue + (grows ? STEP : -STEP)));
    emit('commit');
}

function restore() {
    emit('update:modelValue', props.reset);
    emit('commit');
}
</script>

<template>
    <div
        role="separator"
        aria-orientation="vertical"
        tabindex="0"
        :aria-label="label"
        :aria-valuenow="modelValue"
        :aria-valuemin="min"
        :aria-valuemax="max"
        :class="edge === 'left' ? '-left-1' : '-right-1'"
        class="group absolute inset-y-0 z-10 w-2 cursor-col-resize touch-none outline-none"
        @pointerdown.prevent="start"
        @pointermove="move"
        @pointerup="end"
        @pointercancel="end"
        @dblclick="restore"
        @keydown="nudge"
        @click.stop
    >
        <div class="mx-auto h-full w-0.5 transition-colors duration-150" :class="dragging ? 'bg-primary-500' : 'bg-transparent group-hover:bg-primary-500/60 group-focus-visible:bg-primary-500'"></div>
    </div>
</template>
