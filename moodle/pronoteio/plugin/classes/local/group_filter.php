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

namespace local_pronoteio\local;

/**
 * Participant filter by group ("12") or grouping ("g5"), the values used by the Completion Progress overview.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_filter {

    /**
     * Choices: all participants, the groups, then the groupings that contain groups.
     *
     * @param int $courseid
     * @return array<string, string>
     */
    public static function options(int $courseid): array {
        $context = \context_course::instance($courseid);
        $options = ['0' => get_string('allparticipants')];
        foreach (groups_get_all_groups($courseid) as $group) {
            $options[(string) $group->id] = format_string($group->name, true, ['context' => $context]);
        }
        foreach (groups_get_all_groupings($courseid) as $grouping) {
            if (groups_get_all_groups($courseid, 0, $grouping->id)) {
                $options['g' . $grouping->id] = get_string('grouping', 'group') . ' : '
                    . format_string($grouping->name, true, ['context' => $context]);
            }
        }
        return $options;
    }

    /**
     * Group ids for get_enrolled_users(), 0 for all participants or an unknown value.
     *
     * @param int $courseid
     * @param string $value
     * @return int|int[]
     */
    public static function resolve(int $courseid, string $value): int|array {
        if (preg_match('/^g(\d+)$/', $value, $match)) {
            $ids = array_map('intval', array_keys(groups_get_all_groups($courseid, 0, (int) $match[1])));
            return $ids ?: 0;
        }
        if (ctype_digit($value) && (int) $value > 0) {
            $group = groups_get_group((int) $value);
            if ($group && (int) $group->courseid === $courseid) {
                return (int) $value;
            }
        }
        return 0;
    }

    /**
     * The value when it is one of the choices, '0' otherwise.
     *
     * @param int $courseid
     * @param string $value
     * @return string
     */
    public static function clean(int $courseid, string $value): string {
        return array_key_exists($value, self::options($courseid)) ? $value : '0';
    }
}
