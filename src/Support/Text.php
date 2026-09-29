<?php

declare(strict_types=1);

namespace EasyBusyConnect\Support;

/**
 * Presentation formatting for text typed by clinic staff inside EasyBusy.
 *
 * The vendor returns exactly what was typed into its admin, which mixes
 * styles in the same list: "KONZULTACIJE ESTETIKA \nDR. IVANKA KOVAČIĆ ",
 * "Prvi pregled", "CT", "ORALNA KIRURGIJA". Printing that verbatim makes the
 * booking form look broken, so every label is normalised on the way out —
 * never on the way in, because the API still needs the original ids, and the
 * original text is one live call away.
 */
final class Text
{
    /** Tokens that must stay upper-case even when the rest is de-shouted. */
    private const ACRONYMS = [
        'CT', 'CBCT', 'MR', 'MRI', 'RTG', 'OPG', 'PRF', 'PRP', 'IPL', 'LED',
        'HIFU', 'UZV', 'EKG', 'ORL', 'PDO', 'CO2', '2D', '3D', '4D', 'ALL',
    ];

    /** Academic/medical titles: always lower-case, and they introduce a name. */
    private const TITLES = [
        'dr', 'dr.', 'prim', 'prim.', 'prof', 'prof.', 'doc', 'doc.',
        'mr', 'mr.', 'spec', 'spec.', 'med', 'med.', 'dent', 'dent.',
    ];

    /** Croatian function words: lower-case unless they open the label. */
    private const SMALL_WORDS = [
        'i', 'ili', 'te', 'u', 'na', 'za', 's', 'sa', 'od', 'do', 'iz', 'o',
        'po', 'uz', 'pri', 'bez', 'nad', 'pod', 'pa',
    ];

    /** Collapses line breaks, double spaces and NBSP; trims the edges. */
    public static function clean(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * A label fit for a card, a summary line or an e-mail.
     *
     * Only SHOUTED text is rewritten — anything the clinic already typed in
     * mixed case is left exactly as it is, because that is a deliberate choice
     * ("Čišćenje kamenca" must not become "Čišćenje Kamenca"). Inside a shouted
     * string the first word is capitalised and the rest lower-cased, acronyms
     * keep their case, and everything after an academic title is treated as a
     * personal name.
     */
    public static function label(string $value): string
    {
        $value = self::clean(self::separators($value));
        if ($value === '' || !self::isShouted($value)) {
            return $value;
        }

        $words = explode(' ', $value);
        $name = false;
        $first = true;
        $out = [];

        foreach ($words as $word) {
            if (self::isAcronym($word)) {
                $out[] = $word;
                $first = false;
                continue;
            }

            $lower = self::lower($word);
            if (in_array($lower, self::TITLES, true)) {
                // "DR." introduces a person, so the rest is a name, not prose.
                $out[] = $lower;
                $name = true;
                $first = false;
                continue;
            }

            if (in_array($lower, self::SMALL_WORDS, true) && !$first) {
                // "I" between two service words is the conjunction, not an
                // initial, and a name never starts with one either.
                $out[] = $lower;
                $first = false;
                continue;
            }

            $out[] = ($name || $first) ? self::titleCase($word) : $lower;
            $first = false;
        }

        return implode(' ', $out);
    }

    /** A personal name: title case regardless of how it arrived. */
    public static function person(string $value): string
    {
        $value = self::clean($value);
        if ($value === '') {
            return '';
        }

        $out = [];
        foreach (explode(' ', $value) as $word) {
            $lower = self::lower($word);
            if (in_array($lower, self::TITLES, true)) {
                $out[] = $lower;
                continue;
            }
            $out[] = self::isAcronym($word) ? $word : self::titleCase($word);
        }

        return implode(' ', $out);
    }

    /**
     * Qualifications as the clinic types them — "dr.med.dent.",
     * "d.med.dent., spec. protetike" — spaced out and lower-cased so they read
     * as a subtitle under the name.
     */
    public static function titles(string $value): string
    {
        $value = self::clean($value);
        if ($value === '') {
            return '';
        }

        $value = (string) preg_replace('/\.(?=\p{L})/u', '. ', $value);
        $value = self::clean($value);

        return self::isShouted($value) ? self::lower($value) : $value;
    }

    /**
     * True when the label was typed in caps. One stray lower-case letter does
     * not disqualify it — "ANALIZA KOŽE OBSERVE 520x" is shouting with a unit
     * suffix, not mixed case the clinic chose.
     */
    private static function isShouted(string $value): bool
    {
        preg_match_all('/\p{Lu}/u', $value, $upper);
        preg_match_all('/\p{Ll}/u', $value, $lower);

        return count($upper[0]) >= 2 && count($lower[0]) <= 1;
    }

    /**
     * Known abbreviations, anything carrying a digit, and one/two-letter tokens
     * (ZO, CT) keep the case they arrived in. Three-letter words must not be
     * assumed to be acronyms — "RED" and "PRO" are ordinary words.
     */
    private static function isAcronym(string $word): bool
    {
        $bare = trim($word, ".,:;()[]-");
        if ($bare === '' || in_array(self::lower($bare), self::TITLES, true)) {
            return false;
        }

        return in_array(self::upper($bare), self::ACRONYMS, true)
            || preg_match('/\d/u', $bare) === 1
            || (self::length($bare) <= 2 && !in_array(self::lower($bare), self::SMALL_WORDS, true));
    }

    /** Hyphens and slashes typed without spaces read as one long word. */
    private static function separators(string $value): string
    {
        return (string) preg_replace('/\s*([\/–—])\s*/u', ' $1 ', $value);
    }

    private static function titleCase(string $word): string
    {
        return function_exists('mb_convert_case')
            ? mb_convert_case(self::lower($word), MB_CASE_TITLE, 'UTF-8')
            : ucfirst(strtolower($word));
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function upper(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
