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
 * Link or unlink the Pronote account of the current teacher.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use core\output\notification;
use local_pronoteio\connector\connector_exception;
use local_pronoteio\connector\factory;
use local_pronoteio\form\account_form;
use local_pronoteio\local\account_manager;

require_login(null, false);
if (isguestuser() || !account_manager::can_link((int) $USER->id)) {
    throw new moodle_exception('error_noaccess', 'local_pronoteio');
}

$url = new moodle_url('/local/pronoteio/account.php');
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('accounttitle', 'local_pronoteio'));
$PAGE->set_heading(get_string('accounttitle', 'local_pronoteio'));

$account = account_manager::get_for_user((int) $USER->id);

if (optional_param('action', '', PARAM_ALPHA) === 'unlink' && $account) {
    require_sesskey();
    account_manager::delete($account);
    redirect($url, get_string('accountunlinked', 'local_pronoteio'), null, notification::NOTIFY_SUCCESS);
}

$form = new account_form($url, ['hastoken' => $account && !empty($account->token)]);
if ($account) {
    $form->set_data([
        'pronoteurl' => $account->pronoteurl,
        'username' => $account->username,
        'icalurl' => $account->icalurl,
        'enabled' => $account->enabled,
    ]);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/my/'));
} else if ($data = $form->get_data()) {
    $password = $data->password;
    $pin = $data->pin;
    unset($data->password, $data->pin);
    $account = account_manager::save((int) $USER->id, $data);

    if ($password !== '' || empty($account->token)) {
        try {
            factory::for_account($account)->login($password, $pin);
        } catch (connector_exception $e) {
            account_manager::set_status($account, 'error');
            redirect($url, get_string('connectionfailed', 'local_pronoteio', $e->getMessage()), null,
                notification::NOTIFY_ERROR);
        }
    }
    redirect($url, get_string('accountlinked', 'local_pronoteio'), null, notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('accountintro', 'local_pronoteio'));

if ($account) {
    $PAGE->requires->js_call_amd('local_pronoteio/test_connection', 'init', ['[data-action="local_pronoteio-test"]']);
    echo $OUTPUT->render_from_template('local_pronoteio/account_status', [
        'status' => get_string('status_' . $account->status, 'local_pronoteio'),
        'statusclass' => match ($account->status) {
            'ok' => 'success',
            'error' => 'danger',
            default => 'secondary',
        },
        'lastsync' => $account->lastsync ? userdate($account->lastsync) : get_string('never', 'local_pronoteio'),
        'unlinkurl' => (new moodle_url($url, ['action' => 'unlink', 'sesskey' => sesskey()]))->out(false),
    ]);
}

$form->display();

if ($account) {
    $logs = $DB->get_records('local_pronoteio_log', ['accountid' => $account->id], 'timecreated DESC', '*', 0, 20);
    if ($logs) {
        $table = new html_table();
        $table->head = [get_string('date'), get_string('type', 'local_pronoteio'), get_string('status', 'local_pronoteio'),
            get_string('description')];
        foreach ($logs as $log) {
            $table->data[] = [userdate($log->timecreated), s($log->flow), s($log->level), s($log->message)];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
