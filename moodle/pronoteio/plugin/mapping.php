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
 * Associate a course with Pronote classes or groups.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use core\output\notification;
use local_pronoteio\connector\connector_interface;
use local_pronoteio\connector\factory;
use local_pronoteio\form\mapping_form;
use local_pronoteio\local\account_manager;
use local_pronoteio\local\student_matcher;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/pronoteio:managecourse', $context);

$url = new moodle_url('/local/pronoteio/mapping.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursemapping', 'local_pronoteio'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$account = account_manager::get_for_user((int) $USER->id);
if (!$account) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error_noaccount', 'local_pronoteio'), notification::NOTIFY_WARNING);
    echo $OUTPUT->single_button(new moodle_url('/local/pronoteio/account.php'), get_string('linkaccount', 'local_pronoteio'));
    echo $OUTPUT->footer();
    exit;
}

if (($deleteid = optional_param('delete', 0, PARAM_INT)) > 0) {
    require_sesskey();
    $DB->delete_records('local_pronoteio_map', ['id' => $deleteid, 'courseid' => $course->id]);
    redirect($url);
}

// Pronote resources of the teacher. On failure the form falls back to manual entry.
$resources = [];
$loaderror = '';
$connector = factory::for_account($account);
if ($connector->supports(connector_interface::FEATURE_ROSTER)) {
    try {
        $resources = $connector->get_resources();
    } catch (moodle_exception $e) {
        $loaderror = $e->getMessage();
    }
}
$byid = array_column($resources, null, 'id');

$form = new mapping_form($url, ['courseid' => $course->id, 'resources' => $resources]);
if ($data = $form->get_data()) {
    if ($resources) {
        $resource = $byid[$data->pronoteid] ?? null;
        if (!$resource) {
            throw new moodle_exception('invaliddata', 'error');
        }
    } else {
        $resource = ['id' => 'manual:' . core_text::strtolower($data->pronotename), 'name' => $data->pronotename,
            'type' => $data->type];
    }

    if (!$DB->record_exists('local_pronoteio_map', ['courseid' => $course->id, 'pronoteid' => $resource['id']])) {
        $now = time();
        $DB->insert_record('local_pronoteio_map', (object) [
            'courseid' => $course->id,
            'accountid' => $account->id,
            'pronoteid' => $resource['id'],
            'pronotename' => $resource['name'],
            'type' => $resource['type'] === 'group' ? 'group' : 'class',
            'groupid' => 0,
            'flags' => mapping_form::flags_from_data($data),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
    redirect($url);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursemapping', 'local_pronoteio'));
echo html_writer::tag('p', get_string('mappingintro', 'local_pronoteio'));

if ($loaderror !== '') {
    echo $OUTPUT->notification($loaderror, notification::NOTIFY_WARNING);
}

$mappings = $DB->get_records('local_pronoteio_map', ['courseid' => $course->id], 'pronotename ASC');
if ($mappings) {
    // Cohorts linked to the same Pronote classes, and whether the course already enrols them.
    $cohortlinks = $DB->get_records_sql(
        "SELECT pc.id, pc.pronoteid, pc.pronotename, c.id AS cohortid, c.name
           FROM {local_pronoteio_cohort} pc
           JOIN {cohort} c ON c.id = pc.cohortid");
    $enrolledcohorts = $DB->get_fieldset_select('enrol', 'customint1', "courseid = ? AND enrol = 'cohort'", [$course->id]);
    $canenrol = enrol_is_enabled('cohort') && has_capability('enrol/cohort:config', $context);

    $table = new html_table();
    $table->head = [get_string('pronoteresource', 'local_pronoteio'), get_string('type', 'local_pronoteio'),
        get_string('flows', 'local_pronoteio'), get_string('group'), get_string('cohort', 'cohort'), ''];
    foreach ($mappings as $mapping) {
        $flows = [];
        foreach (mapping_form::FLOW_FLAGS as $name => $flag) {
            if ($mapping->flags & $flag) {
                $flows[] = get_string($name, 'local_pronoteio');
            }
        }
        $cohortcell = [];
        foreach ($cohortlinks as $cohortlink) {
            if ($cohortlink->pronoteid !== $mapping->pronoteid
                    && student_matcher::normalise($cohortlink->pronotename) !== student_matcher::normalise($mapping->pronotename)) {
                continue;
            }
            $cell = format_string($cohortlink->name);
            if (in_array((string) $cohortlink->cohortid, $enrolledcohorts, true)) {
                $cell .= ' ' . html_writer::span(get_string('cohort_enrolled', 'local_pronoteio'), 'badge bg-success');
            } else if ($canenrol) {
                $cell .= ' ' . html_writer::link(new moodle_url('/enrol/editinstance.php',
                    ['type' => 'cohort', 'courseid' => $course->id]), get_string('cohort_addenrol', 'local_pronoteio'));
            }
            $cohortcell[] = $cell;
        }
        $deleteurl = new moodle_url($url, ['delete' => $mapping->id, 'sesskey' => sesskey()]);
        $table->data[] = [
            s($mapping->pronotename),
            get_string('type_' . $mapping->type, 'local_pronoteio'),
            implode(', ', $flows),
            $mapping->groupid ? format_string(groups_get_group_name($mapping->groupid)) : '-',
            $cohortcell ? implode('<br>', $cohortcell) : '-',
            $OUTPUT->action_icon($deleteurl, new pix_icon('t/delete', get_string('deletemapping', 'local_pronoteio')),
                new confirm_action(get_string('deletemapping', 'local_pronoteio') . ' ?')),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nomappings', 'local_pronoteio'), notification::NOTIFY_INFO);
}

$form->display();
echo $OUTPUT->footer();
