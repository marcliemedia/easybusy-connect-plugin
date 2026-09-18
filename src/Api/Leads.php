<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Support\Log;
use EasyBusyConnect\Support\RateLimit;

/**
 * Inquiry (lead) delivery. Used when the simple-booking group is unavailable,
 * when the clinic has defined no slots, and as the loss-preventing fallback if a
 * booking call fails after the patient already filled the form.
 *
 * Every field of POST /lead is optional server-side, so validation happens here:
 * an unvalidated submit would create empty records in the clinic's CRM.
 *
 * EasyBusy parses utm_campaign → lead source and utm_source → channel out of the
 * URL strings, so URLs are forwarded with their query intact rather than parsed.
 */
final class Leads
{
    public function __construct(private Client $client, private Capabilities $capabilities)
    {
    }

    /**
     * @param array<string,string> $contact
     * @return array<string,mixed>|\WP_Error
     */
    public function create(array $contact, string $message, array $attribution = []): array|\WP_Error
    {
        $payload = $this->payload($contact, $message, $attribution);
        $response = $this->client->post('/lead', $payload);
        if (is_wp_error($response)) {
            Log::add('error', 'lead failed', ['code' => $response->get_error_code()]);
            if ($response->get_error_code() === 'ebc_forbidden') {
                // The key does not carry the Leads group after all — stop
                // offering attachments and stop falling back to a dead channel.
                $this->capabilities->deny('leads', (string) $response->get_error_message());
            }

            return $response;
        }

        $leadId = is_array($response) && isset($response['id']) ? (int) $response['id'] : null;
        Log::add('info', 'lead created', ['lead_id' => $leadId]);

        return ['leadId' => $leadId];
    }

    /**
     * Uploads one attachment to an existing lead. The endpoint takes
     * multipart/form-data with a single `file` part and must be called once per
     * file — the booking API has no upload endpoint at all, so attachments can
     * only reach EasyBusy through a lead.
     *
     * @return true|\WP_Error
     */
    public function upload(int $leadId, string $path, string $filename, string $mime): bool|\WP_Error
    {
        if (!is_readable($path)) {
            return new \WP_Error('ebc_upload_missing', __('The attachment is no longer available.', 'easybusy-connect'));
        }

        $boundary = 'ebc' . bin2hex(random_bytes(12));
        $body = "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . (string) file_get_contents($path) . "\r\n"
            . "--{$boundary}--\r\n";

        $response = $this->client->raw(
            'POST',
            sprintf('/lead/%d/upload', $leadId),
            $body,
            'multipart/form-data; boundary=' . $boundary
        );

        if (is_wp_error($response)) {
            Log::add('error', 'lead attachment failed', ['lead_id' => $leadId, 'code' => $response->get_error_code()]);

            return $response;
        }

        Log::add('info', 'lead attachment uploaded', ['lead_id' => $leadId]);

        return true;
    }

    /**
     * @param array<string,string> $contact
     * @param array<string,string> $attribution
     * @return array<string,mixed>
     */
    public function payload(array $contact, string $message, array $attribution = []): array
    {
        $first = $contact['firstName'] ?? '';
        $last = $contact['lastName'] ?? '';

        return array_filter([
            'first_name'       => $first,
            'last_name'        => $last,
            'full_name'        => trim($first . ' ' . $last),
            'email'            => $contact['email'] ?? '',
            'phone'            => $contact['phone'] ?? '',
            'city'             => $contact['city'] ?? '',
            'country_code'     => $contact['countryCode'] ?? '',
            'message'          => $message,
            'agent'            => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'ip_address'       => RateLimit::clientIp(),
            'url_contact_page' => $attribution['contact_page'] ?? '',
            'url_referer'      => $attribution['referer'] ?? '',
            'url_cookie'       => $attribution['landing'] ?? '',
            'external_id'      => $attribution['external_id'] ?? '',
            'external_source'  => 'wordpress',
        ], static fn (string $value): bool => $value !== '');
    }
}
