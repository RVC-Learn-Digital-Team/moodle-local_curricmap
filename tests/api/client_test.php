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

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for the Sofia API client, using a Guzzle MockHandler.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_curricmap\api\client
 * @covers    \local_curricmap\local\apilog
 */
final class client_test extends \advanced_testcase {
    /** @var array Request history captured by the Guzzle middleware. */
    private array $history = [];

    /**
     * Configure plugin credentials before each test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('sofia_baseurl', 'https://sofia.example', 'local_curricmap');
        set_config('sofia_clientid', 'testclientid', 'local_curricmap');
        set_config('sofia_clientsecret', 'testclientsecret', 'local_curricmap');
    }

    /**
     * Build a client whose HTTP layer replays the given responses.
     *
     * @param Response[] $responses Queued responses.
     * @return array{0: client, 1: MockHandler}
     */
    private function make_client(array $responses): array {
        $this->history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $http = new \core\http_client(['handler' => $stack]);
        return [new client($http), $mock];
    }

    /**
     * A successful token response.
     *
     * @param string $token Token value.
     * @return Response
     */
    private function token_response(string $token = 'token-1'): Response {
        return new Response(200, [], json_encode(['access_token' => $token, 'expires_in' => 3600]));
    }

    /**
     * A successful JSON API response with rate headers.
     *
     * @param array $payload Body payload.
     * @param int $count Rate count header.
     * @param int $limit Rate limit header.
     * @return Response
     */
    private function json_response(array $payload, int $count = 1, int $limit = 60): Response {
        $headers = [
            'X-Sofia-Request-Count' => (string) $count,
            'X-Sofia-Request-Limit' => (string) $limit,
        ];
        return new Response(200, $headers, json_encode($payload));
    }

    /**
     * Token flow: credentials as basic auth, bearer header on the API request.
     */
    public function test_token_flow(): void {
        [$client] = $this->make_client([
            $this->token_response(),
            $this->json_response(['fields' => []]),
        ]);

        $result = $client->metadata('vet-med', 'LATEST');
        $this->assertSame(['fields' => []], $result);

        $tokenrequest = $this->history[0]['request'];
        $this->assertSame('POST', $tokenrequest->getMethod());
        $this->assertSame('https://sofia.example/o/token/', (string) $tokenrequest->getUri());
        $this->assertStringContainsString('Basic', $tokenrequest->getHeaderLine('Authorization'));
        $this->assertSame('grant_type=client_credentials', (string) $tokenrequest->getBody());

        $apirequest = $this->history[1]['request'];
        $this->assertSame('Bearer token-1', $apirequest->getHeaderLine('Authorization'));
        $this->assertSame('https://sofia.example/api/_vet-med_/metadata/LATEST', (string) $apirequest->getUri());
    }

    /**
     * The token is cached: two API calls, one token request.
     */
    public function test_token_cached(): void {
        [$client, $mock] = $this->make_client([
            $this->token_response(),
            $this->json_response(['a' => 1]),
            $this->json_response(['b' => 2]),
        ]);

        $client->metadata('vet-med', 'LATEST');
        $client->metadata('vet-med', 'LATEST');
        $this->assertSame(0, $mock->count(), 'All queued responses consumed - no second token request.');
    }

    /**
     * Nodes requests carry the key-only sync options in the query string.
     */
    public function test_nodes_query_options(): void {
        [$client] = $this->make_client([
            $this->token_response(),
            $this->json_response([]),
        ]);

        $client->nodes('vet-med', 'LATEST');
        $uri = (string) end($this->history)['request']->getUri();
        $expected = 'https://sofia.example/api/_vet-med_/nodes/LATEST?coalesce=&connection-sort=&url=';
        $this->assertSame($expected, $uri);
    }

    /**
     * A 401 triggers exactly one re-authentication and retry.
     */
    public function test_reauth_on_401(): void {
        [$client, $mock] = $this->make_client([
            $this->token_response('token-1'),
            new Response(401, [], ''),
            $this->token_response('token-2'),
            $this->json_response(['ok' => true]),
        ]);

        $result = $client->metadata('vet-med', 'LATEST');
        $this->assertSame(['ok' => true], $result);
        $this->assertSame(0, $mock->count());

        $retry = end($this->history)['request'];
        $this->assertSame('Bearer token-2', $retry->getHeaderLine('Authorization'));
    }

    /**
     * A second 401 after re-authentication surfaces as an HTTP error.
     */
    public function test_second_401_fails(): void {
        [$client] = $this->make_client([
            $this->token_response('token-1'),
            new Response(401, [], ''),
            $this->token_response('token-2'),
            new Response(401, [], ''),
        ]);

        $this->expectException(client_exception::class);
        $this->expectExceptionMessage('401');
        $client->metadata('vet-med', 'LATEST');
    }

    /**
     * Server errors throw and are always logged, even with debug mode off.
     */
    public function test_http_error_logged(): void {
        global $DB;
        [$client] = $this->make_client([
            $this->token_response(),
            new Response(500, [], 'boom'),
        ]);

        try {
            $client->metadata('vet-med', 'LATEST');
            $this->fail('client_exception expected');
        } catch (client_exception $exception) {
            $this->assertSame(500, $exception->httpcode);
        }

        $rows = $DB->get_records('local_curricmap_apilog', ['outcome' => 'error']);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(500, (int) $row->httpcode);
        $this->assertStringContainsString('/api/_vet-med_/metadata/LATEST', $row->url);
    }

    /**
     * Non-JSON success bodies are rejected.
     */
    public function test_invalid_json(): void {
        [$client] = $this->make_client([
            $this->token_response(),
            new Response(200, [], 'this is not json'),
        ]);

        $this->expectException(client_exception::class);
        $client->metadata('vet-med', 'LATEST');
    }

    /**
     * Once the remaining budget reaches the floor, further requests are refused
     * before being sent.
     */
    public function test_rate_floor_refusal(): void {
        [$client, $mock] = $this->make_client([
            $this->token_response(),
            $this->json_response(['a' => 1], 55, 60), // Remaining 5, default floor 10.
            $this->json_response(['never' => 'sent']),
        ]);

        $client->metadata('vet-med', 'LATEST');
        $this->assertSame(5, $client->remaining());

        try {
            $client->metadata('vet-med', 'LATEST');
            $this->fail('client_exception expected');
        } catch (client_exception $exception) {
            $this->assertSame('errorratefloor', $exception->errorcode);
        }
        $this->assertSame(1, $mock->count(), 'The refused request was never sent.');
    }

    /**
     * Rate headers are persisted to plugin config for admin visibility.
     */
    public function test_rate_headers_persisted(): void {
        [$client] = $this->make_client([
            $this->token_response(),
            $this->json_response(['a' => 1], 7, 60),
        ]);

        $client->metadata('vet-med', 'LATEST');
        $this->assertSame('7', get_config('local_curricmap', 'lastratecount'));
        $this->assertSame('60', get_config('local_curricmap', 'lastratelimit'));
        $this->assertNotEmpty(get_config('local_curricmap', 'lastrateseen'));
    }

    /**
     * Debug mode records successful requests with a preview; off records nothing;
     * token bodies are never stored.
     */
    public function test_debug_logging(): void {
        global $DB;

        // Debug off: success writes no rows.
        [$client] = $this->make_client([
            $this->token_response(),
            $this->json_response(['quiet' => true]),
        ]);
        $client->metadata('vet-med', 'LATEST');
        $this->assertSame(0, $DB->count_records('local_curricmap_apilog'));

        // Debug on: token + request rows, preview only on the API row.
        set_config('enabledebuglog', 1, 'local_curricmap');
        \cache::make('local_curricmap', 'token')->purge();
        [$client] = $this->make_client([
            $this->token_response(),
            $this->json_response(['loud' => true]),
        ]);
        $client->metadata('vet-med', 'LATEST');

        $rows = array_values($DB->get_records('local_curricmap_apilog', [], 'id ASC'));
        $this->assertCount(2, $rows);
        $this->assertSame('/o/token/', $rows[0]->url);
        $this->assertNull($rows[0]->responsepreview, 'Token bodies are never stored.');
        $this->assertStringContainsString('loud', $rows[1]->responsepreview);
        $this->assertSame('ok', $rows[1]->outcome);
    }

    /**
     * Unconfigured client refuses to make requests.
     */
    public function test_not_configured(): void {
        unset_config('sofia_clientsecret', 'local_curricmap');
        [$client, $mock] = $this->make_client([$this->token_response()]);

        $this->assertFalse($client->is_configured());
        $this->expectException(client_exception::class);
        try {
            $client->metadata('vet-med', 'LATEST');
        } finally {
            $this->assertSame(1, $mock->count(), 'No request was sent.');
        }
    }

    /**
     * Compare builds the two-reference path (also the hash-discovery mechanism).
     */
    public function test_compare_path(): void {
        [$client] = $this->make_client([
            $this->token_response(),
            $this->json_response(['meta' => ['compare' => ['from' => 'aaa', 'to' => 'aaa']]]),
        ]);

        $client->compare('vet-med', 'LATEST', 'LATEST');
        $uri = (string) end($this->history)['request']->getUri();
        $this->assertSame('https://sofia.example/api/_vet-med_/compare/LATEST/LATEST', $uri);
    }

    /**
     * API log cleanup respects the retention setting.
     */
    public function test_apilog_cleanup(): void {
        global $DB;
        set_config('apilogretention', 10, 'local_curricmap');

        $old = (object) ['timecreated' => time() - (11 * DAYSECS), 'method' => 'GET',
            'url' => '/old', 'elapsedms' => 1, 'outcome' => 'error'];
        $new = (object) ['timecreated' => time() - (2 * DAYSECS), 'method' => 'GET',
            'url' => '/new', 'elapsedms' => 1, 'outcome' => 'error'];
        $DB->insert_record('local_curricmap_apilog', $old);
        $DB->insert_record('local_curricmap_apilog', $new);

        $deleted = \local_curricmap\local\apilog::cleanup();
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $DB->count_records('local_curricmap_apilog'));
        $this->assertTrue($DB->record_exists('local_curricmap_apilog', ['url' => '/new']));
    }
}
