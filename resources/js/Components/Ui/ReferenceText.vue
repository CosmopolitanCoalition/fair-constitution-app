<script>
import { useI18n } from 'vue-i18n';
import { referenceTextNode } from '@/lib/referenceTextNodes.js';

// Transform rendered text nodes only. IDs in attributes, links, controls, and
// submitted data must remain exact. No HTML parsing or DOM rewriting is used.
export default {
    setup(_props, { slots }) {
        const { t } = useI18n();
        const translate = (key, fallback) => t(key, fallback);
        return () => (slots.default?.() ?? []).map((node) => referenceTextNode(node, translate));
    },
};
</script>
