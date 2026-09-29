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

use local_pronoteio\sync\base;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Associate a course with a Pronote class or group.
 *
 * Custom data: 'courseid' (int), 'resources' (array[] id, name, type as returned by the connector; empty for manual entry).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mapping_form extends \moodleform {

    /** @var array<string, int> Checkbox name => mapping flag. */
    public const FLOW_FLAGS = [
        'flow_timetable' => base::FLAG_TIMETABLE,
        'flow_homework' => base::FLAG_HOMEWORK,
        'flow_roster' => base::FLAG_ROSTER,
        'flow_absences' => base::FLAG_ABSENCES,
        'flow_grades' => base::FLAG_GRADES,
    ];

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $resources = $this->_customdata['resources'] ?? [];

        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        if ($resources) {
            $options = [];
            foreach ($resources as $resource) {
                $options[$resource['id']] = $resource['name'] . ' (' .
                    get_string('type_' . ($resource['type'] === 'group' ? 'group' : 'class'), 'local_pronoteio') . ')';
            }
            \core_collator::asort($options);
            $mform->addElement('autocomplete', 'pronoteid', get_string('pronoteresource', 'local_pronoteio'), $options);
            $mform->setType('pronoteid', PARAM_RAW_TRIMMED);
            $mform->addRule('pronoteid', null, 'required', null, 'client');
        } else {
            // Manual entry when the connector cannot list resources (file connector, sidecar unavailable).
            $mform->addElement('text', 'pronotename', get_string('pronoteresource', 'local_pronoteio'));
            $mform->setType('pronotename', PARAM_TEXT);
            $mform->addRule('pronotename', null, 'required', null, 'client');

            $mform->addElement('select', 'type', get_string('type', 'local_pronoteio'), [
                'class' => get_string('type_class', 'local_pronoteio'),
                'group' => get_string('type_group', 'local_pronoteio'),
            ]);
        }

        $checkboxes = [];
        foreach (array_keys(self::FLOW_FLAGS) as $name) {
            $checkboxes[] = $mform->createElement('advcheckbox', $name, '', get_string($name, 'local_pronoteio'));
        }
        $mform->addGroup($checkboxes, 'flows', get_string('flows', 'local_pronoteio'), '<br>', false);
        foreach (array_keys(self::FLOW_FLAGS) as $name) {
            $mform->setDefault($name, $name !== 'flow_absences' ? 1 : 0);
        }

        $this->add_action_buttons(false, get_string('addmapping', 'local_pronoteio'));
    }

    /**
     * Mapping flags from submitted data.
     *
     * @param \stdClass $data
     * @return int
     */
    public static function flags_from_data(\stdClass $data): int {
        $flags = 0;
        foreach (self::FLOW_FLAGS as $name => $flag) {
            if (!empty($data->$name)) {
                $flags |= $flag;
            }
        }
        return $flags;
    }
}
