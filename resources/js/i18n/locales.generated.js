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
    { code: "af", name: "Afrikaans", endonym: "Afrikaans", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "am", name: "Amharic", endonym: "\u12a0\u121b\u122d\u129b", dir: "ltr", script: "Ethi", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "ar", name: "Arabic", endonym: "\u0627\u0644\u0639\u0631\u0628\u064a\u0629", dir: "rtl", script: "Arab", pluralFamily: "arabic", tier: 1, translated: true, enabled: true },
    { code: "ay", name: "Aymara", endonym: "Aymara", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "az", name: "Azerbaijani", endonym: "Az\u0259rbaycan", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "be", name: "Belarusian", endonym: "\u0411\u0435\u043b\u0430\u0440\u0443\u0441\u043a\u0430\u044f", dir: "ltr", script: "Cyrl", pluralFamily: "slavic", tier: 2, translated: true, enabled: false },
    { code: "ber", name: "Berber", endonym: "Berber", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "bg", name: "Bulgarian", endonym: "\u0411\u044a\u043b\u0433\u0430\u0440\u0441\u043a\u0438", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "bi", name: "Bislama", endonym: "Bislama", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "bn", name: "Bengali", endonym: "\u09ac\u09be\u0982\u09b2\u09be", dir: "ltr", script: "Beng", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "bs", name: "Bosnian", endonym: "Bosanski", dir: "ltr", script: "Latn", pluralFamily: "slavic", tier: 2, translated: true, enabled: false },
    { code: "ca", name: "Catalan", endonym: "Catal\u00e0", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "ch", name: "Chamorro", endonym: "Chamorro", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "cs", name: "Czech", endonym: "\u010ce\u0161tina", dir: "ltr", script: "Latn", pluralFamily: "czech", tier: 2, translated: true, enabled: false },
    { code: "da", name: "Danish", endonym: "Dansk", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "de", name: "German", endonym: "Deutsch", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "dv", name: "Dhivehi", endonym: "\u078b\u07a8\u0788\u07ac\u0780\u07a8", dir: "rtl", script: "Thaa", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "dz", name: "Dzongkha", endonym: "\u0f62\u0fab\u0f7c\u0f44\u0f0b\u0f41", dir: "ltr", script: "Tibt", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "el", name: "Greek", endonym: "\u0395\u03bb\u03bb\u03b7\u03bd\u03b9\u03ba\u03ac", dir: "ltr", script: "Grek", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "en", name: "English", endonym: "English", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 1, translated: true, enabled: true },
    { code: "es", name: "Spanish", endonym: "Espa\u00f1ol", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 1, translated: true, enabled: true },
    { code: "et", name: "Estonian", endonym: "Eesti", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "fa", name: "Persian", endonym: "\u0641\u0627\u0631\u0633\u06cc", dir: "rtl", script: "Arab", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "fi", name: "Finnish", endonym: "Suomi", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "fil", name: "Filipino", endonym: "Filipino", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "fj", name: "Fijian", endonym: "Fijian", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "fo", name: "Faroese", endonym: "F\u00f8royskt", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "fr", name: "French", endonym: "Fran\u00e7ais", dir: "ltr", script: "Latn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "ga", name: "Irish", endonym: "Gaeilge", dir: "ltr", script: "Latn", pluralFamily: "irish", tier: 2, translated: true, enabled: false },
    { code: "gn", name: "Guarani", endonym: "Guarani", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "gv", name: "Manx", endonym: "Manx", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "he", name: "Hebrew", endonym: "\u05e2\u05d1\u05e8\u05d9\u05ea", dir: "rtl", script: "Hebr", pluralFamily: "one_two_other", tier: 2, translated: true, enabled: false },
    { code: "hi", name: "Hindi", endonym: "\u0939\u093f\u0928\u094d\u0926\u0940", dir: "ltr", script: "Deva", pluralFamily: "zero_one_other", tier: 1, translated: true, enabled: true },
    { code: "ho", name: "Hiri Motu", endonym: "Hiri Motu", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "hr", name: "Croatian", endonym: "Hrvatski", dir: "ltr", script: "Latn", pluralFamily: "slavic", tier: 2, translated: true, enabled: false },
    { code: "ht", name: "Haitian Creole", endonym: "Krey\u00f2l ayisyen", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "hu", name: "Hungarian", endonym: "Magyar", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "hy", name: "Armenian", endonym: "\u0540\u0561\u0575\u0565\u0580\u0565\u0576", dir: "ltr", script: "Armn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "id", name: "Indonesian", endonym: "Bahasa Indonesia", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "is", name: "Icelandic", endonym: "\u00cdslenska", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "it", name: "Italian", endonym: "Italiano", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "ja", name: "Japanese", endonym: "\u65e5\u672c\u8a9e", dir: "ltr", script: "Jpan", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "ka", name: "Georgian", endonym: "\u10e5\u10d0\u10e0\u10d7\u10e3\u10da\u10d8", dir: "ltr", script: "Geor", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "kk", name: "Kazakh", endonym: "\u049a\u0430\u0437\u0430\u049b\u0448\u0430", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "kl", name: "Greenlandic", endonym: "Greenlandic", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "km", name: "Khmer", endonym: "\u1797\u17b6\u179f\u17b6\u1781\u17d2\u1798\u17c2\u179a", dir: "ltr", script: "Khmr", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "ko", name: "Korean", endonym: "\ud55c\uad6d\uc5b4", dir: "ltr", script: "Kore", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "ku", name: "Kurdish", endonym: "Kurd\u00ee", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "ky", name: "Kyrgyz", endonym: "\u041a\u044b\u0440\u0433\u044b\u0437\u0447\u0430", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "lb", name: "Luxembourgish", endonym: "L\u00ebtzebuergesch", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "lo", name: "Lao", endonym: "\u0ea5\u0eb2\u0ea7", dir: "ltr", script: "Laoo", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "lt", name: "Lithuanian", endonym: "Lietuvi\u0173", dir: "ltr", script: "Latn", pluralFamily: "lithuanian", tier: 2, translated: true, enabled: false },
    { code: "lv", name: "Latvian", endonym: "Latvie\u0161u", dir: "ltr", script: "Latn", pluralFamily: "latvian", tier: 2, translated: true, enabled: false },
    { code: "mg", name: "Malagasy", endonym: "Malagasy", dir: "ltr", script: "Latn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "mh", name: "Marshallese", endonym: "Marshallese", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "mi", name: "Maori", endonym: "Maori", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "mk", name: "Macedonian", endonym: "\u041c\u0430\u043a\u0435\u0434\u043e\u043d\u0441\u043a\u0438", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "mn", name: "Mongolian", endonym: "\u041c\u043e\u043d\u0433\u043e\u043b", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "ms", name: "Malay", endonym: "Bahasa Melayu", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "mt", name: "Maltese", endonym: "Malti", dir: "ltr", script: "Latn", pluralFamily: "maltese", tier: 2, translated: true, enabled: false },
    { code: "my", name: "Burmese", endonym: "\u1019\u103c\u1014\u103a\u1019\u102c", dir: "ltr", script: "Mymr", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "na", name: "Nauruan", endonym: "Nauruan", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "nd", name: "North Ndebele", endonym: "North Ndebele", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "ne", name: "Nepali", endonym: "\u0928\u0947\u092a\u093e\u0932\u0940", dir: "ltr", script: "Deva", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "nl", name: "Dutch", endonym: "Nederlands", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "no", name: "Norwegian", endonym: "Norsk", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "nr", name: "South Ndebele", endonym: "South Ndebele", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "ny", name: "Chichewa", endonym: "Chichewa", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "pap", name: "Papiamento", endonym: "Papiamento", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "pau", name: "Palauan", endonym: "Palauan", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "pl", name: "Polish", endonym: "Polski", dir: "ltr", script: "Latn", pluralFamily: "polish", tier: 2, translated: true, enabled: false },
    { code: "ps", name: "Pashto", endonym: "\u067e\u069a\u062a\u0648", dir: "rtl", script: "Arab", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "pt", name: "Portuguese", endonym: "Portugu\u00eas", dir: "ltr", script: "Latn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "qu", name: "Quechua", endonym: "Quechua", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "rm", name: "Romansh", endonym: "Romansh", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "rn", name: "Kirundi", endonym: "Kirundi", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "ro", name: "Romanian", endonym: "Rom\u00e2n\u0103", dir: "ltr", script: "Latn", pluralFamily: "romanian", tier: 2, translated: true, enabled: false },
    { code: "ru", name: "Russian", endonym: "\u0420\u0443\u0441\u0441\u043a\u0438\u0439", dir: "ltr", script: "Cyrl", pluralFamily: "slavic", tier: 2, translated: true, enabled: false },
    { code: "rw", name: "Kinyarwanda", endonym: "Ikinyarwanda", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "sg", name: "Sango", endonym: "Sango", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "si", name: "Sinhala", endonym: "\u0dc3\u0dd2\u0d82\u0dc4\u0dbd", dir: "ltr", script: "Sinh", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "sk", name: "Slovak", endonym: "Sloven\u010dina", dir: "ltr", script: "Latn", pluralFamily: "czech", tier: 2, translated: true, enabled: false },
    { code: "sl", name: "Slovenian", endonym: "Sloven\u0161\u010dina", dir: "ltr", script: "Latn", pluralFamily: "slavic", tier: 2, translated: true, enabled: false },
    { code: "sm", name: "Samoan", endonym: "Samoan", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "sn", name: "Shona", endonym: "chiShona", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "so", name: "Somali", endonym: "Soomaali", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "sq", name: "Albanian", endonym: "Shqip", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "sr", name: "Serbian", endonym: "\u0421\u0440\u043f\u0441\u043a\u0438", dir: "ltr", script: "Cyrl", pluralFamily: "slavic", tier: 2, translated: true, enabled: false },
    { code: "ss", name: "Swati", endonym: "Swati", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "st", name: "Southern Sotho", endonym: "Sesotho", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "sv", name: "Swedish", endonym: "Svenska", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "sw", name: "Swahili", endonym: "Kiswahili", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "ta", name: "Tamil", endonym: "\u0ba4\u0bae\u0bbf\u0bb4\u0bcd", dir: "ltr", script: "Taml", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "tet", name: "Tetum", endonym: "Tetum", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "tg", name: "Tajik", endonym: "\u0422\u043e\u04b7\u0438\u043a\u04e3", dir: "ltr", script: "Cyrl", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "th", name: "Thai", endonym: "\u0e44\u0e17\u0e22", dir: "ltr", script: "Thai", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "ti", name: "Tigrinya", endonym: "\u1275\u130d\u122d\u129b", dir: "ltr", script: "Ethi", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
    { code: "tk", name: "Turkmen", endonym: "T\u00fcrkmen", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "tn", name: "Tswana", endonym: "Tswana", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "to", name: "Tongan", endonym: "Tongan", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "tpi", name: "Tok Pisin", endonym: "Tok Pisin", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "tr", name: "Turkish", endonym: "T\u00fcrk\u00e7e", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "ts", name: "Tsonga", endonym: "Tsonga", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "uk", name: "Ukrainian", endonym: "\u0423\u043a\u0440\u0430\u0457\u043d\u0441\u044c\u043a\u0430", dir: "ltr", script: "Cyrl", pluralFamily: "slavic", tier: 2, translated: true, enabled: false },
    { code: "ur", name: "Urdu", endonym: "\u0627\u0631\u062f\u0648", dir: "rtl", script: "Arab", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "uz", name: "Uzbek", endonym: "O\u02bbzbek", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "ve", name: "Venda", endonym: "Venda", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 3, translated: false, enabled: false },
    { code: "vi", name: "Vietnamese", endonym: "Ti\u1ebfng Vi\u1ec7t", dir: "ltr", script: "Latn", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "xh", name: "Xhosa", endonym: "isiXhosa", dir: "ltr", script: "Latn", pluralFamily: "one_other", tier: 2, translated: true, enabled: false },
    { code: "zh-Hans", name: "Chinese (Simplified)", endonym: "\u4e2d\u6587\uff08\u7b80\u4f53\uff09", dir: "ltr", script: "Hans", pluralFamily: "other", tier: 1, translated: true, enabled: true },
    { code: "zh-Hant", name: "Chinese (Traditional)", endonym: "\u4e2d\u6587\uff08\u7e41\u9ad4\uff09", dir: "ltr", script: "Hant", pluralFamily: "other", tier: 2, translated: true, enabled: false },
    { code: "zu", name: "Zulu", endonym: "isiZulu", dir: "ltr", script: "Latn", pluralFamily: "zero_one_other", tier: 2, translated: true, enabled: false },
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

/** RTL codes — the ONLY source for direction. */
export const RTL = LOCALES.filter((l) => l.dir === 'rtl').map((l) => l.code);
