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

namespace local_extsync;

use local_extsync\local\materials;

/**
 * Material download settings and URL checks.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_extsync\local\materials
 */
final class materials_test extends \advanced_testcase {
    /**
     * Assert that a URL is rejected with the given error.
     *
     * @param string $url the URL
     * @param string $errorcode expected language string
     */
    private function assert_rejected(string $url, string $errorcode): void {
        try {
            materials::check_url($url);
            $this->fail("$url was accepted");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * Only HTTPS URLs on allowed hosts pass; an empty list allows nothing.
     */
    public function test_check_url(): void {
        $this->resetAfterTest();

        $this->assert_rejected('https://files.example.com/a.pdf', 'hostnotallowed');

        set_config('allowedhosts', "files.example.com\n*.cdn.example.net\n192.0.2.10", 'local_extsync');
        materials::check_url('https://files.example.com/a.pdf');
        materials::check_url('https://FILES.example.com/a.pdf');
        materials::check_url('https://eu.cdn.example.net/a.pdf');
        materials::check_url('https://192.0.2.10/a.pdf');

        $this->assert_rejected('http://files.example.com/a.pdf', 'httpsrequired');
        $this->assert_rejected('ftp://files.example.com/a.pdf', 'httpsrequired');
        $this->assert_rejected('https://other.example.com/a.pdf', 'hostnotallowed');
        $this->assert_rejected('https://files.example.com.evil.test/a.pdf', 'hostnotallowed');
        $this->assert_rejected('https://127.0.0.1/a.pdf', 'hostnotallowed');
        $this->assert_rejected('https://169.254.169.254/latest/meta-data', 'hostnotallowed');
    }

    /**
     * The size setting falls back to the default when empty or invalid.
     */
    public function test_max_bytes(): void {
        $this->resetAfterTest();

        $this->assertSame(materials::DEFAULT_MAX_MB * 1048576, materials::max_bytes());
        set_config('maxmaterialsize', 0, 'local_extsync');
        $this->assertSame(materials::DEFAULT_MAX_MB * 1048576, materials::max_bytes());
        set_config('maxmaterialsize', -5, 'local_extsync');
        $this->assertSame(materials::DEFAULT_MAX_MB * 1048576, materials::max_bytes());
        set_config('maxmaterialsize', 3, 'local_extsync');
        $this->assertSame(3 * 1048576, materials::max_bytes());
    }

    /**
     * Redirects are followed only to allowed hosts, and oversized files are refused.
     */
    public function test_redirects_and_size(): void {
        $this->resetAfterTest();
        set_config('allowedhosts', 'files.example.com', 'local_extsync');
        set_config('maxmaterialsize', 1, 'local_extsync');

        $responses = [
            'https://files.example.com/moved' => [302, '/real.pdf', ''],
            'https://files.example.com/real.pdf' => [200, '', '%PDF-1.4'],
            'https://files.example.com/away' => [302, 'https://internal.example.org/secret', ''],
            'https://files.example.com/big' => [200, '', str_repeat('x', 1048577)],
            'https://files.example.com/missing' => [404, '', 'not found'],
        ];
        $materials = new class ($responses) extends materials {
            /** @var array fake responses by URL */
            private array $responses;

            /**
             * Constructor.
             *
             * @param array $responses fake responses by URL
             */
            public function __construct(array $responses) {
                $this->responses = $responses;
            }

            /**
             * Serve a fake response instead of using the network.
             *
             * @param string $url URL
             * @param string $path target file
             * @param int $maxbytes size limit
             * @return array
             */
            protected function fetch(string $url, string $path, int $maxbytes): array {
                [$status, $location, $body] = $this->responses[$url];
                file_put_contents($path, $body);
                return [$status, $location];
            }
        };

        $path = make_request_directory() . '/file';
        $materials->download('https://files.example.com/moved', $path);
        $this->assertSame('%PDF-1.4', file_get_contents($path));

        foreach (['away' => 'hostnotallowed', 'big' => 'downloadtoolarge', 'missing' => 'downloadfailed'] as $name => $code) {
            try {
                $materials->download('https://files.example.com/' . $name, $path);
                $this->fail("$name was downloaded");
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }
    }
}
