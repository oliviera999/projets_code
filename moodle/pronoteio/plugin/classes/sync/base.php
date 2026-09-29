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

namespace local_pronoteio\sync;

use local_pronoteio\connector\connector_interface;

/**
 * Base class of a Pronote -> Moodle synchronisation flow.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {

    /** @var int Mapping flag: timetable. */
    public const FLAG_TIMETABLE = 1;
    /** @var int Mapping flag: homework. */
    public const FLAG_HOMEWORK = 2;
    /** @var int Mapping flag: roster. */
    public const FLAG_ROSTER = 4;
    /** @var int Mapping flag: absences. */
    public const FLAG_ABSENCES = 8;
    /** @var int Mapping flag: grades. */
    public const FLAG_GRADES = 16;

    /**
     * Constructor.
     *
     * @param connector_interface $connector
     * @param \stdClass $account
     */
    public function __construct(
        /** @var connector_interface */
        protected connector_interface $connector,
        /** @var \stdClass */
        protected \stdClass $account,
    ) {
    }

    /**
     * Flow name used in logs.
     *
     * @return string
     */
    abstract public static function get_flow(): string;

    /**
     * Connector feature required (connector_interface::FEATURE_*).
     *
     * @return string
     */
    abstract public static function get_feature(): string;

    /**
     * Runs the flow.
     *
     * @return int Number of items processed.
     */
    abstract public function run(): int;

    /**
     * Mappings of the account having a flag enabled.
     *
     * @param int $flag
     * @return \stdClass[]
     */
    protected function get_mappings(int $flag): array {
        global $DB;
        return $DB->get_records_select('local_pronoteio_map', 'accountid = ? AND ' . $DB->sql_bitand('flags', $flag) . ' <> 0',
            [$this->account->id]);
    }

    /**
     * Synchronisation window [now, now + N days].
     *
     * @return int[] [from, to]
     */
    protected function get_window(): array {
        $days = max(1, (int) get_config('local_pronoteio', 'syncwindowdays'));
        $from = usergetmidnight(time());
        return [$from, $from + $days * DAYSECS];
    }

    /**
     * Course mapped to one of the Pronote group names of an item, or null.
     *
     * @param string[] $groupnames
     * @param \stdClass[] $mappings
     * @return \stdClass|null Mapping record.
     */
    protected function find_mapping(array $groupnames, array $mappings): ?\stdClass {
        $wanted = array_map([self::class, 'normalise'], $groupnames);
        foreach ($mappings as $mapping) {
            if (in_array(self::normalise($mapping->pronotename), $wanted, true)) {
                return $mapping;
            }
        }
        return null;
    }

    /**
     * Creates or updates a calendar event identified by a stable key.
     *
     * @param string $key Unique key for this account (Pronote id).
     * @param array $data name, description, timestart, timeduration, courseid (0 for a user event)
     * @return int Event id.
     */
    protected function upsert_event(string $key, array $data): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $uuid = 'pronoteio-' . $this->account->id . '-' . sha1($key);
        $record = (object) [
            'name' => \core_text::substr($data['name'], 0, 255),
            'description' => $data['description'] ?? '',
            'format' => FORMAT_HTML,
            'courseid' => $data['courseid'] ?? 0,
            'groupid' => 0,
            'userid' => $this->account->userid,
            'modulename' => '',
            'instance' => 0,
            'eventtype' => empty($data['courseid']) ? 'user' : 'course',
            'timestart' => $data['timestart'],
            'timeduration' => $data['timeduration'] ?? 0,
            'visible' => 1,
            'uuid' => $uuid,
            'component' => 'local_pronoteio',
            'type' => CALENDAR_EVENT_TYPE_STANDARD,
        ];

        if ($existing = $DB->get_record('event', ['uuid' => $uuid, 'component' => 'local_pronoteio'])) {
            $event = \calendar_event::load($existing->id);
            $record->id = $existing->id;
            $event->update($record, false);
            return (int) $existing->id;
        }
        return (int) \calendar_event::create($record, false)->id;
    }

    /**
     * Comparable form of a name: lower case, no accents, single spaces.
     *
     * @param string $value
     * @return string
     */
    public static function normalise(string $value): string {
        return \local_pronoteio\local\student_matcher::normalise($value);
    }
}
