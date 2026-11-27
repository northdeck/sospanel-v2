<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- NORTHDECK: Northdeck icon instead of the upstream mark -->
    <img
        src="/northdeck/icon-64.png"
        alt=""
        :class="className"
        v-bind="$attrs"
    />
</template>
