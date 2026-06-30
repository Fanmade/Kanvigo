<?php

namespace App\Enums;

use App\Models\Dependency;
use InvalidArgumentException;

/**
 * The kind of relationship a {@see Dependency} row records between
 * two tasks. A row is always stored as a directed edge whose `blocker` end is
 * the "subject" (outward) side and whose `dependent` end is the "object"
 * (inward) side — e.g. for {@see self::Blocks} the blocker blocks the dependent.
 *
 * Only {@see self::Blocks} affects whether a task is considered blocked; the
 * other types are purely informational links. {@see self::Relates} is symmetric:
 * it reads the same from either end, so it is stored canonically (lowest id as
 * the blocker) to avoid storing the same link twice.
 *
 * The string "keywords" ("blocked_by", "blocks", "duplicates", ...) are the
 * shared vocabulary used by the UI select, the MCP/REST APIs and the activity
 * log; "blocked_by"/"blocks" predate the typed model, so they stay valid.
 */
enum RelationshipType: string
{
    case Blocks = 'blocks';
    case Relates = 'relates';
    case Duplicates = 'duplicates';
    case Clones = 'clones';
    case Causes = 'causes';

    /**
     * Whether links of this type make the dependent task "blocked" until the
     * blocker is complete. Only blocking links factor into cycle detection.
     */
    public function isBlocking(): bool
    {
        return $this === self::Blocks;
    }

    /**
     * Whether the relationship reads the same from both ends and so carries no
     * direction (stored canonically, displayed under a single heading).
     */
    public function isSymmetric(): bool
    {
        return $this === self::Relates;
    }

    /**
     * Heading/label shown for the side that acts — the blocker/subject end.
     */
    public function subjectLabel(): string
    {
        return match ($this) {
            self::Blocks => __('Blocks'),
            self::Relates => __('Relates to'),
            self::Duplicates => __('Duplicates'),
            self::Clones => __('Clones'),
            self::Causes => __('Causes'),
        };
    }

    /**
     * Heading/label shown for the receiving side — the dependent/object end.
     */
    public function objectLabel(): string
    {
        return match ($this) {
            self::Blocks => __('Blocked by'),
            self::Relates => __('Relates to'),
            self::Duplicates => __('Duplicated by'),
            self::Clones => __('Cloned by'),
            self::Causes => __('Caused by'),
        };
    }

    /**
     * The heading under which a link is grouped for a given task, depending on
     * whether that task is the subject (outward) or object (inward) end.
     */
    public function groupHeading(bool $asSubject): string
    {
        return $this->isSymmetric() || $asSubject ? $this->subjectLabel() : $this->objectLabel();
    }

    /**
     * The keyword naming this relationship from the acting task's perspective.
     */
    public function keyword(bool $asSubject): string
    {
        if ($this->isSymmetric()) {
            return $this->value;
        }

        return match ($this) {
            self::Blocks => $asSubject ? 'blocks' : 'blocked_by',
            self::Duplicates => $asSubject ? 'duplicates' : 'duplicated_by',
            self::Clones => $asSubject ? 'clones' : 'cloned_by',
            self::Causes => $asSubject ? 'causes' : 'caused_by',
            self::Relates => $this->value,
        };
    }

    /**
     * Resolve a keyword to its type and whether the acting task is the subject
     * (outward) end. Returns null for an unknown keyword.
     *
     * @return array{0: self, 1: bool}|null
     */
    public static function fromKeyword(string $keyword): ?array
    {
        return match ($keyword) {
            'blocked_by' => [self::Blocks, false],
            'blocks' => [self::Blocks, true],
            'relates' => [self::Relates, true],
            'duplicates' => [self::Duplicates, true],
            'duplicated_by' => [self::Duplicates, false],
            'clones' => [self::Clones, true],
            'cloned_by' => [self::Clones, false],
            'causes' => [self::Causes, true],
            'caused_by' => [self::Causes, false],
            default => null,
        };
    }

    /**
     * Resolve a keyword already known to be valid (e.g., validated against
     * {@see self::keywords()}), throwing if it is not.
     *
     * @return array{0: self, 1: bool}
     */
    public static function requireKeyword(string $keyword): array
    {
        return self::fromKeyword($keyword)
            ?? throw new InvalidArgumentException("Unknown relationship keyword [{$keyword}].");
    }

    /**
     * Every relationship keyword, in display order — for `in:` validation rules
     * and API enums.
     *
     * @return list<string>
     */
    public static function keywords(): array
    {
        return [
            'blocked_by', 'blocks', 'relates',
            'duplicates', 'duplicated_by',
            'clones', 'cloned_by',
            'causes', 'caused_by',
        ];
    }

    /**
     * The select options offered when adding a relationship: keyword => label,
     * phrased as "this task ___", in display order.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'blocked_by' => __('Is blocked by'),
            'blocks' => __('Blocks'),
            'relates' => __('Relates to'),
            'duplicates' => __('Duplicates'),
            'duplicated_by' => __('Is duplicated by'),
            'clones' => __('Clones'),
            'cloned_by' => __('Is cloned by'),
            'causes' => __('Causes'),
            'caused_by' => __('Is caused by'),
        ];
    }

    /**
     * The activity-log sentence describing this relationship change from the
     * acting task's perspective, e.g. "is now blocked by KAN-3".
     */
    public function activityDescription(bool $added, bool $asSubject, string $reference): string
    {
        $params = ['ref' => $reference];

        if ($this->isSymmetric()) {
            return $added
                ? __('is now related to :ref', $params)
                : __('is no longer related to :ref', $params);
        }

        return match (true) {
            $this === self::Blocks && $asSubject => $added ? __('now blocks :ref', $params) : __('no longer blocks :ref', $params),
            $this === self::Blocks => $added ? __('is now blocked by :ref', $params) : __('is no longer blocked by :ref', $params),
            $this === self::Duplicates && $asSubject => $added ? __('now duplicates :ref', $params) : __('no longer duplicates :ref', $params),
            $this === self::Duplicates => $added ? __('is now duplicated by :ref', $params) : __('is no longer duplicated by :ref', $params),
            $this === self::Clones && $asSubject => $added ? __('now clones :ref', $params) : __('no longer clones :ref', $params),
            $this === self::Clones => $added ? __('is now cloned by :ref', $params) : __('is no longer cloned by :ref', $params),
            $this === self::Causes && $asSubject => $added ? __('now causes :ref', $params) : __('no longer causes :ref', $params),
            default => $added ? __('is now caused by :ref', $params) : __('is no longer caused by :ref', $params),
        };
    }
}
