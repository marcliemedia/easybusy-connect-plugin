<?php

declare(strict_types=1);

namespace EasyBusyConnect\Support;

/**
 * ISO 3166-1 alpha-2 country list for the booking form.
 *
 * EasyBusy rejects an appointment request whose patientInfo.address.countryCode
 * is null ("must not be null", HTTP 400), so the country is not optional
 * decoration — it is part of every booking payload. The vendor does not
 * validate the value, which makes a typo silent, so the form offers a closed
 * list and the server re-checks the code against it.
 *
 * Names ship in English and are translated at runtime with ext/intl when the
 * host has it (SiteGround does), so a Croatian form shows "Njemačka" without
 * carrying a second name table.
 */
final class Countries
{
    /** Shown first in the picker: the clinic's own country and its usual catchment. */
    public const PRIORITY = ['HR', 'SI', 'BA', 'RS', 'ME', 'MK', 'AT', 'DE', 'IT', 'HU', 'CH', 'GB', 'IE'];

    public const FALLBACK = 'HR';

    /** @var array<string,string> */
    private const NAMES = [
        'AD' => 'Andorra',
        'AE' => 'United Arab Emirates',
        'AF' => 'Afghanistan',
        'AG' => 'Antigua & Barbuda',
        'AI' => 'Anguilla',
        'AL' => 'Albania',
        'AM' => 'Armenia',
        'AO' => 'Angola',
        'AQ' => 'Antarctica',
        'AR' => 'Argentina',
        'AS' => 'American Samoa',
        'AT' => 'Austria',
        'AU' => 'Australia',
        'AW' => 'Aruba',
        'AX' => 'Åland Islands',
        'AZ' => 'Azerbaijan',
        'BA' => 'Bosnia & Herzegovina',
        'BB' => 'Barbados',
        'BD' => 'Bangladesh',
        'BE' => 'Belgium',
        'BF' => 'Burkina Faso',
        'BG' => 'Bulgaria',
        'BH' => 'Bahrain',
        'BI' => 'Burundi',
        'BJ' => 'Benin',
        'BL' => 'St. Barthélemy',
        'BM' => 'Bermuda',
        'BN' => 'Brunei',
        'BO' => 'Bolivia',
        'BQ' => 'Caribbean Netherlands',
        'BR' => 'Brazil',
        'BS' => 'Bahamas',
        'BT' => 'Bhutan',
        'BV' => 'Bouvet Island',
        'BW' => 'Botswana',
        'BY' => 'Belarus',
        'BZ' => 'Belize',
        'CA' => 'Canada',
        'CC' => 'Cocos (Keeling) Islands',
        'CD' => 'Congo - Kinshasa',
        'CF' => 'Central African Republic',
        'CG' => 'Congo - Brazzaville',
        'CH' => 'Switzerland',
        'CI' => 'Côte d’Ivoire',
        'CK' => 'Cook Islands',
        'CL' => 'Chile',
        'CM' => 'Cameroon',
        'CN' => 'China',
        'CO' => 'Colombia',
        'CR' => 'Costa Rica',
        'CU' => 'Cuba',
        'CV' => 'Cape Verde',
        'CW' => 'Curaçao',
        'CX' => 'Christmas Island',
        'CY' => 'Cyprus',
        'CZ' => 'Czechia',
        'DE' => 'Germany',
        'DJ' => 'Djibouti',
        'DK' => 'Denmark',
        'DM' => 'Dominica',
        'DO' => 'Dominican Republic',
        'DZ' => 'Algeria',
        'EC' => 'Ecuador',
        'EE' => 'Estonia',
        'EG' => 'Egypt',
        'EH' => 'Western Sahara',
        'ER' => 'Eritrea',
        'ES' => 'Spain',
        'ET' => 'Ethiopia',
        'FI' => 'Finland',
        'FJ' => 'Fiji',
        'FK' => 'Falkland Islands',
        'FM' => 'Micronesia',
        'FO' => 'Faroe Islands',
        'FR' => 'France',
        'GA' => 'Gabon',
        'GB' => 'United Kingdom',
        'GD' => 'Grenada',
        'GE' => 'Georgia',
        'GF' => 'French Guiana',
        'GG' => 'Guernsey',
        'GH' => 'Ghana',
        'GI' => 'Gibraltar',
        'GL' => 'Greenland',
        'GM' => 'Gambia',
        'GN' => 'Guinea',
        'GP' => 'Guadeloupe',
        'GQ' => 'Equatorial Guinea',
        'GR' => 'Greece',
        'GS' => 'South Georgia & South Sandwich Islands',
        'GT' => 'Guatemala',
        'GU' => 'Guam',
        'GW' => 'Guinea-Bissau',
        'GY' => 'Guyana',
        'HK' => 'Hong Kong SAR China',
        'HM' => 'Heard & McDonald Islands',
        'HN' => 'Honduras',
        'HR' => 'Croatia',
        'HT' => 'Haiti',
        'HU' => 'Hungary',
        'ID' => 'Indonesia',
        'IE' => 'Ireland',
        'IL' => 'Israel',
        'IM' => 'Isle of Man',
        'IN' => 'India',
        'IO' => 'British Indian Ocean Territory',
        'IQ' => 'Iraq',
        'IR' => 'Iran',
        'IS' => 'Iceland',
        'IT' => 'Italy',
        'JE' => 'Jersey',
        'JM' => 'Jamaica',
        'JO' => 'Jordan',
        'JP' => 'Japan',
        'KE' => 'Kenya',
        'KG' => 'Kyrgyzstan',
        'KH' => 'Cambodia',
        'KI' => 'Kiribati',
        'KM' => 'Comoros',
        'KN' => 'St. Kitts & Nevis',
        'KP' => 'North Korea',
        'KR' => 'South Korea',
        'KW' => 'Kuwait',
        'KY' => 'Cayman Islands',
        'KZ' => 'Kazakhstan',
        'LA' => 'Laos',
        'LB' => 'Lebanon',
        'LC' => 'St. Lucia',
        'LI' => 'Liechtenstein',
        'LK' => 'Sri Lanka',
        'LR' => 'Liberia',
        'LS' => 'Lesotho',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'LV' => 'Latvia',
        'LY' => 'Libya',
        'MA' => 'Morocco',
        'MC' => 'Monaco',
        'MD' => 'Moldova',
        'ME' => 'Montenegro',
        'MF' => 'St. Martin',
        'MG' => 'Madagascar',
        'MH' => 'Marshall Islands',
        'MK' => 'North Macedonia',
        'ML' => 'Mali',
        'MM' => 'Myanmar (Burma)',
        'MN' => 'Mongolia',
        'MO' => 'Macao SAR China',
        'MP' => 'Northern Mariana Islands',
        'MQ' => 'Martinique',
        'MR' => 'Mauritania',
        'MS' => 'Montserrat',
        'MT' => 'Malta',
        'MU' => 'Mauritius',
        'MV' => 'Maldives',
        'MW' => 'Malawi',
        'MX' => 'Mexico',
        'MY' => 'Malaysia',
        'MZ' => 'Mozambique',
        'NA' => 'Namibia',
        'NC' => 'New Caledonia',
        'NE' => 'Niger',
        'NF' => 'Norfolk Island',
        'NG' => 'Nigeria',
        'NI' => 'Nicaragua',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'NP' => 'Nepal',
        'NR' => 'Nauru',
        'NU' => 'Niue',
        'NZ' => 'New Zealand',
        'OM' => 'Oman',
        'PA' => 'Panama',
        'PE' => 'Peru',
        'PF' => 'French Polynesia',
        'PG' => 'Papua New Guinea',
        'PH' => 'Philippines',
        'PK' => 'Pakistan',
        'PL' => 'Poland',
        'PM' => 'St. Pierre & Miquelon',
        'PN' => 'Pitcairn Islands',
        'PR' => 'Puerto Rico',
        'PS' => 'Palestinian Territories',
        'PT' => 'Portugal',
        'PW' => 'Palau',
        'PY' => 'Paraguay',
        'QA' => 'Qatar',
        'RE' => 'Réunion',
        'RO' => 'Romania',
        'RS' => 'Serbia',
        'RU' => 'Russia',
        'RW' => 'Rwanda',
        'SA' => 'Saudi Arabia',
        'SB' => 'Solomon Islands',
        'SC' => 'Seychelles',
        'SD' => 'Sudan',
        'SE' => 'Sweden',
        'SG' => 'Singapore',
        'SH' => 'St. Helena',
        'SI' => 'Slovenia',
        'SJ' => 'Svalbard & Jan Mayen',
        'SK' => 'Slovakia',
        'SL' => 'Sierra Leone',
        'SM' => 'San Marino',
        'SN' => 'Senegal',
        'SO' => 'Somalia',
        'SR' => 'Suriname',
        'SS' => 'South Sudan',
        'ST' => 'São Tomé & Príncipe',
        'SV' => 'El Salvador',
        'SX' => 'Sint Maarten',
        'SY' => 'Syria',
        'SZ' => 'Eswatini',
        'TC' => 'Turks & Caicos Islands',
        'TD' => 'Chad',
        'TF' => 'French Southern Territories',
        'TG' => 'Togo',
        'TH' => 'Thailand',
        'TJ' => 'Tajikistan',
        'TK' => 'Tokelau',
        'TL' => 'Timor-Leste',
        'TM' => 'Turkmenistan',
        'TN' => 'Tunisia',
        'TO' => 'Tonga',
        'TR' => 'Türkiye',
        'TT' => 'Trinidad & Tobago',
        'TV' => 'Tuvalu',
        'TW' => 'Taiwan',
        'TZ' => 'Tanzania',
        'UA' => 'Ukraine',
        'UG' => 'Uganda',
        'UM' => 'U.S. Outlying Islands',
        'US' => 'United States',
        'UY' => 'Uruguay',
        'UZ' => 'Uzbekistan',
        'VA' => 'Vatican City',
        'VC' => 'St. Vincent & Grenadines',
        'VE' => 'Venezuela',
        'VG' => 'British Virgin Islands',
        'VI' => 'U.S. Virgin Islands',
        'VN' => 'Vietnam',
        'VU' => 'Vanuatu',
        'WF' => 'Wallis & Futuna',
        'WS' => 'Samoa',
        'YE' => 'Yemen',
        'YT' => 'Mayotte',
        'ZA' => 'South Africa',
        'ZM' => 'Zambia',
        'ZW' => 'Zimbabwe',
    ];

    /** Uppercased two-letter code, or '' when it is not a real country code. */
    public static function normalise(string $code): string
    {
        $code = strtoupper(trim($code));

        return isset(self::NAMES[$code]) ? $code : '';
    }

    public static function isValid(string $code): bool
    {
        return self::normalise($code) !== '';
    }

    /**
     * Codes mapped to display names in $locale, priority countries first and the
     * rest alphabetical by the *localised* name — an alphabetical English list
     * reads as random once the labels are Croatian.
     *
     * @return array<string,string>
     */
    public static function options(string $locale = 'en'): array
    {
        $rest = [];
        foreach (self::NAMES as $code => $english) {
            if (in_array($code, self::PRIORITY, true)) {
                continue;
            }
            $rest[$code] = self::name($code, $locale);
        }

        if (class_exists('\Collator')) {
            $collator = new \Collator($locale);
            $collator->asort($rest);
        } else {
            asort($rest, SORT_NATURAL | SORT_FLAG_CASE);
        }

        $options = [];
        foreach (self::PRIORITY as $code) {
            $options[$code] = self::name($code, $locale);
        }

        return $options + $rest;
    }

    public static function name(string $code, string $locale = 'en'): string
    {
        $code = self::normalise($code);
        if ($code === '') {
            return '';
        }

        if (class_exists('\Locale')) {
            $translated = \Locale::getDisplayRegion('-' . $code, $locale);
            if ($translated !== '' && $translated !== $code) {
                return $translated;
            }
        }

        return self::NAMES[$code];
    }
}
