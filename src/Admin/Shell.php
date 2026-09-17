<?php

declare(strict_types=1);

namespace EasyBusyConnect\Admin;

/**
 * Small presentation kit for the plugin's admin screens.
 *
 * It exists so the screens stop looking like stock WordPress: one header, cards,
 * toggles, chips and tables with the plugin's own markup, all escaped here so
 * the page classes stay readable. Styling lives in assets/css/admin.css.
 */
final class Shell
{
    public static function header(string $title, string $subtitle, string $pills = ''): void
    {
        printf(
            '<header class="ebc-head"><div class="ebc-head__brand"><span class="ebc-head__mark" aria-hidden="true">EB</span>' .
            '<div><h1 class="ebc-head__title">%s</h1><p class="ebc-head__sub">%s</p></div></div>' .
            '<div class="ebc-head__pills">%s</div></header>',
            esc_html($title),
            esc_html($subtitle),
            $pills
        );
    }

    public static function pill(string $label, string $tone = 'muted'): string
    {
        return sprintf('<span class="ebc-pill ebc-pill--%s">%s</span>', esc_attr($tone), esc_html($label));
    }

    /**
     * Who made this. The plugin is Marclie's work built against EasyBusy's
     * public B2B API — the vendor neither ships nor endorses it.
     */
    public static function credit(): void
    {
        printf(
            '<p class="ebc-credit">%s</p>',
            esc_html(sprintf(
                /* translators: %s: plugin version */
                __('EasyBusy Connect %s — built by Marclie. Not affiliated with or endorsed by EasyBusy; it talks to the EasyBusy B2B API with the clinic\'s own key.', 'easybusy-connect'),
                defined('EBC_VERSION') ? (string) constant('EBC_VERSION') : ''
            ))
        );
    }

    /**
     * Pager shared by every table in the plugin, so no screen ever prints more
     * than one page of rows. Prints a "x–y of N" summary plus windowed page
     * links (first, last, current ±2) so 40 pages do not become 40 links.
     *
     * @param string $base  URL the page argument is appended to
     * @param string $arg   query argument carrying the page number
     */
    public static function pagination(int $page, int $pages, int $total, int $perPage, string $base, string $arg = 'paged'): void
    {
        if ($total === 0) {
            return;
        }

        $first = (($page - 1) * $perPage) + 1;
        $last = min($total, $page * $perPage);

        echo '<div class="ebc-pagination">';
        printf(
            '<span class="ebc-pagination__summary">%s</span>',
            esc_html(sprintf(
                /* translators: 1: first row, 2: last row, 3: total rows */
                __('%1$d–%2$d of %3$d', 'easybusy-connect'),
                $first,
                $last,
                $total
            ))
        );

        if ($pages > 1) {
            $link = static function (int $target, string $label, bool $current = false) use ($base, $arg): void {
                if ($current) {
                    printf('<span class="is-current">%s</span>', esc_html($label));

                    return;
                }
                printf(
                    '<a href="%s">%s</a>',
                    esc_url(add_query_arg($arg, $target, $base)),
                    esc_html($label)
                );
            };

            if ($page > 1) {
                $link($page - 1, '‹');
            }

            $window = range(max(1, $page - 2), min($pages, $page + 2));
            if (!in_array(1, $window, true)) {
                $link(1, '1');
                echo '<span class="ebc-pagination__gap">…</span>';
            }
            foreach ($window as $number) {
                $link($number, (string) $number, $number === $page);
            }
            if (!in_array($pages, $window, true)) {
                echo '<span class="ebc-pagination__gap">…</span>';
                $link($pages, (string) $pages);
            }

            if ($page < $pages) {
                $link($page + 1, '›');
            }
        }

        echo '</div>';
    }

    /** Success/error feedback after a redirect, in the plugin's own styling. */
    public static function flash(): void
    {
        $flash = isset($_GET['ebc-flash']) ? sanitize_text_field((string) $_GET['ebc-flash']) : '';
        if ($flash === '') {
            return;
        }

        $messages = [
            'saved'       => [__('Settings saved.', 'easybusy-connect'), 'ok'],
            'probed'      => [__('Capabilities re-probed against the live API.', 'easybusy-connect'), 'ok'],
            'purged'      => [__('Caches purged.', 'easybusy-connect'), 'ok'],
            'purged-entries' => [__('Entries past the retention window were deleted.', 'easybusy-connect'), 'ok'],
            'mailed'      => [__('Test e-mails sent to the configured recipients.', 'easybusy-connect'), 'ok'],
            'mail-failed' => [__('WordPress could not send the test e-mails — check your SMTP plugin.', 'easybusy-connect'), 'error'],
        ];

        if (!isset($messages[$flash])) {
            return;
        }

        [$message, $tone] = $messages[$flash];
        printf('<div class="ebc-flash ebc-flash--%s" role="status">%s</div>', esc_attr($tone), esc_html($message));
    }

    public static function cardOpen(string $title, string $description = ''): void
    {
        printf(
            '<section class="ebc-card"><div class="ebc-card__head"><h3>%s</h3>%s</div><div class="ebc-card__body">',
            esc_html($title),
            $description !== '' ? '<p>' . esc_html($description) . '</p>' : ''
        );
    }

    public static function cardClose(): void
    {
        echo '</div></section>';
    }

    public static function stat(string $label, string $value): void
    {
        printf(
            '<div class="ebc-stat"><span class="ebc-stat__label">%s</span><span class="ebc-stat__value">%s</span></div>',
            esc_html($label),
            esc_html($value)
        );
    }

    public static function toggle(string $name, string $label, bool $checked, string $help = ''): void
    {
        printf(
            '<div class="ebc-field ebc-field--toggle"><label class="ebc-switch"><input type="checkbox" name="%1$s" value="1"%2$s>' .
            '<span class="ebc-switch__track" aria-hidden="true"></span><span class="ebc-switch__label">%3$s</span></label>%4$s</div>',
            esc_attr($name),
            $checked ? ' checked' : '',
            esc_html($label),
            $help !== '' ? '<p class="ebc-help">' . esc_html($help) . '</p>' : ''
        );
    }

    public static function text(string $name, string $label, string $value, string $help = '', string $placeholder = ''): void
    {
        printf(
            '<div class="ebc-field"><label class="ebc-label" for="ebc-%1$s">%2$s</label>' .
            '<input class="ebc-input" type="text" id="ebc-%1$s" name="%1$s" value="%3$s" placeholder="%4$s">%5$s</div>',
            esc_attr($name),
            esc_html($label),
            esc_attr($value),
            esc_attr($placeholder),
            $help !== '' ? '<p class="ebc-help">' . esc_html($help) . '</p>' : ''
        );
    }

    public static function number(string $name, string $label, int $value, string $help = ''): void
    {
        printf(
            '<div class="ebc-field ebc-field--narrow"><label class="ebc-label" for="ebc-%1$s">%2$s</label>' .
            '<input class="ebc-input" type="number" id="ebc-%1$s" name="%1$s" value="%3$d">%4$s</div>',
            esc_attr($name),
            esc_html($label),
            $value,
            $help !== '' ? '<p class="ebc-help">' . esc_html($help) . '</p>' : ''
        );
    }

    public static function textarea(string $name, string $label, string $value, int $rows = 6, string $help = '', string $placeholder = ''): void
    {
        printf(
            '<div class="ebc-field"><label class="ebc-label" for="ebc-%1$s">%2$s</label>' .
            '<textarea class="ebc-input ebc-input--mono" id="ebc-%1$s" name="%1$s" rows="%3$d" placeholder="%4$s">%5$s</textarea>%6$s</div>',
            esc_attr($name),
            esc_html($label),
            max(2, $rows),
            esc_attr($placeholder),
            esc_textarea($value),
            $help !== '' ? '<p class="ebc-help">' . esc_html($help) . '</p>' : ''
        );
    }

    /** @param array<string,string> $options */
    public static function select(string $name, string $label, array $options, string $current, string $help = ''): void
    {
        printf(
            '<div class="ebc-field"><label class="ebc-label" for="ebc-%1$s">%2$s</label><select class="ebc-input" id="ebc-%1$s" name="%1$s">',
            esc_attr($name),
            esc_html($label)
        );
        foreach ($options as $value => $text) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $value),
                selected($current, (string) $value, false),
                esc_html($text)
            );
        }
        printf('</select>%s</div>', $help !== '' ? '<p class="ebc-help">' . esc_html($help) . '</p>' : '');
    }

    public static function pages(string $name, string $label, int $value, string $help = ''): void
    {
        printf(
            '<div class="ebc-field"><label class="ebc-label" for="ebc-%s">%s</label>',
            esc_attr($name),
            esc_html($label)
        );
        wp_dropdown_pages([
            'name'              => $name,
            'id'                => 'ebc-' . $name,
            'class'             => 'ebc-input',
            'selected'          => $value,
            'show_option_none'  => __('— none (confirm inside the form) —', 'easybusy-connect'),
            'option_none_value' => '0',
        ]);
        printf('%s</div>', $help !== '' ? '<p class="ebc-help">' . esc_html($help) . '</p>' : '');
    }
}
