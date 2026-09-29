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

namespace local_pronoteio\local;

use local_pronoteio\connector\connector_interface;
use local_pronoteio\connector\factory;

/**
 * Correspondence between Moodle cohorts and Pronote classes, and optional filling of the cohorts.
 *
 * The classes are read with the teacher account chosen in the settings: only the classes this
 * teacher can see in Pronote are available.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_sync {

    /** @var string Correspondence table. */
    public const TABLE = 'local_pronoteio_cohort';
    /** @var string Members added by the synchronisation. */
    public const MEMBERS = 'local_pronoteio_cmember';
    /** @var string[] CSV columns. */
    public const CSV_COLUMNS = ['cohort_idnumber', 'cohort_name', 'pronote_class', 'autofill'];

    /**
     * Account used for the cohorts, or null when not configured.
     *
     * @return \stdClass|null
     */
    public static function get_account(): ?\stdClass {
        global $DB;
        $accountid = (int) get_config('local_pronoteio', 'cohortaccount');
        return $accountid ? ($DB->get_record(account_manager::TABLE, ['id' => $accountid]) ?: null) : null;
    }

    /**
     * Connector of the cohort account.
     *
     * @return connector_interface|null
     */
    public static function get_connector(): ?connector_interface {
        $account = self::get_account();
        return $account ? factory::for_account($account) : null;
    }

    /**
     * Cohorts that can be linked (system and category cohorts), indexed by id.
     *
     * @return \stdClass[]
     */
    public static function get_cohorts(): array {
        global $DB;
        return $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber, component, contextid');
    }

    /**
     * Existing links indexed by Pronote id (one cohort per class in the page, several classes per cohort allowed).
     *
     * @return \stdClass[]
     */
    public static function get_links(): array {
        global $DB;
        $links = [];
        foreach ($DB->get_records(self::TABLE, null, 'pronotename ASC') as $link) {
            $links[$link->pronoteid] = $link;
        }
        return $links;
    }

    /**
     * Suggested cohort for each class: same normalised name or idnumber, then a name containing the class name.
     *
     * @param array[] $resources Pronote classes and groups.
     * @param \stdClass[] $cohorts
     * @return array<string, int> Pronote id => cohort id.
     */
    public static function suggest(array $resources, array $cohorts): array {
        $exact = [];
        foreach ($cohorts as $cohort) {
            foreach ([$cohort->name, (string) $cohort->idnumber] as $label) {
                $key = student_matcher::normalise($label);
                if ($key !== '') {
                    $exact[$key][$cohort->id] = true;
                }
            }
        }
        $suggestions = [];
        foreach ($resources as $resource) {
            $key = student_matcher::normalise($resource['name']);
            if ($key === '') {
                continue;
            }
            if (isset($exact[$key]) && count($exact[$key]) === 1) {
                $suggestions[(string) $resource['id']] = (int) array_key_first($exact[$key]);
                continue;
            }
            $partial = [];
            foreach ($cohorts as $cohort) {
                $name = ' ' . student_matcher::normalise($cohort->name) . ' ';
                if (str_contains($name, ' ' . $key . ' ')) {
                    $partial[] = (int) $cohort->id;
                }
            }
            if (count($partial) === 1) {
                $suggestions[(string) $resource['id']] = $partial[0];
            }
        }
        return $suggestions;
    }

    /**
     * Saves the correspondence table from the page.
     *
     * @param array[] $resources Pronote classes and groups (only these ids are accepted).
     * @param array<string, int> $cohortbyclass Pronote id => cohort id (0 to unlink).
     * @param array<string, bool> $autofill Pronote id => autofill.
     */
    public static function save_links(array $resources, array $cohortbyclass, array $autofill): void {
        global $DB;
        $cohorts = self::get_cohorts();
        $links = self::get_links();
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        foreach ($resources as $resource) {
            $id = (string) $resource['id'];
            $cohortid = (int) ($cohortbyclass[$id] ?? 0);
            $link = $links[$id] ?? null;
            if (!$cohortid || !isset($cohorts[$cohortid])) {
                if ($link) {
                    self::delete_link($link);
                }
                continue;
            }
            $fill = !empty($autofill[$id]) ? 1 : 0;
            if ($link && (int) $link->cohortid === $cohortid) {
                if ((int) $link->autofill !== $fill) {
                    $DB->update_record(self::TABLE, (object) ['id' => $link->id, 'autofill' => $fill, 'timemodified' => $now]);
                }
                continue;
            }
            if ($link) {
                self::delete_link($link);
            }
            $DB->insert_record(self::TABLE, (object) [
                'cohortid' => $cohortid,
                'pronoteid' => $id,
                'pronotename' => \core_text::substr((string) $resource['name'], 0, 255),
                'autofill' => $fill,
                'lastsync' => 0,
                'lastreport' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();
    }

    /**
     * Deletes a link. Members added by the synchronisation stay in the cohort.
     *
     * @param \stdClass $link
     */
    public static function delete_link(\stdClass $link): void {
        global $DB;
        $DB->delete_records(self::MEMBERS, ['linkid' => $link->id]);
        $DB->delete_records(self::TABLE, ['id' => $link->id]);
    }

    /**
     * Synchronises every link.
     *
     * @return int Number of links processed.
     */
    public static function run(): int {
        $connector = self::get_connector();
        if (!$connector) {
            return 0;
        }
        $count = 0;
        foreach (self::get_links() as $link) {
            try {
                self::sync_link($connector, $link);
                $count++;
            } catch (\moodle_exception $e) {
                logger::log((int) get_config('local_pronoteio', 'cohortaccount'), 'cohorts', 'error',
                    "{$link->pronotename}: {$e->getMessage()}");
            }
        }
        return $count;
    }

    /**
     * Matches the students of a class with the site users, fills the cohort when enabled, stores a report.
     *
     * @param connector_interface $connector
     * @param \stdClass $link
     * @return array Report: students, matched, added, removed, unmatched (names), ambiguous (names), extra.
     */
    public static function sync_link(connector_interface $connector, \stdClass $link): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $students = $connector->get_roster($link->pronoteid);
        $match = (new student_matcher())->match(self::candidate_users($students), $students);
        $matchedusers = array_map('intval', array_values($match['pairs']));
        $members = $DB->get_fieldset_select('cohort_members', 'userid', 'cohortid = ?', [$link->cohortid]);
        $members = array_map('intval', $members);

        $added = 0;
        $removed = 0;
        if ($link->autofill) {
            $now = time();
            foreach (array_diff($matchedusers, $members) as $userid) {
                cohort_add_member($link->cohortid, $userid);
                $added++;
            }
            foreach ($matchedusers as $userid) {
                if (!$DB->record_exists(self::MEMBERS, ['linkid' => $link->id, 'userid' => $userid])
                        && !in_array($userid, $members, true)) {
                    $DB->insert_record(self::MEMBERS, (object) ['linkid' => $link->id, 'userid' => $userid, 'timecreated' => $now]);
                }
            }
            // Only members added by this link and no longer in the class are removed.
            $ours = $DB->get_fieldset_select(self::MEMBERS, 'userid', 'linkid = ?', [$link->id]);
            foreach (array_diff(array_map('intval', $ours), $matchedusers) as $userid) {
                cohort_remove_member($link->cohortid, $userid);
                $DB->delete_records(self::MEMBERS, ['linkid' => $link->id, 'userid' => $userid]);
                $removed++;
            }
            $members = array_map('intval',
                $DB->get_fieldset_select('cohort_members', 'userid', 'cohortid = ?', [$link->cohortid]));
        }

        $names = fn(array $list) => array_map(fn($s) => trim(($s['lastname'] ?? '') . ' ' . ($s['firstname'] ?? '')), $list);
        $report = [
            'students' => count($students),
            'matched' => count($matchedusers),
            'added' => $added,
            'removed' => $removed,
            'unmatched' => $names($match['unmatched']),
            'ambiguous' => $names($match['ambiguous']),
            'extra' => count(array_diff($members, $matchedusers)),
        ];
        $DB->update_record(self::TABLE, (object) [
            'id' => $link->id,
            'lastsync' => time(),
            'lastreport' => json_encode($report),
        ]);
        logger::log((int) get_config('local_pronoteio', 'cohortaccount'), 'cohorts', 'info',
            "{$link->pronotename}: {$report['matched']}/{$report['students']} matched, +{$added} -{$removed}");
        return $report;
    }

    /**
     * Site users that may correspond to the students (same e-mail or same last name, case-insensitive).
     *
     * @param array[] $students
     * @return \stdClass[]
     */
    public static function candidate_users(array $students): array {
        global $CFG, $DB;
        $emails = [];
        $lastnames = [];
        foreach ($students as $student) {
            $email = \core_text::strtolower(trim((string) ($student['email'] ?? '')));
            if (str_contains($email, '@')) {
                $emails[$email] = $email;
            }
            $lastname = \core_text::strtolower(trim((string) ($student['lastname'] ?? '')));
            if ($lastname !== '') {
                $lastnames[$lastname] = $lastname;
            }
        }
        $conditions = [];
        $params = ['guestid' => $CFG->siteguest];
        if ($emails) {
            [$sql, $emailparams] = $DB->get_in_or_equal(array_values($emails), SQL_PARAMS_NAMED, 'em');
            $conditions[] = 'LOWER(email) ' . $sql;
            $params += $emailparams;
        }
        if ($lastnames) {
            [$sql, $nameparams] = $DB->get_in_or_equal(array_values($lastnames), SQL_PARAMS_NAMED, 'ln');
            $conditions[] = 'LOWER(lastname) ' . $sql;
            $params += $nameparams;
        }
        if (!$conditions) {
            return [];
        }
        return $DB->get_records_select('user',
            'deleted = 0 AND suspended = 0 AND id <> :guestid AND (' . implode(' OR ', $conditions) . ')',
            $params, '', 'id, firstname, lastname, email');
    }

    /**
     * CSV rows of the correspondence table.
     *
     * @return array[]
     */
    public static function export_rows(): array {
        $cohorts = self::get_cohorts();
        $rows = [];
        foreach (self::get_links() as $link) {
            $cohort = $cohorts[$link->cohortid] ?? null;
            if ($cohort) {
                $rows[] = [$cohort->idnumber, $cohort->name, $link->pronotename, (int) $link->autofill];
            }
        }
        return $rows;
    }

    /**
     * Reads a CSV correspondence table (columns CSV_COLUMNS, header line required).
     *
     * A cohort is found by idnumber, then by name; a class by its Pronote name.
     *
     * @param string $content
     * @param array[] $resources
     * @return array ['cohorts' => [pronoteid => cohortid], 'autofill' => [pronoteid => bool], 'errors' => string[]]
     */
    public static function parse_csv(string $content, array $resources): array {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $delimiter = substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',') ? ';' : ',';

        $cohortbyidnumber = [];
        $cohortbyname = [];
        foreach (self::get_cohorts() as $cohort) {
            if ((string) $cohort->idnumber !== '') {
                $cohortbyidnumber[$cohort->idnumber][] = (int) $cohort->id;
            }
            $cohortbyname[student_matcher::normalise($cohort->name)][] = (int) $cohort->id;
        }
        $classbyname = [];
        foreach ($resources as $resource) {
            $classbyname[student_matcher::normalise($resource['name'])][] = (string) $resource['id'];
        }

        $result = ['cohorts' => [], 'autofill' => [], 'errors' => []];
        foreach (array_slice($lines, 1) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_pad(str_getcsv($line, $delimiter, '"', '\\'), 4, '');
            [$idnumber, $name, $class, $autofill] = array_map('trim', $cells);
            $linenumber = $index + 2;

            $cohortids = $idnumber !== '' ? ($cohortbyidnumber[$idnumber] ?? []) : [];
            if (!$cohortids && $name !== '') {
                $cohortids = $cohortbyname[student_matcher::normalise($name)] ?? [];
            }
            $classids = $classbyname[student_matcher::normalise($class)] ?? [];
            if (count($cohortids) !== 1) {
                $result['errors'][] = get_string('csv_nocohort', 'local_pronoteio', (object) ['line' => $linenumber,
                    'value' => $idnumber ?: $name]);
                continue;
            }
            if (count($classids) !== 1) {
                $result['errors'][] = get_string('csv_noclass', 'local_pronoteio', (object) ['line' => $linenumber,
                    'value' => $class]);
                continue;
            }
            $result['cohorts'][$classids[0]] = $cohortids[0];
            $result['autofill'][$classids[0]] = in_array(\core_text::strtolower($autofill), ['1', 'yes', 'oui', 'true', 'x'], true);
        }
        return $result;
    }
}
