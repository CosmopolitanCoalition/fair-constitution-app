<?php

namespace App\Services\Demo;

/**
 * Deterministic synthetic PEOPLE — a name, a language, a locale, an occupation.
 *
 * Pure and seed-driven: `make()` with the same seed and index returns the same
 * person on any box, forever. That is what lets the identities stage be
 * re-handed after a worker dies without minting a different population, and
 * what makes `sim:revert` a DELETE plus a re-derivation rather than a restore.
 *
 * WHAT THIS IS NOT. The name corpus below is a plausible DEFAULT, not research.
 * It is deliberately built so the demo can be walked end to end before a penny
 * of LLM spend — the locality-research layer, when it lands, REPLACES these
 * pools per jurisdiction (a Welsh valley and a London borough both read `en`
 * here and should not) rather than being a prerequisite for them. Anywhere the
 * demo shows a name it must be able to say which of the two produced it, so
 * `persona_source` travels with the person.
 *
 * Names are drawn from the jurisdiction's OWN `official_languages`, which the
 * ETL has populated for 951,625 of 955,130 jurisdictions — so a place in Oaxaca
 * gets Spanish names and one in Alexandria gets Arabic ones without anybody
 * researching anything.
 */
final class PersonaFactory
{
    public const SOURCE_DEFAULT = 'default_corpus';

    public const SOURCE_RESEARCHED = 'researched_profile';

    /**
     * Given names per language. Modest on purpose — combined with family names
     * and the index this yields ample local variety, and repetition between
     * distant jurisdictions is realistic rather than a defect.
     *
     * @var array<string, list<string>>
     */
    private const GIVEN = [
        'en' => ['Alice', 'Marcus', 'Priya', 'Tomas', 'Ruth', 'Daniel', 'Nadia', 'Owen', 'Grace', 'Ibrahim', 'Clara', 'Victor', 'Esther', 'Leo', 'Maya', 'Samuel'],
        'es' => ['Lucía', 'Mateo', 'Valentina', 'Santiago', 'Camila', 'Diego', 'Isabela', 'Andrés', 'Rosa', 'Javier', 'Elena', 'Tomás', 'Ana', 'Rafael', 'Marta', 'Emilio'],
        'fr' => ['Camille', 'Étienne', 'Amélie', 'Julien', 'Sylvie', 'Marc', 'Océane', 'Hugo', 'Claire', 'Damien', 'Adèle', 'Rémi', 'Manon', 'Pascal', 'Noémie', 'Cédric'],
        'pt' => ['Beatriz', 'João', 'Mariana', 'Rafael', 'Sofia', 'Tiago', 'Inês', 'Bruno', 'Carla', 'Pedro', 'Helena', 'Miguel', 'Rita', 'André', 'Luísa', 'Nuno'],
        'de' => ['Lena', 'Jonas', 'Greta', 'Felix', 'Anja', 'Lukas', 'Heike', 'Stefan', 'Nora', 'Til', 'Ingrid', 'Kai', 'Sabine', 'Bernd', 'Frieda', 'Ulrich'],
        'it' => ['Giulia', 'Matteo', 'Chiara', 'Lorenzo', 'Elisa', 'Davide', 'Sara', 'Alessio', 'Marta', 'Nicola', 'Fabio', 'Irene', 'Paolo', 'Silvia', 'Enrico', 'Alba'],
        'ar' => ['ليلى', 'عمر', 'فاطمة', 'يوسف', 'مريم', 'حسن', 'نور', 'خالد', 'زينب', 'طارق', 'سلمى', 'أحمد', 'هدى', 'ياسين', 'رنا', 'كريم'],
        'ru' => ['Анна', 'Дмитрий', 'Ольга', 'Сергей', 'Ирина', 'Павел', 'Наталья', 'Игорь', 'Мария', 'Артём', 'Елена', 'Никита', 'Вера', 'Роман', 'Ксения', 'Борис'],
        'zh' => ['伟', '芳', '娜', '强', '敏', '磊', '静', '军', '洋', '燕', '涛', '丽', '斌', '霞', '鹏', '玲'],
        'hi' => ['आरव', 'दीया', 'विहान', 'अनन्या', 'कबीर', 'सान्वी', 'अर्जुन', 'मीरा', 'रोहन', 'ईशा', 'आदित्य', 'नेहा', 'कार्तिक', 'प्रिया', 'देव', 'रिया'],
        'bn' => ['রাহুল', 'তানিয়া', 'সাইফ', 'নুসরাত', 'আরিফ', 'মিতা', 'শুভ', 'ফারিয়া', 'রিয়াদ', 'অন্তরা', 'জাহিদ', 'সুমি', 'নাঈম', 'রুবি', 'তুহিন', 'শিরিন'],
        'sw' => ['Amani', 'Baraka', 'Zuri', 'Juma', 'Neema', 'Kito', 'Imani', 'Bakari', 'Asha', 'Jabari', 'Halima', 'Simba', 'Zawadi', 'Rafiki', 'Subira', 'Tumaini'],
        'id' => ['Putri', 'Bayu', 'Sari', 'Agus', 'Dewi', 'Eko', 'Ratna', 'Joko', 'Indah', 'Rizki', 'Wulan', 'Hendra', 'Ayu', 'Slamet', 'Rina', 'Yusuf'],
        'ja' => ['さくら', 'はると', 'ゆい', 'そうた', 'あおい', 'れん', 'ひなた', 'ゆうと', 'めい', 'かいと', 'りん', 'たくみ', 'みお', 'しょう', 'あかり', 'けんじ'],
    ];

    /** @var array<string, list<string>> */
    private const FAMILY = [
        'en' => ['Ashford', 'Bramley', 'Calder', 'Denham', 'Ellery', 'Fairbourne', 'Gale', 'Hollis', 'Ingram', 'Jessop', 'Kettering', 'Larkin'],
        'es' => ['Aguilar', 'Barrios', 'Cifuentes', 'Delgado', 'Escobar', 'Fuentes', 'Gallardo', 'Herrera', 'Ibarra', 'Jiménez', 'Lozano', 'Medina'],
        'fr' => ['Alarie', 'Beauchêne', 'Charpentier', 'Delacroix', 'Escoffier', 'Fontaine', 'Gauthier', 'Hérault', 'Imbert', 'Joubert', 'Lemoine', 'Marchand'],
        'pt' => ['Almeida', 'Barbosa', 'Carvalho', 'Dias', 'Esteves', 'Ferreira', 'Gonçalves', 'Henriques', 'Infante', 'Jordão', 'Lopes', 'Macedo'],
        'de' => ['Achenbach', 'Brenner', 'Clausen', 'Dietrich', 'Ebert', 'Fassbender', 'Gerhardt', 'Hoffmann', 'Ingelheim', 'Jäger', 'Köhler', 'Lindner'],
        'it' => ['Agostini', 'Bellini', 'Costa', 'De Luca', 'Esposito', 'Ferrari', 'Gallo', 'Iacobelli', 'Longo', 'Marchetti', 'Neri', 'Orlandi'],
        'ar' => ['الحسيني', 'المنصوري', 'الخالدي', 'الرشيد', 'السالم', 'الفارسي', 'الجابري', 'النعيمي', 'العمري', 'الشامي', 'البكري', 'الزهراني'],
        'ru' => ['Авдеев', 'Белов', 'Волков', 'Гаврилов', 'Дорофеев', 'Ершов', 'Жуков', 'Зайцев', 'Ильин', 'Ковалёв', 'Лебедев', 'Морозов'],
        'zh' => ['王', '李', '张', '刘', '陈', '杨', '黄', '赵', '周', '吴', '徐', '孙'],
        'hi' => ['अग्रवाल', 'बनर्जी', 'चौहान', 'देसाई', 'गुप्ता', 'जोशी', 'कपूर', 'मेहता', 'नायर', 'पटेल', 'राव', 'शर्मा'],
        'bn' => ['আহমেদ', 'বিশ্বাস', 'চৌধুরী', 'দাস', 'গোস্বামী', 'হক', 'ইসলাম', 'খান', 'মণ্ডল', 'রহমান', 'সরকার', 'সেন'],
        'sw' => ['Abdalla', 'Chege', 'Kamau', 'Kimani', 'Mwangi', 'Njoroge', 'Ochieng', 'Odhiambo', 'Omondi', 'Wanjiru', 'Mutiso', 'Kilonzo'],
        'id' => ['Anggraini', 'Budiman', 'Cahyono', 'Halim', 'Kusuma', 'Lestari', 'Nugroho', 'Pratama', 'Rahayu', 'Santoso', 'Utami', 'Wijaya'],
        'ja' => ['青木', '井上', '遠藤', '小林', '斎藤', '佐藤', '田中', '中村', '橋本', '松本', '山田', '渡辺'],
    ];

    /**
     * Occupations by urbanicity. Coarse on purpose — the research layer is what
     * makes a fishing village fish and a mining town mine.
     *
     * @var array<string, list<string>>
     */
    private const OCCUPATIONS = [
        'metropolis' => ['transit operator', 'nurse', 'software engineer', 'teacher', 'chef', 'accountant', 'electrician', 'archivist', 'paramedic', 'logistics planner'],
        'urban' => ['teacher', 'shopkeeper', 'plumber', 'pharmacist', 'bus driver', 'clerk', 'baker', 'mechanic', 'librarian', 'carpenter'],
        'town' => ['farmer', 'teacher', 'grocer', 'builder', 'nurse', 'mechanic', 'postal worker', 'brewer', 'joiner', 'shepherd'],
        'rural' => ['farmer', 'herder', 'fisher', 'miller', 'forester', 'smallholder', 'beekeeper', 'weaver', 'stonemason', 'midwife'],
        'unpopulated' => ['caretaker'],
    ];

    private function __construct() {}

    /**
     * One deterministic person.
     *
     * @param  list<string>  $languages  the jurisdiction's official languages
     * @return array{name: string, display_name: string, locale: string, languages: list<string>, occupation: string, persona_source: string}
     */
    public static function make(string $seed, array $languages, string $urbanicity, int $index): array
    {
        $rng = new HashChainRandom($seed.':person:'.$index);

        $lang = self::resolveLanguage($languages, $rng);

        $given = self::GIVEN[$lang] ?? self::GIVEN['en'];
        $family = self::FAMILY[$lang] ?? self::FAMILY['en'];
        $jobs = self::OCCUPATIONS[$urbanicity] ?? self::OCCUPATIONS['town'];

        $name = $given[$rng->between(0, count($given) - 1)]
            .' '
            .$family[$rng->between(0, count($family) - 1)];

        return [
            'name' => $name,
            'display_name' => $name,
            'locale' => $lang,
            'languages' => [$lang],
            'occupation' => $jobs[$rng->between(0, count($jobs) - 1)],
            // Every synthetic person can say where their persona came from, so
            // a researched population and a defaulted one are never confused.
            'persona_source' => self::SOURCE_DEFAULT,
        ];
    }

    /**
     * Pick one of the jurisdiction's languages. The FIRST is weighted heavily —
     * an official-language list is ordered by prominence, not equality, and a
     * uniform draw would make a bilingual country look 50/50 when it is not.
     *
     * @param  list<string>  $languages
     */
    private static function resolveLanguage(array $languages, HashChainRandom $rng): string
    {
        $known = array_values(array_filter(
            $languages,
            static fn ($l) => is_string($l) && isset(self::GIVEN[$l])
        ));

        if ($known === []) {
            return 'en';
        }

        if (count($known) === 1) {
            return $known[0];
        }

        // ~70% the primary language, the remainder spread over the rest.
        return $rng->between(1, 100) <= 70
            ? $known[0]
            : $known[$rng->between(1, count($known) - 1)];
    }

    /** Reserved synthetic-identity namespace — never a real, reachable address. */
    public static function email(string $seed, int $index): string
    {
        return 'sim-'.substr(hash('sha256', $seed.':'.$index), 0, 20).'@demo.invalid';
    }
}
