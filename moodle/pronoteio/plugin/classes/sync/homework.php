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
 * Pronote homework ("travail à faire") -> course events in mapped courses.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class homework extends base {

    #[\Override]
    public static function get_flow(): string {
        return 'homework';
    }

    #[\Override]
    public static function get_feature(): string {
        return connector_interface::FEATURE_HOMEWORK;
    }

    #[\Override]
    public function run(): int {
        $mappings = $this->get_mappings(self::FLAG_HOMEWORK);
        if (!$mappings) {
            return 0;
        }
        [$from, $to] = $this->get_window();
        $count = 0;

        foreach ($this->connector->get_homework($from, $to) as $item) {
            $mapping = $this->find_mapping($item['groups'] ?? [], $mappings);
            if (!$mapping) {
                continue;
            }
            $description = clean_text($item['description'] ?? '', FORMAT_HTML);
            // TODO: import attachments into the event or a course resource instead of linking to Pronote.
            foreach ($item['attachments'] ?? [] as $attachment) {
                $description .= \html_writer::div(\html_writer::link($attachment['url'], s($attachment['name'])));
            }

            $this->upsert_event('homework:' . $item['id'], [
                'name' => get_string('homeworkevent', 'local_pronoteio', $item['subject']),
                'description' => $description,
                'timestart' => (int) $item['due'],
                'courseid' => (int) $mapping->courseid,
            ]);
            $count++;
        }
        return $count;
    }
}
