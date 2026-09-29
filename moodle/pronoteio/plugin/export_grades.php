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
 * Export the grades of a course to Pronote as an import file; entry point of the direct push.
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
use local_pronoteio\form\export_form;
use local_pronoteio\local\account_manager;
use local_pronoteio\local\grade_converter;
use local_pronoteio\local\group_filter;
use local_pronoteio\local\item_source;
use local_pronoteio\local\progress_source;
use local_pronoteio\sync\grades;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/pronoteio:exportgrades', $context);

$url = new moodle_url('/local/pronoteio/export_grades.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('exportgrades', 'local_pronoteio'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$items = grades::items($course->id);

$form = new export_form($url, ['courseid' => $course->id, 'items' => $items, 'groups' => group_filter::options($course->id)]);

if ($items && ($data = $form->get_data())) {
    if (!isset($items[$data->itemid])) {
        throw new moodle_exception('invaliddata', 'error');
    }
    $item = grade_item::fetch(['id' => $data->itemid, 'courseid' => $course->id]);
    $groupids = group_filter::resolve($course->id, (string) $data->groupid);
    $filename = clean_filename('pronote_' . $course->shortname . '_' . $item->get_name() . '.txt');
    send_file(grades::build_file(new item_source($item), $groupids, grade_converter::from_settings($item)), $filename,
        0, 0, true, true, 'text/plain; charset=utf-16le');
}

$account = account_manager::get_for_user((int) $USER->id);
$canpush = $account && factory::for_account($account)->supports(connector_interface::FEATURE_GRADES_WRITE);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('exportgrades', 'local_pronoteio'));

$progresses = progress_source::for_course($course);
if ($progresses) {
    echo $OUTPUT->box_start('generalbox mb-4');
    echo $OUTPUT->heading(get_string('progressexport', 'local_pronoteio'), 3);
    $links = array_map(fn(progress_source $p) => html_writer::link(new moodle_url('/local/pronoteio/progress.php',
        ['courseid' => $course->id, 'instanceid' => $p->get_instanceid()]), s($p->get_name())), $progresses);
    echo html_writer::alist($links);
    echo $OUTPUT->box_end();
}

if (!$items) {
    echo $OUTPUT->notification(get_string('nogradeitems', 'local_pronoteio'), notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    die();
}

if ($canpush) {
    echo $OUTPUT->box_start('generalbox mb-4');
    echo $OUTPUT->heading(get_string('pushgrades', 'local_pronoteio'), 3);
    echo html_writer::tag('p', get_string('pushgrades_intro', 'local_pronoteio'));
    echo $OUTPUT->single_button(new moodle_url('/local/pronoteio/push.php', ['courseid' => $course->id]),
        get_string('pushgrades', 'local_pronoteio'), 'get', ['type' => 'primary']);
    echo $OUTPUT->box_end();
}

echo $OUTPUT->heading(get_string('exportcsv', 'local_pronoteio'), 3);
echo html_writer::tag('p', get_string('exportgrades_intro', 'local_pronoteio'));
$form->display();
echo $OUTPUT->footer();
