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

namespace local_pronoteio;

use local_pronoteio\local\account_manager;

/**
 * Hook callbacks.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Adds "Pronote" to the primary navigation for teachers.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function extend_primary_navigation(\core\hook\navigation\primary_extend $hook): void {
        global $USER;

        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return;
        }
        // The capability lookup scans the user's courses: cache the answer for the session.
        $cachekey = 'local_pronoteio_canlink';
        if (!isset($USER->$cachekey)) {
            $USER->$cachekey = account_manager::can_link((int) $USER->id);
        }
        if (!$USER->$cachekey) {
            return;
        }

        $hook->get_primaryview()->add(
            get_string('pronote', 'local_pronoteio'),
            new \moodle_url('/local/pronoteio/account.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_pronoteio'
        );
    }

    /**
     * Adds "Export to Pronote" to the overview page of the Completion Progress block, next to its group filter.
     *
     * The block is a third-party plugin: the button is added here rather than in the block.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function add_progress_export_button(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE;

        if (during_initial_install() || !isloggedin() || isguestuser()
                || $PAGE->pagetype !== 'blocks-completion_progress-overview' || !$PAGE->has_set_url()) {
            return;
        }
        $course = $PAGE->course;
        $instanceid = (int) $PAGE->url->param('instanceid');
        if (empty($course->id) || $course->id == SITEID
                || !has_capability('local/pronoteio:exportgrades', \context_course::instance($course->id))) {
            return;
        }
        $progress = local\progress_source::from_instance($course, $instanceid);
        if (!$progress || !has_capability('block/completion_progress:overview', $progress->get_context())) {
            return;
        }

        $url = new \moodle_url('/local/pronoteio/progress.php', [
            'courseid' => $course->id,
            'instanceid' => $instanceid,
            'group' => (string) ($PAGE->url->param('group') ?? '0'),
        ]);
        $id = 'local-pronoteio-progress-export';
        $hook->add_html(\html_writer::div(
            \html_writer::link($url, get_string('progressexport', 'local_pronoteio'), ['class' => 'btn btn-primary']),
            'mb-3',
            ['id' => $id]
        ));
        $PAGE->requires->js_amd_inline("
            const button = document.getElementById('{$id}');
            const menus = document.querySelector('.block_completion_progress .progressoverviewmenus');
            if (button && menus) {
                button.classList.remove('mb-3');
                button.classList.add('d-inline-block', 'align-bottom', 'ms-2');
                menus.appendChild(button);
            }
        ");
    }
}
