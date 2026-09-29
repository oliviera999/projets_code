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

namespace local_pronoteio\connector;

/**
 * Contract between the plugin and a Pronote backend.
 *
 * Every connector works on behalf of one linked teacher account and returns normalised arrays,
 * so that the sync classes never depend on the transport (sidecar, files, future native PHP).
 *
 * Normalised shapes (timestamps are Unix seconds):
 * - lesson:     ['id', 'subject', 'teacher', 'rooms' => string[], 'groups' => string[], 'start', 'end', 'cancelled' => bool, 'status']
 * - homework:   ['id', 'subject', 'description' (HTML), 'due', 'groups' => string[], 'attachments' => [['name', 'url']]]
 * - resource:   ['id', 'name', 'type' => 'class'|'group']
 * - student:    ['id', 'firstname', 'lastname', 'email' (string|null), 'birthdate' (int|null)]
 * - absence:    ['id', 'studentid', 'start', 'end', 'justified' => bool, 'reason']
 * - grade:      ['studentid', 'value' (float|null), 'status' (''|grade_converter::STATUSES)]
 * - assessment: ['id' (string|null), 'title', 'date', 'publication' (int|null), 'max', 'coefficient',
 *                'scaleTo20' => bool, 'optional' => bool, 'bonus' => bool, 'comment']
 * - context:    ['periods' => [['id', 'name', 'current']], 'services' => [['id', 'name', 'subject',
 *                'resourceid', 'resourcename', 'type', 'periods' (ids, optional)]], 'maxScale' (float|null)]
 * - pushresult: ['assessmentid' (string|null), 'written', 'rejected' => [['studentid', 'reason']], 'dryRun']
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface connector_interface {

    /** @var string Timetable reading. */
    public const FEATURE_TIMETABLE = 'timetable';
    /** @var string Homework / lesson content reading. */
    public const FEATURE_HOMEWORK = 'homework';
    /** @var string Classes, groups and students reading. */
    public const FEATURE_ROSTER = 'roster';
    /** @var string Absences reading. */
    public const FEATURE_ABSENCES = 'absences';
    /** @var string Grades reading. */
    public const FEATURE_GRADES_READ = 'gradesread';
    /** @var string Grades writing. */
    public const FEATURE_GRADES_WRITE = 'gradeswrite';

    /**
     * Short technical name of the connector.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Whether the connector implements a feature (FEATURE_* constant).
     *
     * @param string $feature
     * @return bool
     */
    public function supports(string $feature): bool;

    /**
     * Opens a Pronote session. With a password, obtains and stores a fresh token; otherwise reuses the stored token.
     *
     * @param string $password Plain password, never persisted.
     * @param string $pin Double authentication PIN asked by Pronote on a new device, never persisted.
     * @return array ['displayname' => string]
     * @throws connector_exception
     */
    public function login(string $password = '', string $pin = ''): array;

    /**
     * Lessons between two timestamps.
     *
     * @param int $from
     * @param int $to
     * @return array[] lesson
     */
    public function get_timetable(int $from, int $to): array;

    /**
     * Homework due between two timestamps.
     *
     * @param int $from
     * @param int $to
     * @return array[] homework
     */
    public function get_homework(int $from, int $to): array;

    /**
     * Classes and groups taught by the teacher.
     *
     * @return array[] resource
     */
    public function get_resources(): array;

    /**
     * Students of a class or group.
     *
     * @param string $resourceid
     * @return array[] student
     */
    public function get_roster(string $resourceid): array;

    /**
     * Absences of the students of a class or group between two timestamps.
     *
     * @param string $resourceid
     * @param int $from
     * @param int $to
     * @return array[] absence
     */
    public function get_absences(string $resourceid, int $from, int $to): array;

    /**
     * Grades already entered in Pronote for a class or group.
     *
     * @param string $resourceid
     * @return array[] ['assessment' => assessment, 'grades' => grade[]]
     */
    public function get_grades(string $resourceid): array;

    /**
     * Periods, services (subject + class/group) and limits of the grade entry.
     *
     * @return array context
     */
    public function get_grade_context(): array;

    /**
     * Creates (assessment id null) or updates an assessment in Pronote and writes the grades.
     *
     * @param string $serviceid
     * @param string $periodid
     * @param array $assessment
     * @param array[] $grades grade
     * @param bool $dryrun Validate only, write nothing.
     * @return array pushresult
     */
    public function push_grades(string $serviceid, string $periodid, array $assessment, array $grades, bool $dryrun = false): array;
}
