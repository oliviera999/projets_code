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

/**
 * Matches Pronote students with Moodle users.
 *
 * Keys are compared after normalisation (case, accents, punctuation). An ambiguous key (two users
 * or two students sharing it) never produces a match: homonyms are reported instead of guessed.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_matcher {

    /** @var string Last name + first name only. */
    public const MODE_NAME = 'name';
    /** @var string E-mail only. */
    public const MODE_EMAIL = 'email';
    /** @var string Name first, then e-mail for the remaining students. */
    public const MODE_NAME_EMAIL = 'name_email';
    /** @var string E-mail first, then name for the remaining students. */
    public const MODE_EMAIL_NAME = 'email_name';

    /** @var string[] Ordered key types. */
    protected array $keys;

    /**
     * Constructor.
     *
     * @param string|null $mode MODE_* constant, defaults to the site setting.
     */
    public function __construct(?string $mode = null) {
        $mode = $mode ?? (get_config('local_pronoteio', 'matchmode') ?: self::MODE_NAME_EMAIL);
        $this->keys = match ($mode) {
            self::MODE_NAME => ['name'],
            self::MODE_EMAIL => ['email'],
            self::MODE_EMAIL_NAME => ['email', 'name'],
            default => ['name', 'email'],
        };
    }

    /**
     * Matches two populations.
     *
     * @param \stdClass[]|array[] $users Moodle users: id, firstname, lastname, email.
     * @param array[] $students Pronote students: id, firstname, lastname, email.
     * @return array ['pairs' => [pronoteid => userid], 'unmatched' => student[], 'ambiguous' => student[]]
     */
    public function match(array $users, array $students): array {
        $users = array_map(fn($u) => (array) $u, array_values($users));
        $pairs = [];
        $ambiguous = [];
        $remainingstudents = $students;
        $takenusers = [];

        foreach ($this->keys as $keytype) {
            $userindex = $this->index($users, $keytype, $takenusers);
            $studentindex = $this->index($remainingstudents, $keytype, []);
            $next = [];
            foreach ($remainingstudents as $student) {
                $key = self::key($student, $keytype);
                if ($key === '') {
                    $next[] = $student;
                    continue;
                }
                $candidates = $userindex[$key] ?? [];
                if (count($candidates) === 1 && count($studentindex[$key]) === 1) {
                    $userid = (int) $candidates[0]['id'];
                    $pairs[(string) $student['id']] = $userid;
                    $takenusers[$userid] = true;
                } else {
                    if (count($candidates) > 1 || count($studentindex[$key]) > 1) {
                        $ambiguous[(string) $student['id']] = $student;
                    }
                    $next[] = $student;
                }
            }
            $remainingstudents = $next;
        }

        $unmatched = array_values(array_filter($remainingstudents,
            fn($s) => !isset($pairs[(string) $s['id']]) && !isset($ambiguous[(string) $s['id']])));
        $ambiguous = array_values(array_filter($ambiguous, fn($s) => !isset($pairs[(string) $s['id']])));

        return ['pairs' => $pairs, 'unmatched' => $unmatched, 'ambiguous' => $ambiguous];
    }

    /**
     * Groups records by key.
     *
     * @param array[] $records
     * @param string $keytype
     * @param array $excludedids User ids already matched.
     * @return array<string, array[]>
     */
    protected function index(array $records, string $keytype, array $excludedids): array {
        $index = [];
        foreach ($records as $record) {
            if (isset($excludedids[(int) $record['id']])) {
                continue;
            }
            $key = self::key($record, $keytype);
            if ($key !== '') {
                $index[$key][] = $record;
            }
        }
        return $index;
    }

    /**
     * Comparison key of a record.
     *
     * @param array $record
     * @param string $keytype name or email
     * @return string Empty when unavailable.
     */
    public static function key(array $record, string $keytype): string {
        if ($keytype === 'email') {
            $email = \core_text::strtolower(trim((string) ($record['email'] ?? '')));
            return str_contains($email, '@') ? $email : '';
        }
        $name = trim(($record['lastname'] ?? '') . ' ' . ($record['firstname'] ?? ''));
        return $name === '' ? '' : self::normalise($name);
    }

    /**
     * Comparable form of a text: lower case, no accents, single spaces.
     *
     * @param string $value
     * @return string
     */
    public static function normalise(string $value): string {
        $value = \core_text::strtolower(\core_text::specialtoascii(trim($value)));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
