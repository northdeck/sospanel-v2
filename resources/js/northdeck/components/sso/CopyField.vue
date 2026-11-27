<script setup lang="ts">
import { Check } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { toast } from '@/lib/toast';

const props = defineProps<{ label: string; value: string }>();

const copied = ref(false);
async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        toast.error(trans('Couldn’t copy; select it and copy by hand.'));
    }
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <span class="text-[12px]/4 text-(--ds-text-muted)">{{ label }}</span>
        <div class="relative">
            <pre
                class="overflow-x-auto rounded-lg border border-(--ds-border) bg-(--ds-field) py-2 pr-11 pl-3 font-ui-mono text-[12px]/5"
                >{{ value }}</pre>
            <button
                type="button"
                class="absolute top-1 right-1 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                :aria-label="$t('Copy :label', { label })"
                @click="copy"
            >
                <component :is="copied ? Check : Copy" class="size-3.5" />
            </button>
        </div>
    </div>
</template>
