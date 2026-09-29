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
 * Pronote timetable -> Moodle calendar.
 *
 * Lessons of a mapped class/group become course events, the others user events of the teacher.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class timetable extends base {

    #[\Override]
    public static function get_flow(): string {
        return 'timetable';
    }

    #[\Override]
    public static function get_feature(): string {
        return connector_interface::FEATURE_TIMETABLE;
    }

    #[\Override]
    public function run(): int {
        [$from, $to] = $this->get_window();
        $mappings = $this->get_mappings(self::FLAG_TIMETABLE);
        $count = 0;

        foreach ($this->connector->get_timetable($from, $to) as $lesson) {
            $mapping = $this->find_mapping($lesson['groups'] ?? [], $mappings);
            $name = $lesson['subject'];
            if (!empty($lesson['cancelled'])) {
                $name = get_string('lessoncancelled', 'local_pronoteio', $name);
            }
            $description = implode(' - ', array_filter([
                implode(', ', $lesson['groups'] ?? []),
                implode(', ', $lesson['rooms'] ?? []),
            ]));

            $this->upsert_event('lesson:' . $lesson['id'], [
                'name' => $name,
                'description' => s($description),
                'timestart' => (int) $lesson['start'],
                'timeduration' => max(0, (int) $lesson['end'] - (int) $lesson['start']),
                'courseid' => $mapping ? (int) $mapping->courseid : 0,
            ]);
            $count++;
        }

        // TODO: delete events of the window that no longer exist in Pronote (lesson moved or removed).
        return $count;
    }
}
