<?php

declare(strict_types=1);

namespace EasyBusyConnect\Form;

/**
 * Server-side state for one in-progress booking.
 *
 * The browser only ever holds an opaque token: prices, service names, slot
 * ownership and durations are re-read from the API server-side, so a tampered
 * request cannot book something it did not select. The token is also the
 * idempotency key — a repeated submit returns the stored result instead of
 * creating a second appointment.
 */
final class Draft
{
    private const PREFIX = 'ebc_draft_';
    private const TTL = 1800;

    /** @param array<string,mixed> $data */
    private function __construct(public readonly string $token, private array $data)
    {
    }

    public static function create(string $language): self
    {
        $token = bin2hex(random_bytes(16));
        $data = [
            'created'  => time(),
            'language' => $language,
            'service'  => null,
            'doctor'   => null,
            'message'  => '',
            'slot'     => null,
            'start'    => null,
            'result'   => null,
        ];
        set_transient(self::PREFIX . $token, $data, self::TTL);

        return new self($token, $data);
    }

    public static function load(string $token): ?self
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $data = get_transient(self::PREFIX . $token);

        return is_array($data) ? new self($token, $data) : null;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->data[$key] ?? $fallback;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /** @param array<string,mixed> $patch */
    public function merge(array $patch): void
    {
        $this->data = array_merge($this->data, $patch);
        set_transient(self::PREFIX . $this->token, $this->data, self::TTL);
    }

    public function forget(): void
    {
        delete_transient(self::PREFIX . $this->token);
    }

    public function language(): string
    {
        return (string) ($this->data['language'] ?? '');
    }

    public function serviceId(): ?int
    {
        $service = $this->data['service'] ?? null;

        return is_array($service) ? (int) $service['serviceId'] : null;
    }

    public function doctorId(): ?int
    {
        $doctor = $this->data['doctor'] ?? null;

        return is_array($doctor) ? (int) $doctor['doctorId'] : null;
    }
}
