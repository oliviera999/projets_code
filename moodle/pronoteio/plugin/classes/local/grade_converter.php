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
 * Converts Moodle grades to Pronote grade cells (value on the Pronote scale, or special status).
 *
 * @package    local_pronoteio
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_converter {

    /** @var string Scale = maximum grade of the Moodle grade item. */
    public const SCALE_MOODLE = 'moodle';
    /** @var string Scale = fixed value from the settings. */
    public const SCALE_FIXED = 'fixed';

    /** @var string Do not send anything for this student. */
    public const STATUS_SKIP = 'skip';

    /** @var string[] Pronote special statuses understood by the sidecar. */
    public const STATUSES = ['abs', 'disp', 'nonnote', 'inapte', 'nonrendu', 'abszero', 'nonrenduzero'];

    /** @var array<string, string> Rounding steps (value => label). */
    public const ROUNDINGS = ['0.01' => '0,01', '0.1' => '0,1', '0.25' => '0,25', '0.5' => '0,5', '1' => '1'];

    /**
     * Constructor.
     *
     * @param float $scale Pronote scale ("barème").
     * @param float $rounding Rounding step (0.01, 0.5...).
     * @param string $nogradestatus Status for a missing Moodle grade (STATUS_SKIP or STATUSES).
     * @param string $excludedstatus Status for an excluded Moodle grade.
     */
    public function __construct(
        /** @var float */
        protected float $scale,
        /** @var float */
        protected float $rounding = 0.01,
        /** @var string */
        protected string $nogradestatus = self::STATUS_SKIP,
        /** @var string */
        protected string $excludedstatus = 'disp',
    ) {
        if ($this->scale <= 0) {
            throw new \coding_exception('Scale must be positive');
        }
        $this->rounding = $this->rounding > 0 ? $this->rounding : 0.01;
    }

    /**
     * Converter using the site settings for a grade item.
     *
     * @param \grade_item $item
     * @param float|null $scale Force a scale, defaults to the site setting.
     * @return self
     */
    public static function from_settings(\grade_item $item, ?float $scale = null): self {
        $config = get_config('local_pronoteio');
        if ($scale === null) {
            $scale = ($config->grade_scalemode ?? self::SCALE_MOODLE) === self::SCALE_FIXED
                ? (float) ($config->grade_scale ?? 20)
                : (float) $item->grademax;
        }
        return new self(
            $scale,
            (float) ($config->grade_rounding ?? 0.01),
            $config->grade_status_nograde ?? self::STATUS_SKIP,
            $config->grade_status_excluded ?? 'disp'
        );
    }

    /**
     * Status choices for the settings and forms.
     *
     * @return array<string, string>
     */
    public static function status_options(): array {
        $options = [self::STATUS_SKIP => get_string('status_skip', 'local_pronoteio')];
        foreach (self::STATUSES as $status) {
            $options[$status] = get_string('gradestatus_' . $status, 'local_pronoteio');
        }
        return $options;
    }

    /**
     * Scale used by this converter.
     *
     * @return float
     */
    public function get_scale(): float {
        return $this->scale;
    }

    /**
     * Converts one Moodle grade.
     *
     * @param float|null $finalgrade Moodle final grade (null when not graded).
     * @param float $grademin
     * @param float $grademax
     * @param bool $excluded Grade excluded from aggregation in Moodle.
     * @return array|null ['value' => float|null, 'status' => string], null when nothing must be sent.
     */
    public function convert(?float $finalgrade, float $grademin, float $grademax, bool $excluded = false): ?array {
        if ($excluded) {
            return $this->status_cell($this->excludedstatus);
        }
        if ($finalgrade === null) {
            return $this->status_cell($this->nogradestatus);
        }
        $range = $grademax - $grademin;
        $ratio = $range > 0 ? ($finalgrade - $grademin) / $range : 0;
        $value = round($ratio * $this->scale / $this->rounding) * $this->rounding;
        $value = max(0.0, min($this->scale, round($value, 2)));
        return ['value' => $value, 'status' => ''];
    }

    /**
     * Cell for a status, or null for STATUS_SKIP.
     *
     * @param string $status
     * @return array|null
     */
    protected function status_cell(string $status): ?array {
        if (!in_array($status, self::STATUSES, true)) {
            return null;
        }
        return ['value' => null, 'status' => $status];
    }
}
