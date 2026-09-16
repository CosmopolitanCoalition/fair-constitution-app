/* ============================================================================
   GENERATED FILE — DO NOT EDIT
   Written by scripts/i18n/languages.py. Edit that and re-run:
       python3 scripts/i18n/languages.py --write

   The JS half of THE locale registry, plus the CLDR plural selectors vue-i18n
   needs. vue-i18n's built-in selector resolves at most three forms — Arabic
   has six — so without these, an Arabic plural silently renders the wrong
   branch while a form-counting check reports it as correct.
   ============================================================================ */

export const LOCALES = [
    { code: "af", name: "Afrikaans", endonym: "Afrikaans", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "am", name: "Amharic", endonym: "\u12a0\u121b\u122d\u129b", dir: "ltr", script: "Ethi", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ar", name: "Arabic", endonym: "\u0627\u0644\u0639\u0631\u0628\u064a\u0629", dir: "rtl", script: "Arab", pluralFamily: "arabic", tier: 1, translated: true, enabled: true, target: true },
    { code: "az", name: "Azerbaijani", endonym: "Az\u0259rbaycan", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "bg", name: "Bulgarian", endonym: "\u0411\u044a\u043b\u0433\u0430\u0440\u0441\u043a\u0438", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "bn", name: "Bengali", endonym: "\u09ac\u09be\u0982\u09b2\u09be", dir: "ltr", script: "Beng", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "bs", name: "Bosnian", endonym: "Bosanski", dir: "ltr", script: "Latn", pluralFamily: "slavic", tier: 2, translated: true, enabled: true, target: true },
    { code: "ca", name: "Catalan", endonym: "Catal\u00e0", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "cs", name: "Czech", endonym: "\u010ce\u0161tina", dir: "ltr", script: "Latn", pluralFamily: "czech", tier: 2, translated: true, enabled: true, target: true },
    { code: "cy", name: "Welsh", endonym: "Cymraeg", dir: "ltr", script: "Latn", pluralFamily: "welsh", tier: 2, translated: true, enabled: true, target: true },
    { code: "da", name: "Danish", endonym: "Dansk", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "de", name: "German", endonym: "Deutsch", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "el", name: "Greek", endonym: "\u0395\u03bb\u03bb\u03b7\u03bd\u03b9\u03ba\u03ac", dir: "ltr", script: "Grek", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "en", name: "English", endonym: "English", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 1, translated: true, enabled: true, target: true },
    { code: "es", name: "Spanish", endonym: "Espa\u00f1ol", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 1, translated: true, enabled: true, target: true },
    { code: "et", name: "Estonian", endonym: "Eesti", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "eu", name: "Basque", endonym: "Euskara", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "fa", name: "Persian", endonym: "\u0641\u0627\u0631\u0633\u06cc", dir: "rtl", script: "Arab", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "fi", name: "Finnish", endonym: "Suomi", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "fil", name: "Filipino", endonym: "Filipino", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "fr", name: "French", endonym: "Fran\u00e7ais", dir: "ltr", script: "Latn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ga", name: "Irish", endonym: "Gaeilge", dir: "ltr", script: "Latn", pluralFamily: "irish", tier: 2, translated: true, enabled: true, target: true },
    { code: "gl", name: "Galician", endonym: "Galego", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "gu", name: "Gujarati", endonym: "\u0a97\u0ac1\u0a9c\u0ab0\u0abe\u0aa4\u0ac0", dir: "ltr", script: "Gujr", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "he", name: "Hebrew", endonym: "\u05e2\u05d1\u05e8\u05d9\u05ea", dir: "rtl", script: "Hebr", pluralFamily: "one_two_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "hi", name: "Hindi", endonym: "\u0939\u093f\u0928\u094d\u0926\u0940", dir: "ltr", script: "Deva", pluralFamily: "zero_one_other", tier: 1, translated: true, enabled: true, target: true },
    { code: "hr", name: "Croatian", endonym: "Hrvatski", dir: "ltr", script: "Latn", pluralFamily: "slavic", tier: 2, translated: true, enabled: true, target: true },
    { code: "hu", name: "Hungarian", endonym: "Magyar", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "hy", name: "Armenian", endonym: "\u0540\u0561\u0575\u0565\u0580\u0565\u0576", dir: "ltr", script: "Armn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "id", name: "Indonesian", endonym: "Bahasa Indonesia", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "is", name: "Icelandic", endonym: "\u00cdslenska", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "it", name: "Italian", endonym: "Italiano", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ja", name: "Japanese", endonym: "\u65e5\u672c\u8a9e", dir: "ltr", script: "Jpan", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "jv", name: "Javanese", endonym: "Basa Jawa", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ka", name: "Georgian", endonym: "\u10e5\u10d0\u10e0\u10d7\u10e3\u10da\u10d8", dir: "ltr", script: "Geor", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "kk", name: "Kazakh", endonym: "\u049a\u0430\u0437\u0430\u049b\u0448\u0430", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "km", name: "Khmer", endonym: "\u1797\u17b6\u179f\u17b6\u1781\u17d2\u1798\u17c2\u179a", dir: "ltr", script: "Khmr", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "kn", name: "Kannada", endonym: "\u0c95\u0ca8\u0ccd\u0ca8\u0ca1", dir: "ltr", script: "Knda", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ko", name: "Korean", endonym: "\ud55c\uad6d\uc5b4", dir: "ltr", script: "Kore", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "lo", name: "Lao", endonym: "\u0ea5\u0eb2\u0ea7", dir: "ltr", script: "Laoo", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "lt", name: "Lithuanian", endonym: "Lietuvi\u0173", dir: "ltr", script: "Latn", pluralFamily: "lithuanian", tier: 2, translated: true, enabled: true, target: true },
    { code: "lv", name: "Latvian", endonym: "Latvie\u0161u", dir: "ltr", script: "Latn", pluralFamily: "latvian", tier: 2, translated: true, enabled: true, target: true },
    { code: "mk", name: "Macedonian", endonym: "\u041c\u0430\u043a\u0435\u0434\u043e\u043d\u0441\u043a\u0438", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ml", name: "Malayalam", endonym: "\u0d2e\u0d32\u0d2f\u0d3e\u0d33\u0d02", dir: "ltr", script: "Mlym", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "mn", name: "Mongolian", endonym: "\u041c\u043e\u043d\u0433\u043e\u043b", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "mr", name: "Marathi", endonym: "\u092e\u0930\u093e\u0920\u0940", dir: "ltr", script: "Deva", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ms", name: "Malay", endonym: "Bahasa Melayu", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "mt", name: "Maltese", endonym: "Malti", dir: "ltr", script: "Latn", pluralFamily: "maltese", tier: 2, translated: true, enabled: true, target: true },
    { code: "my", name: "Burmese", endonym: "\u1019\u103c\u1014\u103a\u1019\u102c", dir: "ltr", script: "Mymr", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ne", name: "Nepali", endonym: "\u0928\u0947\u092a\u093e\u0932\u0940", dir: "ltr", script: "Deva", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "nl", name: "Dutch", endonym: "Nederlands", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "no", name: "Norwegian", endonym: "Norsk", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "pl", name: "Polish", endonym: "Polski", dir: "ltr", script: "Latn", pluralFamily: "polish", tier: 2, translated: true, enabled: true, target: true },
    { code: "ps", name: "Pashto", endonym: "\u067e\u069a\u062a\u0648", dir: "rtl", script: "Arab", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "pt", name: "Portuguese", endonym: "Portugu\u00eas", dir: "ltr", script: "Latn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ro", name: "Romanian", endonym: "Rom\u00e2n\u0103", dir: "ltr", script: "Latn", pluralFamily: "romanian", tier: 2, translated: true, enabled: true, target: true },
    { code: "ru", name: "Russian", endonym: "\u0420\u0443\u0441\u0441\u043a\u0438\u0439", dir: "ltr", script: "Cyrl", pluralFamily: "slavic", tier: 2, translated: true, enabled: true, target: true },
    { code: "si", name: "Sinhala", endonym: "\u0dc3\u0dd2\u0d82\u0dc4\u0dbd", dir: "ltr", script: "Sinh", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "sk", name: "Slovak", endonym: "Sloven\u010dina", dir: "ltr", script: "Latn", pluralFamily: "czech", tier: 2, translated: true, enabled: true, target: true },
    { code: "sl", name: "Slovenian", endonym: "Sloven\u0161\u010dina", dir: "ltr", script: "Latn", pluralFamily: "slavic", tier: 2, translated: true, enabled: true, target: true },
    { code: "so", name: "Somali", endonym: "Soomaali", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "sq", name: "Albanian", endonym: "Shqip", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "sr", name: "Serbian", endonym: "\u0421\u0440\u043f\u0441\u043a\u0438", dir: "ltr", script: "Cyrl", pluralFamily: "slavic", tier: 2, translated: true, enabled: true, target: true },
    { code: "su", name: "Sundanese", endonym: "Basa Sunda", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "sv", name: "Swedish", endonym: "Svenska", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "sw", name: "Swahili", endonym: "Kiswahili", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "ta", name: "Tamil", endonym: "\u0ba4\u0bae\u0bbf\u0bb4\u0bcd", dir: "ltr", script: "Taml", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "te", name: "Telugu", endonym: "\u0c24\u0c46\u0c32\u0c41\u0c17\u0c41", dir: "ltr", script: "Telu", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "th", name: "Thai", endonym: "\u0e44\u0e17\u0e22", dir: "ltr", script: "Thai", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "tr", name: "Turkish", endonym: "T\u00fcrk\u00e7e", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "uk", name: "Ukrainian", endonym: "\u0423\u043a\u0440\u0430\u0457\u043d\u0441\u044c\u043a\u0430", dir: "ltr", script: "Cyrl", pluralFamily: "slavic", tier: 2, translated: true, enabled: true, target: true },
    { code: "ur", name: "Urdu", endonym: "\u0627\u0631\u062f\u0648", dir: "rtl", script: "Arab", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "uz", name: "Uzbek", endonym: "O\u02bbzbek", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: true, target: true },
    { code: "vi", name: "Vietnamese", endonym: "Ti\u1ebfng Vi\u1ec7t", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: true, target: true },
    { code: "zh-Hans", name: "Chinese (Simplified)", endonym: "\u4e2d\u6587\uff08\u7b80\u4f53\uff09", dir: "ltr", script: "Hans", pluralFamily: "other", tier: 1, translated: true, enabled: true, target: true },
    { code: "zu", name: "Zulu", endonym: "isiZulu", dir: "ltr", script: "Latn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: true, target: true },
];

const RULES = {
    other: () => 0,

    one_other: (n) => (n === 1 ? 0 : 1),

    /* 0 and 1 both take the singular (fr, pt, hi, bn, fa, am, …) */
    zero_one_other: (n) => (n === 0 || n === 1 ? 0 : 1),

    one_two_other: (n) => (n === 1 ? 0 : n === 2 ? 1 : 2),

    /* ru/uk/be/sr/hr/bs/sl */
    slavic: (n) => {
        const m10 = n % 10;
        const m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return 0;
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 1;
        return 2;
    },

    polish: (n) => {
        const m10 = n % 10;
        const m100 = n % 100;
        if (n === 1) return 0;
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 1;
        return 2;
    },

    czech: (n) => (n === 1 ? 0 : n >= 2 && n <= 4 ? 1 : 2),

    lithuanian: (n) => {
        const m10 = n % 10;
        const m100 = n % 100;
        if (m10 === 1 && (m100 < 11 || m100 > 19)) return 0;
        if (m10 >= 2 && m10 <= 9 && (m100 < 11 || m100 > 19)) return 1;
        return 2;
    },

    latvian: (n) => (n % 10 === 0 || (n % 100 >= 11 && n % 100 <= 19) ? 0 : n % 10 === 1 && n % 100 !== 11 ? 1 : 2),

    romanian: (n) => (n === 1 ? 0 : n === 0 || (n % 100 >= 1 && n % 100 <= 19) ? 1 : 2),

    maltese: (n) => {
        const m100 = n % 100;
        if (n === 1) return 0;
        if (n === 0 || (m100 >= 2 && m100 <= 10)) return 1;
        if (m100 >= 11 && m100 <= 19) return 2;
        return 3;
    },

    irish: (n) => (n === 1 ? 0 : n === 2 ? 1 : n >= 3 && n <= 6 ? 2 : n >= 7 && n <= 10 ? 3 : 4),

    /* Welsh: zero | one | two | few | many | other. */
    welsh: (n) => (n === 0 ? 0 : n === 1 ? 1 : n === 2 ? 2 : n === 3 ? 3 : n === 6 ? 4 : 5),

    /* Arabic: zero | one | two | few | many | other — six, not three. */
    arabic: (n) => {
        const m100 = n % 100;
        if (n === 0) return 0;
        if (n === 1) return 1;
        if (n === 2) return 2;
        if (m100 >= 3 && m100 <= 10) return 3;
        if (m100 >= 11 && m100 <= 99) return 4;
        return 5;
    },
};

/**
 * vue-i18n pluralRules map. Clamped to the number of forms the message
 * actually supplies, so a message written with fewer branches than its locale
 * has categories degrades to the last one instead of rendering nothing.
 */
export const pluralRules = Object.fromEntries(
    LOCALES.map((l) => [
        l.code,
        (choice, choicesLength) => {
            const idx = RULES[l.pluralFamily](Math.abs(Number(choice)));
            return Math.min(idx, Math.max(choicesLength - 1, 0));
        },
    ]),
);

/** Codes with a catalog today. */
export const ENABLED = LOCALES.filter((l) => l.enabled).map((l) => l.code);

/** Codes we intend to carry a full catalog for. */
export const TRANSLATED = LOCALES.filter((l) => l.translated).map((l) => l.code);

/** Codes in the translation pass (operator order 2026-09-14): the UN six,
 *  Polish, Italian, Turkish and the Coalition website's programme languages. */
export const TARGETS = LOCALES.filter((l) => l.target).map((l) => l.code);

/** RTL codes — the ONLY source for direction. */
export const RTL = LOCALES.filter((l) => l.dir === 'rtl').map((l) => l.code);
