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

namespace local_extsync\local;

use moodle_exception;

/**
 * Downloads lesson material files from allowed hosts.
 *
 * Security model: a URL is only fetched when it is HTTPS and its host is in the administrator's
 * allowlist. Redirects are followed by this class, one hop at a time, and every target is checked
 * against the same allowlist. All requests go through Moodle's curl class, so the site-wide
 * curlsecurityblockedhosts and curlsecurityallowedport rules apply as well. Files are streamed to
 * disk and aborted as soon as they exceed the configured size.
 *
 * Obtain an instance through \core\di so tests can replace the network access.
 *
 * @package    local_extsync
 * @copyright  2026 Arigun Jamsran
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class materials {
    /** @var int Default maximum material size in megabytes. */
    public const DEFAULT_MAX_MB = 50;

    /** @var int Redirects followed for one file. */
    public const MAX_REDIRECTS = 3;

    /**
     * Allowed hosts from the plugin settings.
     *
     * @return string[]
     */
    public static function allowed_hosts(): array {
        $setting = (string)get_config('local_extsync', 'allowedhosts');

        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $setting))));
    }

    /**
     * Maximum material size in bytes.
     *
     * @return int
     */
    public static function max_bytes(): int {
        $mb = (int)get_config('local_extsync', 'maxmaterialsize');

        return ($mb > 0 ? $mb : self::DEFAULT_MAX_MB) * 1024 * 1024;
    }

    /**
     * Throw unless the URL is HTTPS on an allowed host.
     *
     * @param string $url the URL
     * @throws moodle_exception
     */
    public static function check_url(string $url): void {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
            throw new moodle_exception('httpsrequired', 'local_extsync', '', $url);
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $allowed = self::allowed_hosts();
        if (\core\ip_utils::is_ip_address($host)) {
            $ok = $allowed && address_in_subnet($host, implode(',', $allowed));
        } else {
            $ok = \core\ip_utils::is_domain_in_allowed_list($host, $allowed);
        }
        if (!$ok) {
            throw new moodle_exception('hostnotallowed', 'local_extsync', '', $host);
        }
    }

    /**
     * Download every file into a per-request directory.
     *
     * Either all files are downloaded or an exception is thrown, so a caller never replaces
     * existing materials with an incomplete set.
     *
     * @param array $files items with 'url', 'name' and 'description'
     * @return array items with 'file' (the input item) and 'path' (the downloaded file)
     * @throws moodle_exception naming the first file that failed
     */
    public function download_all(array $files): array {
        $dir = make_request_directory();
        $downloaded = [];
        foreach (array_values($files) as $index => $file) {
            $path = $dir . '/material' . $index;
            $this->download($file['url'], $path);
            $downloaded[] = ['file' => $file, 'path' => $path];
        }

        return $downloaded;
    }

    /**
     * Download one URL to a file, following allowed redirects.
     *
     * @param string $url the URL
     * @param string $path target file
     * @throws moodle_exception
     */
    public function download(string $url, string $path): void {
        $maxbytes = self::max_bytes();

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            self::check_url($url);
            [$status, $location] = $this->fetch($url, $path, $maxbytes);

            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = self::resolve_location($url, $location);
                continue;
            }
            if ($status !== 200) {
                throw new moodle_exception('downloadfailed', 'local_extsync', '', $url);
            }

            $size = (int)filesize($path);
            if ($size === 0) {
                throw new moodle_exception('downloadfailed', 'local_extsync', '', $url);
            }
            if ($size > $maxbytes) {
                throw new moodle_exception('downloadtoolarge', 'local_extsync', '', $url);
            }

            return;
        }

        throw new moodle_exception('downloadredirect', 'local_extsync', '', $url);
    }

    /**
     * Perform one HTTP GET without following redirects.
     *
     * @param string $url the URL, already checked
     * @param string $path file to write the body to
     * @param int $maxbytes abort once the body is larger than this
     * @return array [HTTP status, Location header or '']
     * @throws moodle_exception
     */
    protected function fetch(string $url, string $path, int $maxbytes): array {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new moodle_exception('downloadfailed', 'local_extsync', '', $url);
        }

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_NOPROGRESS' => 0,
            'CURLOPT_PROGRESSFUNCTION' => function ($ch, $total, $now) use ($maxbytes) {
                return ($total > $maxbytes || $now > $maxbytes) ? 1 : 0;
            },
        ]);
        $result = $curl->download_one($url, null, [
            'file' => $handle,
            'followlocation' => 0,
            'timeout' => 300,
            'connecttimeout' => 20,
        ]);
        fclose($handle);

        if ($curl->get_errno() == CURLE_ABORTED_BY_CALLBACK) {
            throw new moodle_exception('downloadtoolarge', 'local_extsync', '', $url);
        }
        if ($result !== true) {
            throw new moodle_exception('downloadfailed', 'local_extsync', '', $url);
        }

        $location = '';
        foreach ((array)$curl->getResponse() as $name => $value) {
            if (strtolower($name) === 'location') {
                $location = trim(is_array($value) ? end($value) : $value);
            }
        }

        return [(int)($curl->get_info()['http_code'] ?? 0), $location];
    }

    /**
     * Turn a Location header into an absolute URL.
     *
     * @param string $current the URL that answered with the redirect
     * @param string $location the Location header
     * @return string
     */
    protected static function resolve_location(string $current, string $location): string {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location)) {
            return $location;
        }
        $parts = parse_url($current);
        $origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strpos($location, '//') === 0) {
            return 'https:' . $location;
        }
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $dir = isset($parts['path']) ? rtrim(dirname($parts['path']), '/\\') : '';

        return $origin . $dir . '/' . $location;
    }
}
