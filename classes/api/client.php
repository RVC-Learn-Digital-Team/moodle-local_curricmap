<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_curricmap\api;

use local_curricmap\local\apilog;

/**
 * Sofia API client.
 *
 * Built on \core\http_client (Guzzle) so tests can inject a MockHandler; TLS
 * verification is core's default and is never disabled. OAuth2 client-credentials
 * tokens are cached in MUC until shortly before expiry, with a single re-auth
 * retry on 401. Rate-limit headers (X-Sofia-Request-Count/Limit, 60/hour) are
 * captured per response, persisted to plugin config for admin visibility, and
 * enforced against a configurable floor: once the remaining budget seen by this
 * client instance drops to the floor, further requests throw rather than send.
 *
 * Credentials and bearer tokens are never logged.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var array Key-only query options used for Nodes/Tree fetches by the sync engine. */
    const DEFAULT_NODE_OPTIONS = ['coalesce' => '', 'connection-sort' => '', 'url' => ''];

    /** @var int Seconds before nominal token expiry at which we re-authenticate. */
    const TOKEN_EXPIRY_MARGIN = 60;

    /** @var int Default rate-limit floor when the setting is unset. */
    const DEFAULT_RATE_FLOOR = 10;

    /** @var int Persisted rate headers younger than this seed a new client's budget. */
    const RATE_SEED_WINDOW = 900;

    /** @var \core\http_client HTTP client (injectable for tests). */
    private \core\http_client $http;

    /** @var string Sofia base URL, no trailing slash. */
    private string $baseurl;

    /** @var string OAuth2 client id. */
    private string $clientid;

    /** @var string OAuth2 client secret. */
    private string $clientsecret;

    /** @var int Remaining-request floor below which requests are refused. */
    private int $ratefloor;

    /** @var int|null Remaining request budget last seen on a response, null before any response. */
    private ?int $remaining = null;

    /** @var int Requests actually sent by this instance (excluding token requests). */
    private int $requestcount = 0;

    /**
     * Constructor. Reads connection settings from plugin configuration.
     *
     * @param \core\http_client|null $http Injectable HTTP client for tests.
     */
    public function __construct(?\core\http_client $http = null) {
        $this->http = $http ?? new \core\http_client();
        $config = get_config('local_curricmap');
        $this->baseurl = rtrim(trim($config->sofia_baseurl ?? ''), '/');
        $this->clientid = trim($config->sofia_clientid ?? '');
        $this->clientsecret = (string) ($config->sofia_clientsecret ?? '');
        $floor = (int) ($config->ratelimitfloor ?? self::DEFAULT_RATE_FLOOR);
        $this->ratefloor = $floor > 0 ? $floor : self::DEFAULT_RATE_FLOOR;

        // Seed the budget from recently persisted headers so a fresh instance
        // (e.g. an admin page click) refuses politely instead of collecting a
        // rate-limit 403 from the server. Sofia's window is an hour; headers
        // older than the seed window are ignored as probably stale.
        $seen = (int) ($config->lastrateseen ?? 0);
        if ($seen && (time() - $seen) < self::RATE_SEED_WINDOW
                && isset($config->lastratecount, $config->lastratelimit)) {
            $this->remaining = max(0, (int) $config->lastratelimit - (int) $config->lastratecount);
        }
    }

    /**
     * Are base URL and credentials all configured?
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->baseurl !== '' && $this->clientid !== '' && $this->clientsecret !== '';
    }

    /**
     * Remaining request budget last seen on a response header, if any.
     *
     * @return int|null
     */
    public function remaining(): ?int {
        return $this->remaining;
    }

    /**
     * Requests sent by this instance (excluding token requests).
     *
     * @return int
     */
    public function request_count(): int {
        return $this->requestcount;
    }

    /**
     * Fetch the Metadata API payload (tag/connection schema).
     *
     * @param string $slug Programme slug.
     * @param string $version Version, label or revision hash.
     * @return array Decoded payload.
     */
    public function metadata(string $slug, string $version): array {
        return $this->get_json($this->apipath($slug, 'metadata', $version));
    }

    /**
     * Fetch the Nodes API payload (flat uuid => node map).
     *
     * @param string $slug Programme slug.
     * @param string $version Version, label or revision hash.
     * @param string|null $subtreeuuid Optional subtree restriction.
     * @param array|null $options Query options; defaults to DEFAULT_NODE_OPTIONS.
     * @return array Decoded payload.
     */
    public function nodes(string $slug, string $version, ?string $subtreeuuid = null, ?array $options = null): array {
        return $this->get_json(
            $this->apipath($slug, 'nodes', $version, $subtreeuuid),
            $options ?? self::DEFAULT_NODE_OPTIONS
        );
    }

    /**
     * Fetch the Tree API payload (nested node objects).
     *
     * @param string $slug Programme slug.
     * @param string $version Version, label or revision hash.
     * @param string|null $subtreeuuid Optional subtree restriction.
     * @param array|null $options Query options; defaults to DEFAULT_NODE_OPTIONS.
     * @return array Decoded payload.
     */
    public function tree(string $slug, string $version, ?string $subtreeuuid = null, ?array $options = null): array {
        return $this->get_json(
            $this->apipath($slug, 'tree', $version, $subtreeuuid),
            $options ?? self::DEFAULT_NODE_OPTIONS
        );
    }

    /**
     * Fetch the Compare API payload between two references.
     *
     * Comparing a reference with itself is the documented way to resolve the
     * current revision hash behind a label (meta.compare in the response).
     *
     * @param string $slug Programme slug.
     * @param string $from Older version, label or hash (first URL parameter).
     * @param string $to Newer version, label or hash (second URL parameter).
     * @return array Decoded payload.
     */
    public function compare(string $slug, string $from, string $to): array {
        return $this->get_json('/api/_' . $slug . '_/compare/' . $from . '/' . $to);
    }

    /**
     * Perform an authenticated GET returning decoded JSON.
     *
     * @param string $path API path starting with /.
     * @param array $params Query parameters (key-only options use empty-string values).
     * @return array Decoded payload.
     */
    public function get_json(string $path, array $params = []): array {
        if (!$this->is_configured()) {
            throw new client_exception('errornotconfigured');
        }
        if ($this->remaining !== null && $this->remaining <= $this->ratefloor) {
            throw new client_exception('errorratefloor', (object) ['remaining' => $this->remaining, 'floor' => $this->ratefloor]);
        }

        $logurl = $path . ($params ? '?' . http_build_query($params, '', '&') : '');
        $url = $this->baseurl . $logurl;
        $started = microtime(true);

        try {
            $response = $this->request_with_auth($url);
        } catch (client_exception $exception) {
            throw $exception;
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            apilog::record('GET', $logurl, null, $this->elapsed($started), null, null, false, $exception->getMessage());
            throw new client_exception('errorhttp', (object) ['url' => $logurl, 'code' => 0], null, $exception->getMessage());
        }

        $elapsed = $this->elapsed($started);
        $code = $response->getStatusCode();
        [$count, $limit] = $this->capture_rate($response);
        $body = (string) $response->getBody();

        if ($code !== 200) {
            apilog::record('GET', $logurl, $code, $elapsed, $count, $limit, false, substr($body, 0, apilog::PREVIEW_LENGTH));
            throw new client_exception('errorhttp', (object) ['url' => $logurl, 'code' => $code], $code, substr($body, 0, 500));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            apilog::record('GET', $logurl, $code, $elapsed, $count, $limit, false, 'Response was not valid JSON', $body);
            throw new client_exception('errorinvalidjson', (object) ['url' => $logurl], $code, substr($body, 0, 500));
        }

        apilog::record('GET', $logurl, $code, $elapsed, $count, $limit, true, null, $body);
        return $decoded;
    }

    /**
     * Send an authenticated GET, re-authenticating once on 401.
     *
     * @param string $url Absolute URL.
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function request_with_auth(string $url): \Psr\Http\Message\ResponseInterface {
        $response = $this->send_get($url, $this->token());
        if ($response->getStatusCode() === 401) {
            $response = $this->send_get($url, $this->token(true));
        }
        return $response;
    }

    /**
     * Send one GET request with a bearer token.
     *
     * @param string $url Absolute URL.
     * @param string $token Bearer token.
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function send_get(string $url, string $token): \Psr\Http\Message\ResponseInterface {
        $this->requestcount++;
        $options = [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'http_errors' => false,
        ];
        return $this->http->get($url, $options);
    }

    /**
     * Obtain a bearer token, from MUC cache unless expiring or a refresh is forced.
     *
     * @param bool $forcenew Discard any cached token and re-authenticate.
     * @return string
     */
    private function token(bool $forcenew = false): string {
        $cache = \cache::make('local_curricmap', 'token');
        $key = sha1($this->baseurl . '|' . $this->clientid);

        if (!$forcenew) {
            $cached = $cache->get($key);
            if (is_array($cached) && ($cached['expires'] ?? 0) > time() + self::TOKEN_EXPIRY_MARGIN) {
                return $cached['token'];
            }
        }

        $options = [
            'auth' => [$this->clientid, $this->clientsecret],
            'form_params' => ['grant_type' => 'client_credentials'],
            'http_errors' => false,
        ];
        $started = microtime(true);
        try {
            $response = $this->http->post($this->baseurl . '/o/token/', $options);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            apilog::record('POST', '/o/token/', null, $this->elapsed($started), null, null, false, $exception->getMessage());
            throw new client_exception('errortoken', null, null, $exception->getMessage());
        }

        $elapsed = $this->elapsed($started);
        $code = $response->getStatusCode();
        if ($code !== 200) {
            apilog::record('POST', '/o/token/', $code, $elapsed, null, null, false, 'Token request failed');
            throw new client_exception('errortoken', null, $code);
        }

        $payload = json_decode((string) $response->getBody(), true);
        $token = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            apilog::record('POST', '/o/token/', $code, $elapsed, null, null, false, 'Token response had no access_token');
            throw new client_exception('errortoken', null, $code);
        }

        $expiresin = (int) ($payload['expires_in'] ?? 3600);
        $cache->set($key, ['token' => $token, 'expires' => time() + $expiresin]);
        // Deliberately no body preview for token responses - never log tokens.
        apilog::record('POST', '/o/token/', $code, $elapsed, null, null, true);
        return $token;
    }

    /**
     * Capture rate-limit headers from a response and persist for admin visibility.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response.
     * @return array{0: int|null, 1: int|null} Count and limit header values.
     */
    private function capture_rate(\Psr\Http\Message\ResponseInterface $response): array {
        $count = $response->getHeaderLine('X-Sofia-Request-Count');
        $limit = $response->getHeaderLine('X-Sofia-Request-Limit');
        if ($count === '' || $limit === '') {
            return [null, null];
        }
        $count = (int) $count;
        $limit = (int) $limit;
        $this->remaining = max(0, $limit - $count);
        set_config('lastratecount', $count, 'local_curricmap');
        set_config('lastratelimit', $limit, 'local_curricmap');
        set_config('lastrateseen', time(), 'local_curricmap');
        return [$count, $limit];
    }

    /**
     * Milliseconds elapsed since a microtime(true) start point.
     *
     * @param float $started Start time.
     * @return int
     */
    private function elapsed(float $started): int {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * Build a standard API path.
     *
     * @param string $slug Programme slug.
     * @param string $endpoint Endpoint name (nodes, tree, metadata).
     * @param string $version Version, label or hash.
     * @param string|null $subtreeuuid Optional subtree restriction.
     * @return string
     */
    private function apipath(string $slug, string $endpoint, string $version, ?string $subtreeuuid = null): string {
        $path = '/api/_' . $slug . '_/' . $endpoint . '/' . $version;
        if ($subtreeuuid !== null && $subtreeuuid !== '') {
            $path .= '/' . $subtreeuuid;
        }
        return $path;
    }
}
