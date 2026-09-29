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

namespace local_pronoteio\sync;

use local_pronoteio\connector\connector_interface;
use local_pronoteio\export\pronote_csv;
use local_pronoteio\local\grade_converter;
use local_pronoteio\local\grade_source;
use local_pronoteio\local\logger;
use local_pronoteio\local\student_matcher;

/**
 * Moodle grades (grade item or progress) -> Pronote. Triggered by the teacher, never by the scheduled task.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades {

    /** @var string History table. */
    public const TABLE = 'local_pronoteio_push';

    /**
     * Numeric grade items of a course (course total excluded).
     *
     * @param int $courseid
     * @return array<int, string> id => name
     */
    public static function items(int $courseid): array {
        $items = [];
        foreach (\grade_item::fetch_all(['courseid' => $courseid]) ?: [] as $item) {
            if ($item->itemtype !== 'course' && (int) $item->gradetype === GRADE_TYPE_VALUE) {
                $items[$item->id] = $item->get_name();
            }
        }
        return $items;
    }

    /**
     * Pronote import file for a source.
     *
     * @param grade_source $source
     * @param int|int[] $groupids
     * @param grade_converter $converter
     * @param string|null $title Header of the grade column, defaults to the source name.
     * @return string File content.
     */
    public static function build_file(grade_source $source, int|array $groupids, grade_converter $converter,
            ?string $title = null): string {
        $rows = [];
        foreach ($source->collect($groupids) as $row) {
            $cell = $converter->convert($row['finalgrade'], $source->get_min(), $source->get_max(), $row['excluded']);
            $rows[] = $row + ($cell ?? ['value' => null, 'status' => '']);
        }
        return pronote_csv::build($rows, $title ?? $source->get_name());
    }

    /**
     * Matches the Moodle participants with the students of a Pronote service and converts the grades.
     *
     * @param connector_interface $connector
     * @param array $service Service from the grade context.
     * @param grade_source $source
     * @param int|int[] $groupids
     * @param grade_converter $converter
     * @return array ['rows' => [[userid, fullname, pronoteid, value, status, state]], 'orphans' => student[], 'grades' => grade[]]
     *               state: send, skip (nothing to send), unmatched, ambiguous.
     */
    public static function preview(connector_interface $connector, array $service, grade_source $source, int|array $groupids,
            grade_converter $converter): array {
        $participants = $source->collect($groupids);
        $students = $connector->get_roster((string) $service['resourceid']);
        $match = (new student_matcher())->match(
            array_map(fn($r) => ['id' => $r['userid']] + $r, $participants),
            $students
        );

        $pronotebyuser = array_flip(array_map('intval', $match['pairs']));
        $ambiguousids = array_map('strval', array_column($match['ambiguous'], 'id'));
        $studentsbyid = array_column($students, null, 'id');

        $rows = [];
        $grades = [];
        foreach ($participants as $participant) {
            $pronoteid = isset($pronotebyuser[$participant['userid']]) ? (string) $pronotebyuser[$participant['userid']] : null;
            $cell = $converter->convert($participant['finalgrade'], $source->get_min(), $source->get_max(),
                $participant['excluded']);

            if ($pronoteid === null) {
                $state = 'unmatched';
            } else if ($cell === null) {
                $state = 'skip';
            } else {
                $state = 'send';
                $grades[] = ['studentid' => $pronoteid, 'value' => $cell['value'], 'status' => $cell['status']];
            }
            $rows[] = [
                'userid' => $participant['userid'],
                'fullname' => $participant['fullname'],
                'pronoteid' => $pronoteid,
                'pronotename' => $pronoteid !== null ? trim(($studentsbyid[$pronoteid]['lastname'] ?? '') . ' ' .
                    ($studentsbyid[$pronoteid]['firstname'] ?? '')) : '',
                'value' => $cell['value'] ?? null,
                'status' => $cell['status'] ?? '',
                'state' => $state,
            ];
        }

        $matchedids = array_map('strval', array_keys($match['pairs']));
        $orphans = [];
        foreach ($students as $student) {
            if (!in_array((string) $student['id'], $matchedids, true)) {
                $orphans[] = $student + ['ambiguous' => in_array((string) $student['id'], $ambiguousids, true)];
            }
        }

        return ['rows' => $rows, 'orphans' => $orphans, 'grades' => $grades];
    }

    /**
     * Converter for the options chosen in the push form.
     *
     * @param grade_source $source
     * @param array $options push_form values.
     * @return grade_converter
     */
    public static function converter(grade_source $source, array $options): grade_converter {
        $scale = ($options['scalemode'] ?? '') === grade_converter::SCALE_FIXED ? (float) $options['scale'] : $source->get_max();
        return new grade_converter($scale, (float) $options['rounding'], (string) $options['status_nograde'],
            (string) $options['status_excluded']);
    }

    /**
     * Pronote assessment (without id) for the options chosen in the push form.
     *
     * @param grade_source $source
     * @param array $options push_form values.
     * @param grade_converter $converter
     * @return array assessment
     */
    public static function assessment(grade_source $source, array $options, grade_converter $converter): array {
        $title = trim((string) ($options['title'] ?? '')) ?: $source->get_name();
        return [
            'title' => \core_text::substr($title, 0, 255),
            'date' => (int) $options['date'],
            'publication' => !empty($options['publication']) ? (int) $options['publication'] : null,
            'max' => $converter->get_scale(),
            'coefficient' => (float) $options['coefficient'],
            'scaleTo20' => !empty($options['scaleto20']),
            'optional' => !empty($options['optional']),
            'bonus' => !empty($options['bonus']),
            'comment' => \core_text::substr((string) ($options['comment'] ?? ''), 0, 1000),
        ];
    }

    /**
     * Previous push of a source to a service, or null.
     *
     * @param grade_source $source
     * @param string $serviceid
     * @return \stdClass|null
     */
    public static function get_previous_push(grade_source $source, string $serviceid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, $source->get_history_key() + ['serviceid' => $serviceid]) ?: null;
    }

    /**
     * Most recent push of a source (to prefill the options), or null.
     *
     * @param grade_source $source
     * @return \stdClass|null
     */
    public static function get_last_push(grade_source $source): ?\stdClass {
        global $DB;
        $records = $DB->get_records(self::TABLE, $source->get_history_key(), 'timemodified DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Sends the grades and records the push. A second push of the same source to the same service updates the
     * Pronote assessment created the first time.
     *
     * @param connector_interface $connector
     * @param \stdClass $account
     * @param grade_source $source
     * @param array $service
     * @param string $periodid
     * @param array $options push_form values, stored to prefill the next push.
     * @param array $assessment From assessment().
     * @param array[] $grades Grades from preview().
     * @param bool $dryrun
     * @return array pushresult
     */
    public static function push(connector_interface $connector, \stdClass $account, grade_source $source, array $service,
            string $periodid, array $options, array $assessment, array $grades, bool $dryrun): array {
        global $DB, $USER;

        $previous = self::get_previous_push($source, (string) $service['id']);
        $sameperiod = $previous && (string) $previous->periodid === $periodid;
        $assessment = ['id' => $sameperiod ? $previous->assessmentid : null] + $assessment;
        $result = $connector->push_grades((string) $service['id'], $periodid, $assessment, $grades, $dryrun);

        if ($dryrun) {
            return $result;
        }

        $now = time();
        $record = $previous ?: (object) ($source->get_history_key() + [
            'courseid' => $source->get_courseid(),
            'serviceid' => (string) $service['id'],
            'timecreated' => $now,
        ]);
        $record->accountid = $account->id;
        $record->servicename = \core_text::substr((string) $service['name'], 0, 255);
        $record->periodid = $periodid;
        $record->assessmentid = $result['assessmentid'] ?? $record->assessmentid ?? null;
        $record->options = json_encode($options);
        $record->written = $result['written'];
        $record->userid = $USER->id;
        $record->timemodified = $now;
        if (empty($record->id)) {
            $DB->insert_record(self::TABLE, $record);
        } else {
            $DB->update_record(self::TABLE, $record);
        }

        logger::log($account->id, 'grades', 'info',
            "{$result['written']} grade(s) written for {$service['name']} ({$source->get_name()})");
        return $result;
    }
}
