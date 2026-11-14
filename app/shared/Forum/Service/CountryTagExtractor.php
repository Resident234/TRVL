<?php

declare(strict_types=1);

namespace app\shared\Forum\Service;

/**
 * The only tag rule of the forum pass: a forum title that names a country
 * gets that country as a tag. The name is looked up in a dictionary, so
 * regions ("ЕВРОПА форум") and ordinary words never become country tags.
 *
 * The rule reads the label of the title — the part before the dash or the
 * colon that separates it from the description — because the description
 * may mention a dozen countries the forum is not about.
 */
final class CountryTagExtractor
{
    /**
     * Canonical tag names. A canonical name is already lower case and is
     * stored in the tag table as it stands here.
     */
    private const COUNTRIES = [
        'австралия', 'австрия', 'азербайджан', 'албания', 'алжир', 'ангола',
        'андорра', 'аргентина', 'армения', 'афганистан', 'багамы', 'бангладеш',
        'барбадос', 'бахрейн', 'беларусь', 'белиз', 'бельгия', 'бенин',
        'болгария', 'боливия', 'босния и герцеговина', 'ботсвана', 'бразилия',
        'бруней', 'буркина-фасо', 'бурунди', 'бутан', 'вануату', 'ватикан',
        'великобритания', 'венгрия', 'венесуэла', 'вьетнам', 'габон', 'гаити',
        'гайана', 'гамбия', 'гана', 'гватемала', 'гвинея', 'гвинея-бисау',
        'германия', 'гондурас', 'гренада', 'греция', 'грузия', 'дания',
        'джибути', 'доминика', 'доминиканская республика', 'египет', 'замбия',
        'зимбабве', 'израиль', 'индия', 'индонезия', 'иордания', 'ирак', 'иран',
        'ирландия', 'исландия', 'испания', 'италия', 'йемен', 'кабо-верде',
        'казахстан', 'камбоджа', 'камерун', 'канада', 'катар', 'кения', 'кипр',
        'киргизия', 'кирибати', 'китай', 'колумбия', 'коморы', 'конго', 'корея',
        'косово', 'коста-рика', "кот-д'ивуар", 'куба', 'кувейт', 'лаос', 'латвия',
        'лесото', 'либерия', 'ливан', 'ливия', 'литва', 'лихтенштейн',
        'люксембург', 'маврикий', 'мавритания', 'мадагаскар', 'малави',
        'малайзия', 'мали', 'мальдивы', 'мальта', 'марокко', 'маршалловы острова',
        'мексика', 'микронезия', 'мозамбик', 'молдавия', 'монако', 'монголия',
        'мьянма', 'намибия', 'науру', 'непал', 'нигер', 'нигерия', 'нидерланды',
        'никарагуа', 'новая зеландия', 'норвегия', 'оаэ', 'оман', 'пакистан',
        'палау', 'панама', 'папуа новая гвинея', 'парагвай', 'перу', 'польша',
        'португалия', 'россия', 'руанда', 'румыния', 'сальвадор', 'самоа',
        'сан-марино', 'сан-томе и принсипи', 'саудовская аравия',
        'северная корея', 'северная македония', 'сейшелы', 'сенегал',
        'сент-винсент и гренадины', 'сент-китс и невис', 'сент-люсия', 'сербия',
        'сингапур', 'сирия', 'словакия', 'словения', 'соломоновы острова',
        'сомали', 'судан', 'суринам', 'сша', 'сьерра-леоне', 'таджикистан',
        'тайвань', 'таиланд', 'танзания', 'тимор-лесте', 'того', 'тонга',
        'тринидад и тобаго', 'тувалу', 'тунис', 'туркмения', 'турция', 'уганда',
        'узбекистан', 'украина', 'уругвай', 'фиджи', 'филиппины', 'финляндия',
        'франция', 'хорватия', 'центральноафриканская республика', 'чад',
        'черногория', 'чехия', 'чили', 'швейцария', 'швеция', 'шри-ланка',
        'эквадор', 'экваториальная гвинея', 'эритрея', 'эсватини', 'эстония',
        'эфиопия', 'юар', 'южная корея', 'южный судан', 'ямайка', 'япония',
    ];

    /**
     * Common spellings that must land on the same canonical tag.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'англия' => 'великобритания',
        'британия' => 'великобритания',
        'багамские острова' => 'багамы',
        'белоруссия' => 'беларусь',
        'босния' => 'босния и герцеговина',
        'герцеговина' => 'босния и герцеговина',
        'восточный тимор' => 'тимор-лесте',
        'голландия' => 'нидерланды',
        'демократическая республика конго' => 'конго',
        'доминикана' => 'доминиканская республика',
        'коморские острова' => 'коморы',
        'кыргызстан' => 'киргизия',
        'македония' => 'северная македония',
        'мальдивские острова' => 'мальдивы',
        'молдова' => 'молдавия',
        'объединенные арабские эмираты' => 'оаэ',
        'свазиленд' => 'эсватини',
        'сейшельские острова' => 'сейшелы',
        'соединенные штаты америки' => 'сша',
        'туркменистан' => 'туркмения',
        'чешская республика' => 'чехия',
        'эмираты' => 'оаэ',
        'южно-африканская республика' => 'юар',
    ];

    /** @var array<string, string>|null match key => canonical tag name */
    private static ?array $_canonicalByKey = null;

    private static ?string $_pattern = null;

    /**
     * Every country named in the label of the title, in the order the names
     * appear, without repetitions.
     *
     * @return string[]
     */
    public function extract(string $title): array
    {
        $label = $this->label($title);
        $matches = [];
        if ($label === '' || preg_match_all($this->pattern(), $label, $matches) === false) {
            return [];
        }

        $canonicalByKey = $this->canonicalByKey();
        $tags = [];
        foreach ($matches[0] as $match) {
            $canonical = $canonicalByKey[$match] ?? null;
            if ($canonical !== null) {
                $tags[$canonical] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * The label before the dash or the colon that starts the description.
     * A bare hyphen inside a country name ("Коста-Рика") is not a separator.
     */
    private function label(string $title): string
    {
        $parts = preg_split('/\s+[—–]\s+|\s+-\s+|:\s+/u', $title, 2);
        return $this->matchKey($parts[0] ?? $title);
    }

    /**
     * Lower case, ё folded to е, every run of non-letters turned into one
     * space: "Коста-Рика" and "Коста Рика" become the same key.
     */
    private function matchKey(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace('ё', 'е', $value);
        $value = (string)preg_replace('/[^\p{L}]+/u', ' ', $value);
        return trim($value);
    }

    /**
     * @return array<string, string>
     */
    private function canonicalByKey(): array
    {
        if (self::$_canonicalByKey === null) {
            $map = [];
            foreach (self::COUNTRIES as $country) {
                $map[$this->matchKey($country)] = $country;
            }
            foreach (self::ALIASES as $alias => $country) {
                $map[$this->matchKey($alias)] = $country;
            }
            uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            self::$_canonicalByKey = $map;
        }

        return self::$_canonicalByKey;
    }

    /**
     * One alternation of every known name, longest first, so "Босния и
     * Герцеговина" wins over "Босния" at the same position.
     */
    private function pattern(): string
    {
        if (self::$_pattern === null) {
            $keys = array_keys($this->canonicalByKey());
            $alternatives = array_map(
                static fn (string $key): string => preg_quote($key, '/'),
                $keys,
            );
            self::$_pattern = '/(?<![\p{L}])(' . implode('|', $alternatives) . ')(?![\p{L}])/u';
        }

        return self::$_pattern;
    }
}
