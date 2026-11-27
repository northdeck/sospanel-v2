<script setup lang="ts">
/**
 * The Northdeck wordmark. logo-on-light.png has ink letters for light
 * backgrounds; logo-on-dark.png has white letters for dark ones. The panel
 * puts .dark on <html>, so CSS picks the right one.
 */
defineProps<{ height?: number }>();
</script>

<template>
    <span
        class="inline-flex items-center"
        :style="{ height: `${height ?? 20}px` }"
    >
        <img
            src="/northdeck/logo-on-light.png"
            alt="Northdeck"
            class="h-full w-auto dark:hidden"
        />
        <img
            src="/northdeck/logo-on-dark.png"
            alt="Northdeck"
            class="hidden h-full w-auto dark:block"
        />
    </span>
</template>
