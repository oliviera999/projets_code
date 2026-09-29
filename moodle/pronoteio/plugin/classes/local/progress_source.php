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
 * Progress (percentage of completed activities) of a Completion Progress block (block_completion_progress).
 *
 * The percentage is computed by the block itself, as in its overview page.
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_source extends grade_source {

    /** @var string Block name. */
    public const BLOCK = 'completion_progress';
    /** @var float Default Pronote scale of a progress. */
    public const DEFAULT_SCALE = 10.0;
    /** @var float Default Pronote coefficient of a progress. */
    public const DEFAULT_COEFFICIENT = 0.2;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \stdClass $instance Record of block_instances.
     */
    protected function __construct(
        /** @var \stdClass */
        protected \stdClass $course,
        /** @var \stdClass */
        protected \stdClass $instance,
    ) {
    }

    /**
     * Whether the Completion Progress block is installed.
     *
     * @return bool
     */
    public static function available(): bool {
        return class_exists(\block_completion_progress\completion_progress::class);
    }

    /**
     * Completion Progress block of a course, or null when the instance is not one of this course.
     *
     * @param \stdClass $course
     * @param int $instanceid
     * @return self|null
     */
    public static function from_instance(\stdClass $course, int $instanceid): ?self {
        global $DB;
        if (!self::available() || $instanceid <= 0) {
            return null;
        }
        $instance = $DB->get_record('block_instances', ['id' => $instanceid, 'blockname' => self::BLOCK]);
        if (!$instance) {
            return null;
        }
        $parent = \context::instance_by_id($instance->parentcontextid, IGNORE_MISSING);
        $coursecontext = $parent ? $parent->get_course_context(false) : false;
        if (!$coursecontext || (int) $coursecontext->instanceid !== (int) $course->id) {
            return null;
        }
        return new self($course, $instance);
    }

    /**
     * Completion Progress blocks of a course (course page and its activities) the user may view the overview of.
     *
     * @param \stdClass $course
     * @return self[]
     */
    public static function for_course(\stdClass $course): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        $coursecontext = \context_course::instance($course->id);
        $instances = $DB->get_records_sql(
            "SELECT bi.*
               FROM {block_instances} bi
               JOIN {context} ctx ON ctx.id = bi.parentcontextid
              WHERE bi.blockname = :blockname AND (ctx.id = :contextid OR " . $DB->sql_like('ctx.path', ':path') . ")
           ORDER BY bi.id",
            ['blockname' => self::BLOCK, 'contextid' => $coursecontext->id, 'path' => $coursecontext->path . '/%']);
        $sources = [];
        foreach ($instances as $instance) {
            $source = new self($course, $instance);
            if (has_capability('block/completion_progress:overview', $source->get_context())) {
                $sources[] = $source;
            }
        }
        return $sources;
    }

    /**
     * Block instance id.
     *
     * @return int
     */
    public function get_instanceid(): int {
        return (int) $this->instance->id;
    }

    /**
     * Context of the block, where its capabilities are checked.
     *
     * @return \context_block
     */
    public function get_context(): \context_block {
        return \context_block::instance($this->instance->id);
    }

    /**
     * Overview page of the block ("all students").
     *
     * @param array $params Extra parameters (group...).
     * @return \moodle_url
     */
    public function get_overview_url(array $params = []): \moodle_url {
        return new \moodle_url('/blocks/completion_progress/overview.php',
            ['instanceid' => $this->instance->id, 'courseid' => $this->course->id] + $params);
    }

    #[\Override]
    public function get_courseid(): int {
        return (int) $this->course->id;
    }

    #[\Override]
    public function get_name(): string {
        $config = unserialize_object(base64_decode((string) ($this->instance->configdata ?? '')));
        $title = trim((string) ($config->progressTitle ?? ''));
        return $title !== ''
            ? format_string($title, true, ['context' => $this->get_context()])
            : get_string('config_default_title', 'block_completion_progress');
    }

    #[\Override]
    public function get_min(): float {
        return 0.0;
    }

    #[\Override]
    public function get_max(): float {
        return 100.0;
    }

    #[\Override]
    public function collect(int|array $groupids = 0): array {
        $users = $this->participants($groupids);
        if (!$users) {
            return [];
        }
        $progress = (new \block_completion_progress\completion_progress($this->course))
            ->for_overview()
            ->for_block_instance($this->instance);
        $rows = [];
        foreach ($users as $user) {
            $percentage = $progress->has_activities() ? $progress->for_user($user)->get_percentage() : null;
            $rows[] = $this->row($user, $percentage === null ? null : (float) $percentage);
        }
        return $rows;
    }

    #[\Override]
    public function get_history_key(): array {
        return ['itemid' => 0, 'blockinstanceid' => (int) $this->instance->id];
    }

    #[\Override]
    public function get_default_options(): array {
        return [
            'scalemode' => grade_converter::SCALE_FIXED,
            'scale' => self::DEFAULT_SCALE,
            'coefficient' => self::DEFAULT_COEFFICIENT,
        ];
    }
}
