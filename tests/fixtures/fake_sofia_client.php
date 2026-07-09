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

namespace local_curricmap\local;

/**
 * Test double for the Sofia client: serves recorded payloads, no HTTP at all.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_sofia_client extends \local_curricmap\api\client {
    /** @var array Nodes payload to serve. */
    private array $nodespayload;

    /** @var array Metadata payload to serve. */
    private array $metadatapayload;

    /** @var string Revision hash to report from compare(). */
    private string $hash;

    /** @var int Simulated request counter. */
    private int $requests = 0;

    /** @var array|null Version labels that exist; null means every reference resolves. */
    private ?array $versions;

    /**
     * Constructor.
     *
     * @param array $nodespayload Nodes payload.
     * @param array $metadatapayload Metadata payload.
     * @param string $hash Revision hash for compare().
     * @param array|null $versions Existing version labels; null = all resolve.
     */
    public function __construct(array $nodespayload, array $metadatapayload, string $hash, ?array $versions = null) {
        parent::__construct();
        $this->nodespayload = $nodespayload;
        $this->metadatapayload = $metadatapayload;
        $this->hash = $hash;
        $this->versions = $versions;
    }

    /**
     * Always claims to be configured.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return true;
    }

    /**
     * Serve the compare meta with the configured hash on both sides.
     *
     * @param string $slug Ignored.
     * @param string $from Ignored.
     * @param string $to Ignored.
     * @return array
     */
    public function compare(string $slug, string $from, string $to): array {
        $this->requests++;
        if ($this->versions !== null && !in_array($to, $this->versions, true)) {
            throw new \local_curricmap\api\client_exception('errorhttp', (object) ['url' => $to, 'code' => 404], 404);
        }
        $meta = ['removed' => 0, 'added' => 0, 'modified' => 0, 'compare' => ['from' => $this->hash, 'to' => $this->hash]];
        return ['meta' => $meta, 'changes' => []];
    }

    /**
     * Serve the recorded nodes payload.
     *
     * @param string $slug Ignored.
     * @param string $version Ignored.
     * @param string|null $subtreeuuid Ignored.
     * @param array|null $options Ignored.
     * @return array
     */
    public function nodes(string $slug, string $version, ?string $subtreeuuid = null, ?array $options = null): array {
        $this->requests++;
        return $this->nodespayload;
    }

    /**
     * Serve the recorded metadata payload.
     *
     * @param string $slug Ignored.
     * @param string $version Ignored.
     * @return array
     */
    public function metadata(string $slug, string $version): array {
        $this->requests++;
        return $this->metadatapayload;
    }

    /**
     * Simulated remaining budget.
     *
     * @return int|null
     */
    public function remaining(): ?int {
        return 42;
    }

    /**
     * Simulated request count.
     *
     * @return int
     */
    public function request_count(): int {
        return $this->requests;
    }
}
