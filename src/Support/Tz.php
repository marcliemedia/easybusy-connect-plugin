<?php

declare(strict_types=1);

namespace EasyBusyConnect\Support;

/**
 * EasyBusy takes Java OffsetDateTime values, so every timestamp must carry the
 * clinic's real UTC offset. Offsets are derived from wp_timezone() and never
 * hardcoded, because Croatia alternates CET (+01:00) and CEST (+02:00).
 */
final class Tz
{
    public const API_FORMAT = 'Y-m-d\TH:i:sP';

    /** Minutes added to "now" before asking for slots: a past `from` is a 400. */
    private const PAST_GUARD_MINUTES = 5;

    public static function zone(): \DateTimeZone
    {
        return wp_timezone();
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::zone());
    }

    public static function api(\DateTimeImmutable $moment): string
    {
        return $moment->format(self::API_FORMAT);
    }

    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(self::zone());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Search window for available slots, clamped so `from` is never in the past.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    public static function searchWindow(int $days, ?\DateTimeImmutable $from = null): array
    {
        $floor = self::now()->modify('+' . self::PAST_GUARD_MINUTES . ' minutes');
        $start = $from === null || $from < $floor ? $floor : $from;
        $end = $start->modify('+' . max(1, $days) . ' days');

        return [$start, $end];
    }

    /**
     * A slot can be longer than the appointment. Expand it into the start times
     * a patient may pick, on the clinic's scheduling grid, so that
     * start + requiredMinutes still fits inside the slot.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public static function startOptions(
        \DateTimeImmutable $slotStart,
        \DateTimeImmutable $slotEnd,
        int $requiredMinutes,
        int $gridMinutes
    ): array {
        $required = max(1, $requiredMinutes);
        $grid = max(5, $gridMinutes);
        $options = [];
        $cursor = $slotStart;

        while ($cursor->modify('+' . $required . ' minutes') <= $slotEnd) {
            $options[] = [
                'value' => self::api($cursor),
                'label' => $cursor->format('H:i'),
            ];
            $cursor = $cursor->modify('+' . $grid . ' minutes');
            if (count($options) >= 48) {
                break;
            }
        }

        if ($options === []) {
            $options[] = ['value' => self::api($slotStart), 'label' => $slotStart->format('H:i')];
        }

        return $options;
    }

    /**
     * True when the site runs on a fixed UTC offset instead of a named zone.
     * Such a site cannot observe DST, so every timestamp sent to EasyBusy is one
     * hour wrong for half the year. Surfaced as an admin warning.
     */
    public static function hasFixedOffset(): bool
    {
        return (bool) preg_match('/^[+-]\d{2}:\d{2}$/', self::zone()->getName());
    }
}
