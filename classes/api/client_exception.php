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

/**
 * Exception thrown by the Sofia API client.
 *
 * @package   local_curricmap
 * @copyright 2026 The Royal Veterinary College
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_exception extends \moodle_exception {
    /** @var int|null HTTP status code of the failed request, if one was received. */
    public ?int $httpcode;

    /**
     * Constructor.
     *
     * @param string $errorcode Language string key within local_curricmap.
     * @param mixed $a Placeholder value(s) for the language string.
     * @param int|null $httpcode HTTP status code, if a response was received.
     * @param string|null $debuginfo Additional detail for logs (never shown to users).
     */
    public function __construct(string $errorcode, $a = null, ?int $httpcode = null, ?string $debuginfo = null) {
        $this->httpcode = $httpcode;
        parent::__construct($errorcode, 'local_curricmap', '', $a, $debuginfo);
    }
}
