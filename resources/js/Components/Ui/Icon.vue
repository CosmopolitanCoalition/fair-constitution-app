<script setup>
/**
 * Ui/Icon — the app's single icon entry point (DESIGN_frontend_port.md §A5).
 *
 * Wraps lucide-vue-next behind the mockups' 38-name kebab vocabulary
 * (mockups/assets/js/icons.js) — that vocabulary is the app contract;
 * lucide is an implementation detail. Unknown names fall back to `info`
 * (mirrors shell.js behavior).
 *
 * - `label` set   → role="img" + aria-label (meaningful icon)
 * - `label` unset → aria-hidden (decorative icon)
 * - Directional glyphs get .icon--directional so [dir="rtl"] flips them
 *   (never mirror search/settings/logos/clocks).
 */
import { computed } from 'vue';
import {
    ArrowDown,
    ArrowRight,
    ArrowUp,
    Award,
    Bell,
    BookOpen,
    Briefcase,
    Building,
    ChartNoAxesColumnIncreasing,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Copy,
    ExternalLink,
    FileText,
    Flag,
    Globe,
    GraduationCap,
    GripVertical,
    Home,
    Info,
    Landmark,
    Languages,
    ListChecks,
    Lock,
    Map,
    MapPin,
    Menu,
    MessageSquare,
    Minus,
    Play,
    Plus,
    RefreshCw,
    Scale,
    Search,
    Shield,
    SlidersHorizontal,
    TriangleAlert,
    User,
    Users,
    Vote,
    X,
} from 'lucide-vue-next';

/* The 38-name vocabulary from mockups/assets/js/icons.js, mapped to lucide. */
const ICONS = {
    'home': Home,
    'search': Search,
    'bell': Bell,
    'check': Check,
    'x': X,
    'plus': Plus,
    'minus': Minus,
    'info': Info,
    'alert-triangle': TriangleAlert,
    'chevron-down': ChevronDown,
    'chevron-right': ChevronRight,
    'chevron-left': ChevronLeft,
    'arrow-right': ArrowRight,
    'arrow-up': ArrowUp,
    'arrow-down': ArrowDown,
    'external-link': ExternalLink,
    'menu': Menu,
    'grip-vertical': GripVertical,
    'globe': Globe,
    'lock': Lock,
    'clock': Clock,
    /* FE-B1 addition: BallotReceipt's "Copy receipt" button
       (PHASE_B_DESIGN_frontend.md §A.5 names icon=copy). */
    'copy': Copy,
    'map-pin': MapPin,
    'map': Map,
    'users': Users,
    'user': User,
    'file-text': FileText,
    'scale': Scale,
    'landmark': Landmark,
    'building': Building,
    'briefcase': Briefcase,
    'shield': Shield,
    'vote': Vote,
    'languages': Languages,
    'sliders': SlidersHorizontal,
    'book-open': BookOpen,
    'award': Award,
    'refresh-cw': RefreshCw,
    'bar-chart': ChartNoAxesColumnIncreasing,
    /* v2-shell additions (mockups/v3 icons.js vocabulary — Phase 1 port). */
    'message-square': MessageSquare,
    'graduation-cap': GraduationCap,
    'list-checks': ListChecks,
    'flag': Flag,
    'play': Play,
};

/* Icons whose geometry encodes reading direction — flipped under [dir="rtl"]
   via .icon--directional (frozen list from icons.js). */
const DIRECTIONAL = new Set([
    'chevron-right',
    'chevron-left',
    'arrow-right',
    'external-link',
    'book-open',
]);

const props = defineProps({
    /** Kebab-case name from the icons.js vocabulary. Unknown → info. */
    name: { type: String, required: true },
    size: {
        type: String,
        default: 'base',
        validator: (v) => ['sm', 'base'].includes(v),
    },
    /** Accessible name. Set → role="img"; unset → decorative (aria-hidden). */
    label: { type: String, default: null },
});

const glyph = computed(() => ICONS[props.name] ?? Info);
const directional = computed(() => DIRECTIONAL.has(props.name));
</script>

<template>
    <component
        :is="glyph"
        class="icon"
        :class="{ 'icon--sm': size === 'sm', 'icon--directional': directional }"
        :role="label ? 'img' : undefined"
        :aria-label="label || undefined"
        :aria-hidden="label ? undefined : 'true'"
    />
</template>
