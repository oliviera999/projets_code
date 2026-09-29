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

/**
 * Library callbacks for local_pronoteio.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the Pronote pages to the course "More" menu.
 *
 * No hook replaces this callback yet in Moodle 5.2.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_pronoteio_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    if (has_capability('local/pronoteio:managecourse', $context)) {
        $navigation->add(
            get_string('coursemapping', 'local_pronoteio'),
            new moodle_url('/local/pronoteio/mapping.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_pronoteio_mapping',
            new pix_icon('i/settings', '')
        );
    }
    if (has_capability('local/pronoteio:exportgrades', $context)) {
        $navigation->add(
            get_string('exportgrades', 'local_pronoteio'),
            new moodle_url('/local/pronoteio/export_grades.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_pronoteio_exportgrades',
            new pix_icon('i/export', '')
        );
    }
}
