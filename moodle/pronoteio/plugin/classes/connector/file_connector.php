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

namespace local_pronoteio\connector;

/**
 * Official fallback: timetable from the Pronote iCal export, grades through a CSV file (see export\pronote_csv).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_connector implements connector_interface {

    /** @var \stdClass Linked account. */
    protected \stdClass $account;

    /**
     * Constructor.
     *
     * @param \stdClass $account
     */
    public function __construct(\stdClass $account) {
        $this->account = $account;
    }

    #[\Override]
    public function get_name(): string {
        return 'file';
    }

    #[\Override]
    public function supports(string $feature): bool {
        return $feature === self::FEATURE_TIMETABLE && !empty($this->account->icalurl);
    }

    #[\Override]
    public function login(string $password = '', string $pin = ''): array {
        return ['displayname' => $this->account->username];
    }

    #[\Override]
    public function get_timetable(int $from, int $to): array {
        if (!$this->supports(self::FEATURE_TIMETABLE)) {
            throw new connector_exception('error_notsupported', $this->get_name());
        }
        $client = new \core\http_client(['timeout' => 20]);
        try {
            $ics = (string) $client->get($this->account->icalurl)->getBody();
        } catch (\Throwable $e) {
            throw new connector_exception('error_sidecar', $e->getMessage());
        }

        $lessons = [];
        foreach (self::parse_ical($ics) as $event) {
            if ($event['end'] < $from || $event['start'] > $to) {
                continue;
            }
            $lessons[] = [
                'id' => $event['uid'],
                'subject' => $event['summary'],
                'teacher' => '',
                'rooms' => $event['location'] === '' ? [] : [$event['location']],
                'groups' => [],
                'start' => $event['start'],
                'end' => $event['end'],
                'cancelled' => $event['status'] === 'CANCELLED',
                'status' => $event['status'],
            ];
        }
        return $lessons;
    }

    /**
     * Minimal VEVENT parser (UID, SUMMARY, LOCATION, STATUS, DTSTART, DTEND).
     *
     * @param string $ics
     * @return array[]
     */
    public static function parse_ical(string $ics): array {
        // Unfold continuation lines (RFC 5545 section 3.1).
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics);
        $events = [];
        $current = null;
        foreach (preg_split("/\r?\n/", $ics) as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = ['uid' => '', 'summary' => '', 'location' => '', 'status' => '', 'start' => 0, 'end' => 0];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($current !== null && $current['start'] > 0) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current === null || !str_contains($line, ':')) {
                continue;
            }
            [$prop, $value] = explode(':', $line, 2);
            [$name] = explode(';', $prop, 2);
            $value = str_replace(['\\n', '\\,', '\\;', '\\\\'], ["\n", ',', ';', '\\'], $value);
            match (strtoupper($name)) {
                'UID' => $current['uid'] = $value,
                'SUMMARY' => $current['summary'] = $value,
                'LOCATION' => $current['location'] = $value,
                'STATUS' => $current['status'] = strtoupper($value),
                'DTSTART' => $current['start'] = self::parse_ical_date($prop, $value),
                'DTEND' => $current['end'] = self::parse_ical_date($prop, $value),
                default => null,
            };
        }
        return $events;
    }

    /**
     * Converts an iCal date to a timestamp, honouring TZID and the UTC suffix.
     *
     * @param string $prop Full property with parameters.
     * @param string $value
     * @return int
     */
    protected static function parse_ical_date(string $prop, string $value): int {
        $tz = \core_date::get_server_timezone_object();
        if (preg_match('/TZID=([^;:]+)/', $prop, $m)) {
            try {
                $tz = new \DateTimeZone($m[1]);
            } catch (\Exception $e) {
                // Unknown TZID: keep the server timezone.
            }
        }
        if (str_ends_with($value, 'Z')) {
            $tz = new \DateTimeZone('UTC');
            $value = substr($value, 0, -1);
        }
        $format = strlen($value) === 8 ? '!Ymd' : 'Ymd\THis';
        $date = \DateTime::createFromFormat($format, $value, $tz);
        return $date ? $date->getTimestamp() : 0;
    }

    #[\Override]
    public function get_homework(int $from, int $to): array {
        throw new connector_exception('error_notsupported', $this->get_name());
    }

    #[\Override]
    public function get_resources(): array {
        throw new connector_exception('error_notsupported', $this->get_name());
    }

    #[\Override]
    public function get_roster(string $resourceid): array {
        throw new connector_exception('error_notsupported', $this->get_name());
    }

    #[\Override]
    public function get_absences(string $resourceid, int $from, int $to): array {
        throw new connector_exception('error_notsupported', $this->get_name());
    }

    #[\Override]
    public function get_grades(string $resourceid): array {
        throw new connector_exception('error_notsupported', $this->get_name());
    }

    #[\Override]
    public function get_grade_context(): array {
        throw new connector_exception('error_notsupported', $this->get_name());
    }

    #[\Override]
    public function push_grades(string $serviceid, string $periodid, array $assessment, array $grades,
            bool $dryrun = false): array {
        throw new connector_exception('error_notsupported', $this->get_name());
    }
}
