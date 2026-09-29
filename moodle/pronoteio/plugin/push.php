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
 * Direct push of a grade item, or of the progress of a Completion Progress block, to Pronote: options,
 * preview, then simulation or real send.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

use core\output\notification;
use local_pronoteio\connector\connector_exception;
use local_pronoteio\connector\connector_interface;
use local_pronoteio\connector\factory;
use local_pronoteio\form\push_form;
use local_pronoteio\local\account_manager;
use local_pronoteio\local\group_filter;
use local_pronoteio\local\item_source;
use local_pronoteio\local\progress_source;
use local_pronoteio\sync\base;
use local_pronoteio\sync\grades;

$courseid = required_param('courseid', PARAM_INT);
$instanceid = optional_param('instanceid', 0, PARAM_INT);
$token = optional_param('token', '', PARAM_ALPHANUM);
$action = optional_param('action', '', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/pronoteio:exportgrades', $context);

$progress = null;
if ($instanceid) {
    $progress = progress_source::from_instance($course, $instanceid);
    if (!$progress) {
        throw new moodle_exception('invaliddata', 'error');
    }
    require_capability('block/completion_progress:overview', $progress->get_context());
}

$url = new moodle_url('/local/pronoteio/push.php', ['courseid' => $course->id] + ($progress ? ['instanceid' => $instanceid] : []));
$exporturl = $progress
    ? new moodle_url('/local/pronoteio/progress.php', ['courseid' => $course->id, 'instanceid' => $instanceid])
    : new moodle_url('/local/pronoteio/export_grades.php', ['courseid' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pushgrades', 'local_pronoteio'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

/**
 * Prints an error page and stops.
 *
 * @param string $message
 * @param moodle_url $back
 */
function local_pronoteio_push_fail(string $message, moodle_url $back): void {
    global $OUTPUT;
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pushgrades', 'local_pronoteio'));
    echo $OUTPUT->notification($message, notification::NOTIFY_ERROR);
    echo $OUTPUT->continue_button($back);
    echo $OUTPUT->footer();
    die();
}

$account = account_manager::get_for_user((int) $USER->id);
if (!$account) {
    local_pronoteio_push_fail(get_string('error_noaccount', 'local_pronoteio'),
        new moodle_url('/local/pronoteio/account.php'));
}
$connector = factory::for_account($account);
if (!$connector->supports(connector_interface::FEATURE_GRADES_WRITE)) {
    local_pronoteio_push_fail(get_string('error_notsupported', 'local_pronoteio', $connector->get_name()), $exporturl);
}

$items = $progress ? [] : grades::items($course->id);
if (!$progress && !$items) {
    local_pronoteio_push_fail(get_string('nogradeitems', 'local_pronoteio'), $exporturl);
}

try {
    $gradecontext = $connector->get_grade_context();
} catch (connector_exception $e) {
    local_pronoteio_push_fail($e->getMessage(), $exporturl);
}

// Services of the classes mapped to this course come first.
$mappedresources = $DB->get_fieldset_select('local_pronoteio_map', 'pronoteid',
    'courseid = ? AND accountid = ? AND ' . $DB->sql_bitand('flags', base::FLAG_GRADES) . ' <> 0',
    [$course->id, $account->id]);
$servicesbyid = [];
foreach ($gradecontext['services'] as $service) {
    $servicesbyid[(string) $service['id']] = $service;
}
uasort($servicesbyid, function(array $a, array $b) use ($mappedresources): int {
    $ma = in_array((string) $a['resourceid'], $mappedresources, true) ? 0 : 1;
    $mb = in_array((string) $b['resourceid'], $mappedresources, true) ? 0 : 1;
    return [$ma, $a['name']] <=> [$mb, $b['name']];
});
$periods = [];
$currentperiod = null;
foreach ($gradecontext['periods'] as $period) {
    $periods[(string) $period['id']] = $period['name'];
    if (!empty($period['current'])) {
        $currentperiod = (string) $period['id'];
    }
}

// A service may be graded in some periods only (a group graded by semester): the periods are shown
// when the service is not graded in the current period. Older sidecars do not send them.
$services = [];
$serviceperiods = [];
foreach ($servicesbyid as $id => $service) {
    $active = array_values(array_filter(array_map('strval', $service['periods'] ?? []),
        fn($periodid) => isset($periods[$periodid])));
    $serviceperiods[$id] = $active;
    $services[$id] = $service['name'];
    if ($active && $currentperiod !== null && !in_array($currentperiod, $active, true)) {
        $services[$id] = get_string('service_withperiods', 'local_pronoteio', (object) [
            'name' => $service['name'],
            'periods' => implode(', ', array_map(fn($periodid) => $periods[$periodid], $active)),
        ]);
    }
}
if (!$services || !$periods) {
    local_pronoteio_push_fail(get_string('error_noservices', 'local_pronoteio'), $exporturl);
}

$groups = group_filter::options($course->id);

$form = new push_form($url, [
    'courseid' => $course->id,
    'items' => $items,
    'instanceid' => $progress ? $instanceid : 0,
    'sourcename' => $progress ? $progress->get_name() : '',
    'groups' => $groups,
    'services' => $services,
    'serviceperiods' => $serviceperiods,
    'periods' => $periods,
    'maxscale' => $gradecontext['maxScale'],
]);
if ($form->is_cancelled()) {
    redirect($exporturl);
}

$optionkeys = array_flip(['itemid', 'groupid', 'serviceid', 'periodid']) + push_form::defaults(null, null);
$options = null;

if ($token !== '') {
    require_sesskey();
    $stored = $SESSION->local_pronoteio_push[$token] ?? null;
    if (!$stored || (int) $stored['courseid'] !== (int) $course->id || (int) ($stored['instanceid'] ?? 0) !== $instanceid) {
        redirect($url, get_string('push_expired', 'local_pronoteio'), null, notification::NOTIFY_WARNING);
    }
    $options = $stored['options'];
    if ($action === 'edit') {
        $form->set_data($options);
        $options = null;
        $token = '';
    }
} else if ($data = $form->get_data()) {
    $options = array_intersect_key((array) $data, $optionkeys);
    $token = random_string(20);
    $SESSION->local_pronoteio_push[$token] = ['courseid' => $course->id, 'instanceid' => $instanceid, 'options' => $options];
} else if (!$form->is_submitted()) {
    if ($progress) {
        $source = $progress;
        $defaults = push_form::defaults($source, grades::get_last_push($source));
    } else {
        $itemid = optional_param('itemid', (int) array_key_first($items), PARAM_INT);
        $itemid = isset($items[$itemid]) ? $itemid : (int) array_key_first($items);
        $source = new item_source(grade_item::fetch(['id' => $itemid, 'courseid' => $course->id]));
        $defaults = push_form::defaults($source, grades::get_last_push($source));
        $defaults['itemid'] = $itemid;
    }
    $defaults['groupid'] = group_filter::clean($course->id, optional_param('group', '0', PARAM_ALPHANUMEXT));
    if (!isset($services[$defaults['serviceid'] ?? ''])) {
        $defaults['serviceid'] = array_key_first($services);
    }
    if (!isset($periods[$defaults['periodid'] ?? ''])) {
        $defaults['periodid'] = $currentperiod ?? array_key_first($periods);
    }
    $active = $serviceperiods[$defaults['serviceid']] ?? [];
    if ($active && !in_array((string) $defaults['periodid'], $active, true)) {
        $defaults['periodid'] = $active[0];
    }
    $form->set_data($defaults);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pushgrades', 'local_pronoteio'));

if ($options === null) {
    echo html_writer::tag('p', get_string('push_step1', 'local_pronoteio'), ['class' => 'text-muted']);
    if (!get_config('local_pronoteio', 'enablegradewrite')) {
        echo $OUTPUT->notification(get_string('push_writedisabled', 'local_pronoteio'), notification::NOTIFY_INFO);
    }
    $form->display();
    echo $OUTPUT->footer();
    die();
}

// Steps 2 and 3.
if ($progress) {
    $source = $progress;
} else {
    $item = isset($items[$options['itemid'] ?? 0]) ? grade_item::fetch(['id' => $options['itemid'], 'courseid' => $course->id]) : null;
    $source = $item ? new item_source($item) : null;
}
$service = $servicesbyid[(string) $options['serviceid']] ?? null;
if (!$source || !$service || !isset($periods[(string) $options['periodid']])) {
    unset($SESSION->local_pronoteio_push[$token]);
    echo $OUTPUT->notification(get_string('push_expired', 'local_pronoteio'), notification::NOTIFY_WARNING);
    echo $OUTPUT->continue_button($url);
    echo $OUTPUT->footer();
    die();
}
$groupids = group_filter::resolve($course->id, (string) $options['groupid']);
$converter = grades::converter($source, $options);
$assessment = grades::assessment($source, $options, $converter);
$writeenabled = (bool) get_config('local_pronoteio', 'enablegradewrite');
$result = null;

try {
    $preview = grades::preview($connector, $service, $source, $groupids, $converter);
    if ($action === 'dryrun' || ($action === 'send' && $writeenabled)) {
        $result = grades::push($connector, $account, $source, $service, (string) $options['periodid'], $options, $assessment,
            $preview['grades'], $action === 'dryrun');
    }
} catch (connector_exception $e) {
    echo $OUTPUT->notification($e->getMessage(), notification::NOTIFY_ERROR);
    echo $OUTPUT->continue_button($url);
    echo $OUTPUT->footer();
    die();
}

$fullnames = array_column($preview['rows'], 'fullname', 'pronoteid');
$formatcell = function(?float $value, string $status): string {
    if ($status !== '') {
        return get_string('gradestatus_' . $status, 'local_pronoteio');
    }
    return $value === null ? '-' : format_float($value, 2, true, true);
};

if ($result) {
    $key = $result['dryRun'] ? 'push_dryrun_done' : 'push_done';
    $count = $result['dryRun'] ? count($preview['grades']) - count($result['rejected']) : $result['written'];
    echo $OUTPUT->notification(get_string($key, 'local_pronoteio', $count),
        $result['rejected'] ? notification::NOTIFY_WARNING : notification::NOTIFY_SUCCESS);
    if ($result['rejected']) {
        $table = new html_table();
        $table->head = [get_string('student', 'local_pronoteio'), get_string('push_reason', 'local_pronoteio')];
        foreach ($result['rejected'] as $rejected) {
            $reasonkey = 'push_rejected_' . $rejected['reason'];
            $table->data[] = [
                s($fullnames[$rejected['studentid']] ?? $rejected['studentid']),
                get_string_manager()->string_exists($reasonkey, 'local_pronoteio')
                    ? get_string($reasonkey, 'local_pronoteio') : s($rejected['reason']),
            ];
        }
        echo html_writer::table($table);
    }
    if (!$result['dryRun']) {
        unset($SESSION->local_pronoteio_push[$token]);
        if ($progress) {
            echo $OUTPUT->single_button($progress->get_overview_url(['group' => $options['groupid']]), get_string('back'), 'get');
        } else {
            echo $OUTPUT->single_button(new moodle_url($url, ['itemid' => $item->id]),
                get_string('push_another', 'local_pronoteio'), 'get');
            echo $OUTPUT->single_button(new moodle_url('/grade/report/grader/index.php', ['id' => $course->id]),
                get_string('back'), 'get');
        }
        echo $OUTPUT->footer();
        die();
    }
}

echo html_writer::tag('p', get_string('push_step2', 'local_pronoteio'), ['class' => 'text-muted']);

$summary = [
    get_string($progress ? 'progresssource' : 'gradeitem', 'local_pronoteio') => s($source->get_name()),
    get_string('groupsgroupings', 'group') => s($groups[(string) $options['groupid']] ?? $groups['0']),
    get_string('pronoteservice', 'local_pronoteio') => s($service['name']),
    get_string('pronoteperiod', 'local_pronoteio') => s($periods[(string) $options['periodid']]),
    get_string('assessmenttitle', 'local_pronoteio') => s($assessment['title']),
    get_string('assessmentdate', 'local_pronoteio') => userdate($assessment['date'], get_string('strftimedate', 'langconfig')),
    get_string('setting_scale', 'local_pronoteio') => format_float($assessment['max'], 2, true, true)
        . ($assessment['scaleTo20'] ? ' (' . get_string('setting_scaleto20', 'local_pronoteio') . ')' : ''),
    get_string('setting_coefficient', 'local_pronoteio') => format_float($assessment['coefficient'], 2, true, true),
];
if ($assessment['publication']) {
    $summary[get_string('assessmentpublication', 'local_pronoteio')] =
        userdate($assessment['publication'], get_string('strftimedate', 'langconfig'));
}
$dl = '';
foreach ($summary as $label => $value) {
    $dl .= html_writer::tag('dt', $label, ['class' => 'col-sm-3']) . html_writer::tag('dd', $value, ['class' => 'col-sm-9']);
}
echo html_writer::tag('dl', $dl, ['class' => 'row']);

$previous = grades::get_previous_push($source, (string) $service['id']);
if ($previous && $previous->assessmentid && (string) $previous->periodid === (string) $options['periodid']) {
    echo $OUTPUT->notification(get_string('push_update', 'local_pronoteio',
        userdate($previous->timemodified, get_string('strftimedatetimeshort', 'langconfig'))), notification::NOTIFY_INFO);
}

$counts = array_count_values(array_column($preview['rows'], 'state')) + ['send' => 0, 'skip' => 0, 'unmatched' => 0];
echo html_writer::tag('p', get_string('push_counts', 'local_pronoteio', (object) $counts));

$table = new html_table();
$table->head = [
    get_string('student', 'local_pronoteio'),
    get_string('push_pronotestudent', 'local_pronoteio'),
    get_string('push_value', 'local_pronoteio'),
    get_string('push_state', 'local_pronoteio'),
];
$badges = ['send' => 'bg-success', 'skip' => 'bg-secondary', 'unmatched' => 'bg-warning text-dark'];
foreach ($preview['rows'] as $row) {
    $table->data[] = [
        s($row['fullname']),
        s($row['pronotename']),
        $row['state'] === 'unmatched' ? '' : $formatcell($row['value'], $row['status']),
        html_writer::span(get_string('push_state_' . $row['state'], 'local_pronoteio'), 'badge ' . $badges[$row['state']]),
    ];
}
echo html_writer::table($table);

if ($preview['orphans']) {
    $list = array_map(fn($s) => s(trim($s['lastname'] . ' ' . $s['firstname']))
        . (!empty($s['ambiguous']) ? ' (' . get_string('push_ambiguous', 'local_pronoteio') . ')' : ''), $preview['orphans']);
    echo $OUTPUT->notification(get_string('push_orphans', 'local_pronoteio') . html_writer::alist($list),
        notification::NOTIFY_WARNING);
}

$hidden = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $course->id])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $token])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$buttons = html_writer::tag('button', get_string('push_edit', 'local_pronoteio'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'edit', 'class' => 'btn btn-secondary me-2']);
$nothing = !$preview['grades'];
$buttons .= html_writer::tag('button', get_string('push_dryrun', 'local_pronoteio'),
    ['type' => 'submit', 'name' => 'action', 'value' => 'dryrun', 'class' => 'btn btn-outline-primary me-2']
    + ($nothing ? ['disabled' => 'disabled'] : []));
if ($writeenabled) {
    $buttons .= html_writer::tag('button', get_string('push_send', 'local_pronoteio', count($preview['grades'])),
        ['type' => 'submit', 'name' => 'action', 'value' => 'send', 'class' => 'btn btn-primary', 'onclick' => 'return confirm(' . json_encode(
                get_string('push_confirm', 'local_pronoteio', count($preview['grades']))) . ');']
        + ($nothing ? ['disabled' => 'disabled'] : []));
}
echo html_writer::tag('form', $hidden . $buttons, ['method' => 'post', 'action' => $url->out(false), 'class' => 'mt-3']);
if (!$writeenabled) {
    echo $OUTPUT->notification(get_string('push_writedisabled', 'local_pronoteio'), notification::NOTIFY_INFO);
}

echo $OUTPUT->footer();
