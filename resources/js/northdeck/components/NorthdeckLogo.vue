<script setup lang="ts">
/**
 * The Northdeck wordmark. logo-on-light.png has ink letters for light
 * backgrounds; logo-on-dark.png has white letters for dark ones. The panel
 * puts .dark on <html>, so CSS picks the right one. `on` forces one, for
 * pages whose background doesn't follow the theme.
 */
defineProps<{ height?: number; on?: 'light' | 'dark' }>();
</script>

<template>
    <span
        class="inline-flex items-center"
        :style="{ height: `${height ?? 20}px` }"
    >
        <img
            v-if="on !== 'dark'"
            src="/northdeck/logo-on-light.png"
            alt="Northdeck"
            class="h-full w-auto"
            :class="{ 'dark:hidden': on !== 'light' }"
        />
        <img
            v-if="on !== 'light'"
            src="/northdeck/logo-on-dark.png"
            alt="Northdeck"
            class="h-full w-auto"
            :class="{ 'hidden dark:block': on !== 'dark' }"
        />
    </span>
</template>
