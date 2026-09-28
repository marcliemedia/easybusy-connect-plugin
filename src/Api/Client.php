<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Log;

/**
 * Transport for the EasyBusy B2B REST API.
 *
 * Shape verified live 2026-09-17:
 *  - paths live under /v2, except GET /ping which sits at the host root and
 *    needs no API key (GET /v2/ping is a 404);
 *  - auth is the X-API-KEY header;
 *  - 401 = missing/invalid key, 403 = valid key without that endpoint group,
 *    400 = validation, 405 = correct path but wrong method;
 *  - errors come back as {errorType, message, timestamp}.
 */
final class Client
{
    private const TIMEOUT = 10;
    private const RETRIES = 1;

    /**
     * @param array<string,scalar|null> $query
     * @param array<string,mixed>|null  $body
     * @return array{status:int,data:mixed,error_type:string|null}|\WP_Error
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, bool $requireKey = true): array|\WP_Error
    {
        $key = Settings::apiKey();
        if ($requireKey && $key === '') {
            return new \WP_Error('ebc_no_key', __('No EasyBusy API key is configured.', 'easybusy-connect'));
        }

        $url = Settings::apiBase() . (str_starts_with($path, '/ping') ? $path : '/v2' . $path);
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => array_filter([
                'Accept'       => 'application/json',
                'Content-Type' => $body === null ? null : 'application/json',
                'X-API-KEY'    => $key !== '' ? $key : null,
            ]),
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt <= self::RETRIES) {
            $attempt++;
            $started = microtime(true);
            $response = wp_remote_request($url, $args);
            $duration = (int) round((microtime(true) - $started) * 1000);

            if (is_wp_error($response)) {
                // A single socket close on first contact was observed live.
                $lastError = new \WP_Error('ebc_transport', $response->get_error_message());
                Log::add('warning', 'transport failure', [
                    'method' => $method, 'path' => $path, 'attempt' => $attempt, 'duration_ms' => $duration,
                ]);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            $decoded = $raw === '' ? null : json_decode($raw, true);
            $errorType = is_array($decoded) ? (string) ($decoded['errorType'] ?? '') : '';

            Log::add($status >= 400 ? 'warning' : 'info', 'api call', [
                'method' => $method, 'path' => $path, 'status' => $status,
                'attempt' => $attempt, 'duration_ms' => $duration,
                'error_type' => $errorType !== '' ? $errorType : null,
            ]);

            if ($status >= 500) {
                $lastError = $this->httpError($status, $decoded, $raw);
                continue; // retry server-side failures only
            }

            return [
                'status'     => $status,
                'data'       => $decoded ?? $raw,
                'error_type' => $errorType !== '' ? $errorType : null,
            ];
        }

        return $lastError ?? new \WP_Error('ebc_transport', __('EasyBusy did not answer.', 'easybusy-connect'));
    }

    /**
     * GET helper that turns non-200 answers into typed WP_Errors and unwraps the
     * vendor's `{"data": …}` envelope.
     *
     * @param array<string,scalar|null> $query
     * @return mixed|\WP_Error
     */
    public function get(string $path, array $query = []): mixed
    {
        $response = $this->request('GET', $path, $query);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['status'] !== 200) {
            return $this->httpError($response['status'], $response['data'], '');
        }

        $data = $response['data'];

        return is_array($data) && array_key_exists('data', $data) ? $data['data'] : $data;
    }

    /**
     * @param array<string,mixed>       $body
     * @param array<string,scalar|null> $query
     * @return mixed|\WP_Error
     */
    public function post(string $path, array $body, array $query = []): mixed
    {
        $response = $this->request('POST', $path, $query, $body);
        if (is_wp_error($response)) {
            return $response;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return $this->httpError($response['status'], $response['data'], '');
        }

        return $response['data'];
    }

    /**
     * Sends a pre-encoded body (multipart attachments). No retry: a half-sent
     * file must not be duplicated on the clinic's side.
     *
     * @return mixed|\WP_Error
     */
    public function raw(string $method, string $path, string $body, string $contentType): mixed
    {
        $key = Settings::apiKey();
        if ($key === '') {
            return new \WP_Error('ebc_no_key', __('No EasyBusy API key is configured.', 'easybusy-connect'));
        }

        $response = wp_remote_request(Settings::apiBase() . '/v2' . $path, [
            'method'  => $method,
            'timeout' => 30,
            'headers' => ['X-API-KEY' => $key, 'Content-Type' => $contentType, 'Accept' => 'application/json'],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('ebc_transport', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        Log::add($status >= 400 ? 'warning' : 'info', 'api call', ['method' => $method, 'path' => $path, 'status' => $status]);

        if ($status < 200 || $status >= 300) {
            return $this->httpError($status, json_decode($raw, true), $raw);
        }

        return $raw;
    }

    /** Raw status probe used by the capability map; never throws on 4xx. */
    public function probeStatus(string $method, string $path, bool $requireKey = true): int|\WP_Error
    {
        $response = $this->request($method, $path, [], null, $requireKey);

        return is_wp_error($response) ? $response : $response['status'];
    }

    /**
     * Status probe for an endpoint that only answers to a body (the Leads
     * upload). Same transport as raw(), but 4xx comes back as a status instead
     * of a WP_Error, because the status is the answer being looked for.
     */
    public function probeRawStatus(string $method, string $path, string $body, string $contentType): int|\WP_Error
    {
        $key = Settings::apiKey();
        if ($key === '') {
            return new \WP_Error('ebc_no_key', __('No EasyBusy API key is configured.', 'easybusy-connect'));
        }

        $response = wp_remote_request(Settings::apiBase() . '/v2' . $path, [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => ['X-API-KEY' => $key, 'Content-Type' => $contentType, 'Accept' => 'application/json'],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('ebc_transport', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        Log::add('info', 'api call', ['method' => $method, 'path' => $path, 'status' => $status]);

        return $status;
    }

    private function httpError(int $status, mixed $decoded, string $raw): \WP_Error
    {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
        if ($message === '') {
            $message = $raw !== '' ? substr($raw, 0, 200) : sprintf('HTTP %d', $status);
        }

        $code = match (true) {
            $status === 400 => 'ebc_bad_request',
            $status === 401 => 'ebc_unauthorized',
            $status === 403 => 'ebc_forbidden',
            $status === 404 => 'ebc_not_found',
            $status === 405 => 'ebc_method_not_allowed',
            $status === 409 => 'ebc_conflict',
            $status >= 500  => 'ebc_server_error',
            default         => 'ebc_http_error',
        };

        return new \WP_Error($code, $message, ['status' => $status]);
    }
}
