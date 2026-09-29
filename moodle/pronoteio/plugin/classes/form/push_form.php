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

namespace local_pronoteio\form;

use local_pronoteio\local\grade_converter;
use local_pronoteio\local\grade_source;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Step 1 of the direct grade push: grade item, Pronote service and period, assessment options.
 *
 * Custom data: 'courseid', 'items' (id => name) or, for a progress, 'instanceid' and 'sourcename',
 * 'groups' (group_filter::options()), 'services' (id => label),
 * 'serviceperiods' (service id => ids of the periods it is graded in, empty when unknown),
 * 'periods' (id => name), 'maxscale' (float|null).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class push_form extends \moodleform {

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $data = $this->_customdata;

        $mform->addElement('hidden', 'courseid', $data['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('header', 'sourcehdr', get_string('push_source', 'local_pronoteio'));
        if (!empty($data['instanceid'])) {
            $mform->addElement('hidden', 'instanceid', $data['instanceid']);
            $mform->setType('instanceid', PARAM_INT);
            $mform->addElement('static', 'sourcename', get_string('progresssource', 'local_pronoteio'), s($data['sourcename']));
            $mform->addHelpButton('sourcename', 'progresssource', 'local_pronoteio');
        } else {
            $mform->addElement('select', 'itemid', get_string('gradeitem', 'local_pronoteio'), $data['items']);
            $mform->setType('itemid', PARAM_INT);
        }
        $mform->addElement('select', 'groupid', get_string('groupsgroupings', 'group'), $data['groups']);
        $mform->setType('groupid', PARAM_ALPHANUMEXT);

        $mform->addElement('header', 'targethdr', get_string('push_target', 'local_pronoteio'));
        $mform->addElement('select', 'serviceid', get_string('pronoteservice', 'local_pronoteio'), $data['services']);
        $mform->setType('serviceid', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('serviceid', 'pronoteservice', 'local_pronoteio');
        $mform->addElement('select', 'periodid', get_string('pronoteperiod', 'local_pronoteio'), $data['periods']);
        $mform->setType('periodid', PARAM_RAW_TRIMMED);

        $mform->addElement('header', 'assessmenthdr', get_string('push_assessment', 'local_pronoteio'));
        $mform->setExpanded('assessmenthdr');
        $mform->addElement('text', 'title', get_string('assessmenttitle', 'local_pronoteio'), ['size' => 50]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addHelpButton('title', 'assessmenttitle', 'local_pronoteio');
        $mform->addElement('date_selector', 'date', get_string('assessmentdate', 'local_pronoteio'));
        $mform->addElement('date_selector', 'publication', get_string('assessmentpublication', 'local_pronoteio'));
        $mform->addHelpButton('publication', 'assessmentpublication', 'local_pronoteio');

        $mform->addElement('select', 'scalemode', get_string('setting_scalemode', 'local_pronoteio'), [
            grade_converter::SCALE_MOODLE => get_string('scalemode_moodle', 'local_pronoteio'),
            grade_converter::SCALE_FIXED => get_string('scalemode_fixed', 'local_pronoteio'),
        ]);
        $mform->addElement('text', 'scale', get_string('setting_scale', 'local_pronoteio'), ['size' => 6]);
        $mform->setType('scale', PARAM_FLOAT);
        $mform->hideIf('scale', 'scalemode', 'eq', grade_converter::SCALE_MOODLE);

        $mform->addElement('text', 'coefficient', get_string('setting_coefficient', 'local_pronoteio'), ['size' => 6]);
        $mform->setType('coefficient', PARAM_FLOAT);
        $mform->addElement('advcheckbox', 'scaleto20', get_string('setting_scaleto20', 'local_pronoteio'));
        $mform->addElement('advcheckbox', 'optional', get_string('setting_optional', 'local_pronoteio'));
        $mform->addHelpButton('optional', 'setting_optional', 'local_pronoteio');
        $mform->addElement('advcheckbox', 'bonus', get_string('setting_bonus', 'local_pronoteio'));
        $mform->addHelpButton('bonus', 'setting_bonus', 'local_pronoteio');
        $mform->addElement('textarea', 'comment', get_string('setting_comment', 'local_pronoteio'), ['rows' => 2, 'cols' => 50]);
        $mform->setType('comment', PARAM_TEXT);

        $mform->addElement('header', 'conversionhdr', get_string('push_conversion', 'local_pronoteio'));
        $mform->addElement('select', 'rounding', get_string('setting_rounding', 'local_pronoteio'), grade_converter::ROUNDINGS);
        $statuses = grade_converter::status_options();
        $mform->addElement('select', 'status_nograde', get_string('setting_status_nograde', 'local_pronoteio'), $statuses);
        $mform->addElement('select', 'status_excluded', get_string('setting_status_excluded', 'local_pronoteio'), $statuses);

        $this->add_action_buttons(true, get_string('push_preview', 'local_pronoteio'));
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (empty($this->_customdata['instanceid']) && !isset($this->_customdata['items'][$data['itemid']])) {
            $errors['itemid'] = get_string('invaliddata', 'error');
        }
        if (!array_key_exists((string) $data['groupid'], $this->_customdata['groups'])) {
            $errors['groupid'] = get_string('invaliddata', 'error');
        }
        if (!isset($this->_customdata['services'][$data['serviceid']])) {
            $errors['serviceid'] = get_string('invaliddata', 'error');
        }
        if (!isset($this->_customdata['periods'][$data['periodid']])) {
            $errors['periodid'] = get_string('invaliddata', 'error');
        } else if (!isset($errors['serviceid'])) {
            $active = $this->_customdata['serviceperiods'][$data['serviceid']] ?? [];
            if ($active && !in_array((string) $data['periodid'], $active, true)) {
                $names = array_map(fn($periodid) => $this->_customdata['periods'][$periodid], $active);
                $errors['periodid'] = get_string('error_serviceperiod', 'local_pronoteio', implode(', ', $names));
            }
        }
        if ($data['scalemode'] === grade_converter::SCALE_FIXED) {
            $max = $this->_customdata['maxscale'] ?? null;
            if ((float) $data['scale'] <= 0 || ($max && (float) $data['scale'] > $max)) {
                $errors['scale'] = get_string('error_scale', 'local_pronoteio', $max ?: '-');
            }
        }
        if ((float) $data['coefficient'] < 0) {
            $errors['coefficient'] = get_string('error_coefficient', 'local_pronoteio');
        }
        if (!empty($data['publication']) && $data['publication'] < $data['date']) {
            $errors['publication'] = get_string('error_publication', 'local_pronoteio');
        }
        if (!array_key_exists($data['rounding'], grade_converter::ROUNDINGS)) {
            $errors['rounding'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }

    /**
     * Default values from the site settings, then those of the kind of source (a progress is sent on 10 with a
     * coefficient of 0.2), overridden by the last push of the source.
     *
     * @param grade_source|null $source
     * @param \stdClass|null $lastpush Record of local_pronoteio_push.
     * @return array
     */
    public static function defaults(?grade_source $source, ?\stdClass $lastpush): array {
        $config = get_config('local_pronoteio');
        $today = usergetmidnight(time());
        $delay = (int) ($config->grade_publicationdelay ?? 0);
        $defaults = [
            'title' => $source ? $source->get_name() : '',
            'date' => $today,
            'publication' => $today + max(0, $delay) * DAYSECS,
            'scalemode' => $config->grade_scalemode ?? grade_converter::SCALE_MOODLE,
            'scale' => (float) ($config->grade_scale ?? 20),
            'coefficient' => (float) ($config->grade_coefficient ?? 1),
            'scaleto20' => (int) ($config->grade_scaleto20 ?? 0),
            'optional' => (int) ($config->grade_optional ?? 0),
            'bonus' => (int) ($config->grade_bonus ?? 0),
            'comment' => (string) ($config->grade_comment ?? ''),
            'rounding' => (string) ($config->grade_rounding ?? '0.01'),
            'status_nograde' => $config->grade_status_nograde ?? grade_converter::STATUS_SKIP,
            'status_excluded' => $config->grade_status_excluded ?? 'disp',
        ];
        if ($source) {
            $defaults = array_merge($defaults, array_intersect_key($source->get_default_options(), $defaults));
        }
        if ($lastpush) {
            $previous = json_decode((string) $lastpush->options, true) ?: [];
            $defaults = array_merge($defaults, array_intersect_key($previous, $defaults));
            $defaults['serviceid'] = $lastpush->serviceid;
            $defaults['periodid'] = $lastpush->periodid;
            // Pushes made when the publication date was optional may have none: publish on the assessment date.
            if (empty($defaults['publication'])) {
                $defaults['publication'] = $defaults['date'];
            }
        }
        return $defaults;
    }
}
