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
 * Progress of a Completion Progress block to Pronote: import file or direct push, by group.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

use core\output\notification;
use local_pronoteio\connector\connector_interface;
use local_pronoteio\connector\factory;
use local_pronoteio\form\progress_export_form;
use local_pronoteio\local\account_manager;
use local_pronoteio\local\grade_converter;
use local_pronoteio\local\group_filter;
use local_pronoteio\local\progress_source;
use local_pronoteio\sync\grades;

$courseid = required_param('courseid', PARAM_INT);
$instanceid = required_param('instanceid', PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/pronoteio:exportgrades', $context);
$progress = progress_source::from_instance($course, $instanceid);
if (!$progress) {
    throw new moodle_exception('invaliddata', 'error');
}
require_capability('block/completion_progress:overview', $progress->get_context());

$group = group_filter::clean($course->id, optional_param('group', '0', PARAM_ALPHANUMEXT));
$groups = group_filter::options($course->id);
$groupids = group_filter::resolve($course->id, $group);

$url = new moodle_url('/local/pronoteio/progress.php', ['courseid' => $course->id, 'instanceid' => $instanceid, 'group' => $group]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('progressexport', 'local_pronoteio'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
$PAGE->navbar->add(get_string('overview', 'block_completion_progress'), $progress->get_overview_url(['group' => $group]));
$PAGE->navbar->add(get_string('progressexport', 'local_pronoteio'));

$config = get_config('local_pronoteio');
$statusnograde = $config->grade_status_nograde ?? grade_converter::STATUS_SKIP;
$form = new progress_export_form($url, ['courseid' => $course->id, 'instanceid' => $instanceid, 'group' => $group]);

if ($data = $form->get_data()) {
    $converter = new grade_converter((float) $data->scale, (float) $data->rounding, $statusnograde);
    $title = trim((string) $data->title) ?: $progress->get_name();
    $filename = preg_replace('/\s+/', ' ', clean_filename('pronote_' . $course->shortname . '_' . $title
        . ($group !== '0' ? '_' . $groups[$group] : '') . '.txt'));
    send_file(grades::build_file($progress, $groupids, $converter, $title), $filename, 0, 0, true, true,
        'text/plain; charset=utf-16le');
}
$form->set_data([
    'title' => $progress->get_name(),
    'scale' => progress_source::DEFAULT_SCALE,
    'rounding' => (string) ($config->grade_rounding ?? '0.01'),
]);

$account = account_manager::get_for_user((int) $USER->id);
$canpush = $account && factory::for_account($account)->supports(connector_interface::FEATURE_GRADES_WRITE);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('progressexport', 'local_pronoteio') . ' : ' . s($progress->get_name()));
echo html_writer::tag('p', get_string('progressexport_intro', 'local_pronoteio', (object) [
    'scale' => format_float(progress_source::DEFAULT_SCALE, 0),
    'coefficient' => format_float(progress_source::DEFAULT_COEFFICIENT, 1),
]));

echo $OUTPUT->single_select($url, 'group', $groups, $group, null, null,
    ['label' => get_string('groupsgroupings', 'group')]);

if ($canpush) {
    echo $OUTPUT->box_start('generalbox my-4');
    echo $OUTPUT->heading(get_string('pushgrades', 'local_pronoteio'), 3);
    echo html_writer::tag('p', get_string('progressexport_push', 'local_pronoteio', format_float(
        progress_source::DEFAULT_COEFFICIENT, 1)));
    echo $OUTPUT->single_button(new moodle_url('/local/pronoteio/push.php',
        ['courseid' => $course->id, 'instanceid' => $instanceid, 'group' => $group]),
        get_string('pushgrades', 'local_pronoteio'), 'get', ['type' => 'primary']);
    echo $OUTPUT->box_end();
} else {
    echo $OUTPUT->notification(get_string('progressexport_nopush', 'local_pronoteio'), notification::NOTIFY_INFO);
}

echo $OUTPUT->heading(get_string('exportcsv', 'local_pronoteio'), 3);
echo html_writer::tag('p', get_string('progressexport_file', 'local_pronoteio'));
$form->display();

$rows = $progress->collect($groupids);
$preview = new grade_converter(progress_source::DEFAULT_SCALE, (float) ($config->grade_rounding ?? 0.01), $statusnograde);
echo $OUTPUT->heading(get_string('progressexport_preview', 'local_pronoteio',
    format_float(progress_source::DEFAULT_SCALE, 0)), 3);
if (!$rows) {
    echo $OUTPUT->notification(get_string('progressexport_nostudents', 'local_pronoteio'), notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('student', 'local_pronoteio'),
        get_string('progress', 'block_completion_progress'),
        get_string('push_value', 'local_pronoteio'),
    ];
    foreach ($rows as $row) {
        $cell = $preview->convert($row['finalgrade'], $progress->get_min(), $progress->get_max());
        if ($cell === null) {
            $value = '-';
        } else if ($cell['status'] !== '') {
            $value = get_string('gradestatus_' . $cell['status'], 'local_pronoteio');
        } else {
            $value = format_float($cell['value'], 2, true, true);
        }
        $table->data[] = [
            s($row['fullname']),
            $row['finalgrade'] === null ? get_string('indeterminate', 'block_completion_progress')
                : get_string('percents', '', (int) $row['finalgrade']),
            $value,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
