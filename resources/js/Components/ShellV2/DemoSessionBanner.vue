<script setup>
/**
 * ShellV2/DemoSessionBanner — the scale_demo session notice (DemoMode ruling C).
 *
 * On a scale_demo instance every action a visitor takes is a real write for
 * the length of their session. DemoSessionService::void reverses those writes
 * at sign-out or when the session expires. This banner states that plainly so
 * a visitor knows their changes are session-scoped, not permanent.
 *
 * It shows ONLY when the shell reports a demo world (shellInstance.demo, set
 * by InstanceClass::isScaleDemo). A non-demo instance renders nothing. A
 * visitor may dismiss it; the dismissal is remembered per browser and never
 * leaves the device.
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Banner from '@/Components/Ui/Banner.vue';

const STORE_KEY = 'cga.demo.sessionBannerDismissed';

const { t } = useI18n({ useScope: 'global' });
const page = usePage();

const instance = computed(() => page.props.shellInstance ?? page.props.instance ?? {});
const isDemoWorld = computed(() => instance.value.demo === true);

function readDismissed() {
    try {
        return globalThis.localStorage?.getItem(STORE_KEY) === '1';
    } catch (e) {
        return false;
    }
}

const dismissed = ref(readDismissed());

function dismiss() {
    dismissed.value = true;
    try {
        globalThis.localStorage?.setItem(STORE_KEY, '1');
    } catch (e) {
        // Private mode or blocked storage. The banner still hides for this view.
    }
}

const visible = computed(() => isDemoWorld.value && ! dismissed.value);

defineExpose({ visible, isDemoWorld, dismiss });
</script>

<template>
    <Banner
        v-if="visible"
        tone="demo"
        :title="t('demo.session_notice_title', 'Demo world')"
    >
        {{ t('demo.session_notice_body', 'Every change you make here is real for this session only. It is reversed when you sign out or when the session ends.') }}
        <button type="button" class="demo-session-dismiss" @click="dismiss">
            {{ t('demo.session_notice_dismiss', 'Dismiss') }}
        </button>
    </Banner>
</template>

<style scoped>
.demo-session-dismiss {
    margin-inline-start: var(--space-2);
    min-height: 44px;
    padding-inline: var(--space-3);
    background: transparent;
    border: 1px solid currentColor;
    border-radius: var(--radius-sm, 4px);
    color: inherit;
    font: inherit;
    cursor: pointer;
}
</style>
