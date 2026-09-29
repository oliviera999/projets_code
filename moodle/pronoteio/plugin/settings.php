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
 * Admin settings for local_pronoteio.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_pronoteio\local\grade_converter;
use local_pronoteio\local\student_matcher;

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('local_pronoteio_category', get_string('pluginname', 'local_pronoteio')));

    // General connection settings.
    $general = new admin_settingpage('local_pronoteio', get_string('settings_general', 'local_pronoteio'));
    if ($ADMIN->fulltree) {
        $general->add(new admin_setting_configselect(
            'local_pronoteio/connector',
            get_string('setting_connector', 'local_pronoteio'),
            get_string('setting_connector_desc', 'local_pronoteio'),
            'sidecar',
            [
                'sidecar' => get_string('connector_sidecar', 'local_pronoteio'),
                'file' => get_string('connector_file', 'local_pronoteio'),
            ]
        ));
        $general->add(new admin_setting_configtext(
            'local_pronoteio/sidecarurl',
            get_string('setting_sidecarurl', 'local_pronoteio'),
            get_string('setting_sidecarurl_desc', 'local_pronoteio'),
            'http://127.0.0.1:3900',
            PARAM_URL
        ));
        $general->add(new admin_setting_configpasswordunmask(
            'local_pronoteio/sidecarsecret',
            get_string('setting_sidecarsecret', 'local_pronoteio'),
            get_string('setting_sidecarsecret_desc', 'local_pronoteio'),
            ''
        ));
        $general->add(new admin_setting_configtext(
            'local_pronoteio/timeout',
            get_string('setting_timeout', 'local_pronoteio'),
            get_string('setting_timeout_desc', 'local_pronoteio'),
            20,
            PARAM_INT
        ));
        $general->add(new admin_setting_configtext(
            'local_pronoteio/syncwindowdays',
            get_string('setting_syncwindowdays', 'local_pronoteio'),
            get_string('setting_syncwindowdays_desc', 'local_pronoteio'),
            14,
            PARAM_INT
        ));
        $general->add(new admin_setting_configselect(
            'local_pronoteio/matchmode',
            get_string('setting_matchmode', 'local_pronoteio'),
            get_string('setting_matchmode_desc', 'local_pronoteio'),
            student_matcher::MODE_NAME_EMAIL,
            [
                student_matcher::MODE_NAME => get_string('matchmode_name', 'local_pronoteio'),
                student_matcher::MODE_EMAIL => get_string('matchmode_email', 'local_pronoteio'),
                student_matcher::MODE_NAME_EMAIL => get_string('matchmode_name_email', 'local_pronoteio'),
                student_matcher::MODE_EMAIL_NAME => get_string('matchmode_email_name', 'local_pronoteio'),
            ]
        ));
    }
    $ADMIN->add('local_pronoteio_category', $general);

    // Grade sending to Pronote: every Pronote assessment option has a site default here.
    $grades = new admin_settingpage('local_pronoteio_grades', get_string('settings_grades', 'local_pronoteio'));
    if ($ADMIN->fulltree) {
        $grades->add(new admin_setting_configcheckbox(
            'local_pronoteio/enablegradewrite',
            get_string('setting_enablegradewrite', 'local_pronoteio'),
            get_string('setting_enablegradewrite_desc', 'local_pronoteio'),
            0
        ));

        $grades->add(new admin_setting_heading('local_pronoteio/gradedefaults',
            get_string('settings_gradedefaults', 'local_pronoteio'), get_string('settings_gradedefaults_desc', 'local_pronoteio')));
        $grades->add(new admin_setting_configselect(
            'local_pronoteio/grade_scalemode',
            get_string('setting_scalemode', 'local_pronoteio'),
            get_string('setting_scalemode_desc', 'local_pronoteio'),
            grade_converter::SCALE_MOODLE,
            [
                grade_converter::SCALE_MOODLE => get_string('scalemode_moodle', 'local_pronoteio'),
                grade_converter::SCALE_FIXED => get_string('scalemode_fixed', 'local_pronoteio'),
            ]
        ));
        $grades->add(new admin_setting_configtext(
            'local_pronoteio/grade_scale',
            get_string('setting_scale', 'local_pronoteio'),
            get_string('setting_scale_desc', 'local_pronoteio'),
            '20',
            PARAM_FLOAT
        ));
        $grades->add(new admin_setting_configtext(
            'local_pronoteio/grade_coefficient',
            get_string('setting_coefficient', 'local_pronoteio'),
            get_string('setting_coefficient_desc', 'local_pronoteio'),
            '1',
            PARAM_FLOAT
        ));
        $grades->add(new admin_setting_configcheckbox(
            'local_pronoteio/grade_scaleto20',
            get_string('setting_scaleto20', 'local_pronoteio'),
            get_string('setting_scaleto20_desc', 'local_pronoteio'),
            0
        ));
        $grades->add(new admin_setting_configcheckbox(
            'local_pronoteio/grade_optional',
            get_string('setting_optional', 'local_pronoteio'),
            get_string('setting_optional_desc', 'local_pronoteio'),
            0
        ));
        $grades->add(new admin_setting_configcheckbox(
            'local_pronoteio/grade_bonus',
            get_string('setting_bonus', 'local_pronoteio'),
            get_string('setting_bonus_desc', 'local_pronoteio'),
            0
        ));
        $grades->add(new admin_setting_configtext(
            'local_pronoteio/grade_publicationdelay',
            get_string('setting_publicationdelay', 'local_pronoteio'),
            get_string('setting_publicationdelay_desc', 'local_pronoteio'),
            0,
            PARAM_INT
        ));
        $grades->add(new admin_setting_configtext(
            'local_pronoteio/grade_comment',
            get_string('setting_comment', 'local_pronoteio'),
            get_string('setting_comment_desc', 'local_pronoteio'),
            '',
            PARAM_TEXT
        ));
        $grades->add(new admin_setting_configselect(
            'local_pronoteio/grade_rounding',
            get_string('setting_rounding', 'local_pronoteio'),
            get_string('setting_rounding_desc', 'local_pronoteio'),
            '0.01',
            grade_converter::ROUNDINGS
        ));

        $grades->add(new admin_setting_heading('local_pronoteio/gradestatuses',
            get_string('settings_gradestatuses', 'local_pronoteio'), get_string('settings_gradestatuses_desc', 'local_pronoteio')));
        $statuses = grade_converter::status_options();
        $grades->add(new admin_setting_configselect(
            'local_pronoteio/grade_status_nograde',
            get_string('setting_status_nograde', 'local_pronoteio'),
            get_string('setting_status_nograde_desc', 'local_pronoteio'),
            grade_converter::STATUS_SKIP,
            $statuses
        ));
        $grades->add(new admin_setting_configselect(
            'local_pronoteio/grade_status_excluded',
            get_string('setting_status_excluded', 'local_pronoteio'),
            get_string('setting_status_excluded_desc', 'local_pronoteio'),
            'disp',
            $statuses
        ));
    }
    $ADMIN->add('local_pronoteio_category', $grades);

    // Cohorts synchronisation.
    $cohorts = new admin_settingpage('local_pronoteio_cohorts', get_string('settings_cohorts', 'local_pronoteio'));
    if ($ADMIN->fulltree) {
        $accounts = [0 => get_string('none')];
        if ($DB->get_manager()->table_exists('local_pronoteio_account')) {
            $sql = "SELECT a.id, a.username, " . implode(', ', array_map(fn($f) => "u.$f",
                    \core_user\fields::get_name_fields())) . "
                      FROM {local_pronoteio_account} a
                      JOIN {user} u ON u.id = a.userid
                     WHERE u.deleted = 0";
            foreach ($DB->get_records_sql($sql) as $record) {
                $accounts[$record->id] = fullname($record) . ' (' . s($record->username) . ')';
            }
        }
        $cohorts->add(new admin_setting_configselect(
            'local_pronoteio/cohortaccount',
            get_string('setting_cohortaccount', 'local_pronoteio'),
            get_string('setting_cohortaccount_desc', 'local_pronoteio'),
            0,
            $accounts
        ));
    }
    $ADMIN->add('local_pronoteio_category', $cohorts);

    $ADMIN->add('local_pronoteio_category', new admin_externalpage(
        'local_pronoteio_cohortmap',
        get_string('cohortmapping', 'local_pronoteio'),
        new moodle_url('/local/pronoteio/cohorts.php'),
        'moodle/cohort:manage'
    ));

    // The plugin registers its own category; Moodle must not add a default page.
    $settings = null;
}
