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
 * Correspondence table between Moodle cohorts and Pronote classes.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/csvlib.class.php');

use core\output\notification;
use local_pronoteio\form\cohort_import_form;
use local_pronoteio\local\cohort_sync;

admin_externalpage_setup('local_pronoteio_cohortmap');

$url = new moodle_url('/local/pronoteio/cohorts.php');
$action = optional_param('action', '', PARAM_ALPHA);

$account = cohort_sync::get_account();
if (!$account) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('cohortmapping', 'local_pronoteio'));
    echo $OUTPUT->notification(get_string('cohort_noaccount', 'local_pronoteio'), notification::NOTIFY_WARNING);
    echo $OUTPUT->single_button(new moodle_url('/admin/settings.php', ['section' => 'local_pronoteio_cohorts']),
        get_string('settings'));
    echo $OUTPUT->footer();
    die();
}

if ($action === 'export') {
    require_sesskey();
    $rows = array_merge([cohort_sync::CSV_COLUMNS], cohort_sync::export_rows());
    csv_export_writer::download_array('pronote_cohorts', $rows, 'semicolon');
    die();
}

if ($action === 'sync') {
    require_sesskey();
    core_php_time_limit::raise(300);
    try {
        $count = cohort_sync::run();
        redirect($url, get_string('cohort_synced', 'local_pronoteio', $count), null, notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, notification::NOTIFY_ERROR);
    }
}

$resources = [];
$loaderror = '';
try {
    $resources = cohort_sync::get_connector()->get_resources();
} catch (moodle_exception $e) {
    $loaderror = $e->getMessage();
}
usort($resources, fn($a, $b) => [$a['type'] ?? '', $a['name']] <=> [$b['type'] ?? '', $b['name']]);

$cohorts = cohort_sync::get_cohorts();
$links = cohort_sync::get_links();

if ($action === 'save' && $resources) {
    require_sesskey();
    $pids = optional_param_array('pid', [], PARAM_RAW_TRIMMED);
    $selected = optional_param_array('cohort', [], PARAM_INT);
    $fill = optional_param_array('autofill', [], PARAM_BOOL);
    $cohortbyclass = [];
    $autofill = [];
    foreach ($pids as $index => $pid) {
        $cohortbyclass[$pid] = (int) ($selected[$index] ?? 0);
        $autofill[$pid] = !empty($fill[$index]);
    }
    cohort_sync::save_links($resources, $cohortbyclass, $autofill);
    redirect($url, get_string('changessaved'), null, notification::NOTIFY_SUCCESS);
}

$importform = new cohort_import_form($url);
$importerrors = [];
if ($resources && ($data = $importform->get_data())) {
    $parsed = cohort_sync::parse_csv((string) $importform->get_file_content('csvfile'), $resources);
    $importerrors = $parsed['errors'];
    $cohortbyclass = [];
    $autofill = [];
    if (empty($data->replace)) {
        foreach ($links as $pid => $link) {
            $cohortbyclass[$pid] = (int) $link->cohortid;
            $autofill[$pid] = (bool) $link->autofill;
        }
    }
    $cohortbyclass = $parsed['cohorts'] + $cohortbyclass;
    $autofill = $parsed['autofill'] + $autofill;
    cohort_sync::save_links($resources, $cohortbyclass, $autofill);
    if (!$importerrors) {
        redirect($url, get_string('cohortimport_done', 'local_pronoteio', count($parsed['cohorts'])), null,
            notification::NOTIFY_SUCCESS);
    }
    $links = cohort_sync::get_links();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cohortmapping', 'local_pronoteio'));
echo html_writer::tag('p', get_string('cohortmapping_intro', 'local_pronoteio'));

if ($loaderror !== '') {
    echo $OUTPUT->notification($loaderror, notification::NOTIFY_ERROR);
}
if ($importerrors) {
    echo $OUTPUT->notification(get_string('cohortimport_errors', 'local_pronoteio') . html_writer::alist(array_map('s',
        $importerrors)), notification::NOTIFY_WARNING);
}
if (!$cohorts) {
    echo $OUTPUT->notification(get_string('cohort_nocohorts', 'local_pronoteio'), notification::NOTIFY_INFO);
}

if ($resources) {
    $suggestions = cohort_sync::suggest($resources, $cohorts);
    $options = [0 => get_string('none')];
    foreach ($cohorts as $cohort) {
        $options[$cohort->id] = format_string($cohort->name) . ($cohort->idnumber !== '' ? ' [' . s($cohort->idnumber) . ']' : '')
            . ($cohort->component ? ' (' . get_string('cohort_locked', 'local_pronoteio') . ')' : '');
    }

    $table = new html_table();
    $table->head = [
        get_string('pronoteresource', 'local_pronoteio'),
        get_string('type', 'local_pronoteio'),
        get_string('cohort', 'cohort'),
        get_string('cohort_autofill', 'local_pronoteio'),
        get_string('cohort_report', 'local_pronoteio'),
    ];
    foreach (array_values($resources) as $index => $resource) {
        $pid = (string) $resource['id'];
        $link = $links[$pid] ?? null;
        $selected = $link ? (int) $link->cohortid : ($suggestions[$pid] ?? 0);
        $suggested = !$link && $selected;

        $select = html_writer::select($options, "cohort[{$index}]", $selected, false,
            ['class' => 'form-select' . ($suggested ? ' border-info' : '')]);
        if ($suggested) {
            $select .= html_writer::div(get_string('cohort_suggested', 'local_pronoteio'), 'small text-info');
        }
        $checkbox = html_writer::checkbox("autofill[{$index}]", 1, $link ? (bool) $link->autofill : false, '',
            ['title' => get_string('cohort_autofill', 'local_pronoteio')]);

        $table->data[] = [
            s($resource['name']) . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => "pid[{$index}]", 'value' => $pid]),
            get_string('type_' . (($resource['type'] ?? '') === 'group' ? 'group' : 'class'), 'local_pronoteio'),
            $select,
            $checkbox,
            $link ? local_pronoteio_cohort_report($link) : '',
        ];
    }

    $hidden = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
    echo html_writer::tag('form', $hidden . html_writer::table($table)
        . html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']),
        ['method' => 'post', 'action' => $url->out(false)]);
}

echo html_writer::start_div('d-flex gap-2 my-4');
if ($links) {
    echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'sync', 'sesskey' => sesskey()]),
        get_string('cohort_syncnow', 'local_pronoteio'), 'post');
    echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'export', 'sesskey' => sesskey()]),
        get_string('cohortexport', 'local_pronoteio'), 'post');
}
echo html_writer::end_div();

if ($resources) {
    $importform->display();
}
echo $OUTPUT->footer();

/**
 * Summary of the last synchronisation of a link, with the unmatched students.
 *
 * @param stdClass $link
 * @return string HTML
 */
function local_pronoteio_cohort_report(stdClass $link): string {
    if (!$link->lastsync || !$link->lastreport) {
        return html_writer::span(get_string('cohort_neversynced', 'local_pronoteio'), 'text-muted');
    }
    $report = json_decode($link->lastreport, true) ?: [];
    $html = html_writer::div(get_string('cohort_reportsummary', 'local_pronoteio', (object) [
        'matched' => $report['matched'] ?? 0,
        'students' => $report['students'] ?? 0,
        'added' => $report['added'] ?? 0,
        'removed' => $report['removed'] ?? 0,
        'extra' => $report['extra'] ?? 0,
    ]));
    $missing = array_merge($report['unmatched'] ?? [],
        array_map(fn($n) => $n . ' (' . get_string('push_ambiguous', 'local_pronoteio') . ')', $report['ambiguous'] ?? []));
    if ($missing) {
        $html .= print_collapsible_region(html_writer::alist(array_map('s', $missing)), '',
            'local_pronoteio_missing_' . $link->id, get_string('cohort_unmatched', 'local_pronoteio', count($missing)),
            '', true, true);
    }
    $html .= html_writer::div(userdate($link->lastsync, get_string('strftimedatetimeshort', 'langconfig')), 'small text-muted');
    return $html;
}
