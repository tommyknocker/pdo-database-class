<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\orm\relations;

use tommyknocker\pdodb\orm\Model;
use tommyknocker\pdodb\query\QueryProfiler;

/** Internal, opt-in relation attribution for the active query profilers. */
final class RelationTrace
{
    /** @return list<QueryProfiler> */
    public static function begin(Model $owner, string $name, string $mode, RelationInterface $relation): array
    {
        $databases = [];

        try {
            $ownerDb = $owner->getModelDb();
        } catch (\RuntimeException) {
            // Attribution must not change the behavior of a relation that needs no query.
            return [];
        }
        if ($ownerDb !== null) {
            $databases[] = $ownerDb;
        }
        if (!$owner->propagatesConnection() && method_exists($relation, 'getModelClass')) {
            $class = $relation->getModelClass();
            if (is_string($class) && is_a($class, Model::class, true)) {
                $relatedDb = $class::getDb();
                if ($relatedDb !== null) {
                    $databases[] = $relatedDb;
                }
            }
        }
        $profilers = [];
        foreach ($databases as $db) {
            $profiler = $db->getProfiler();
            if ($profiler !== null && !isset($profilers[spl_object_id($profiler)]) && $profiler->beginRelation($owner::class, $name, $mode)) {
                $profilers[spl_object_id($profiler)] = $profiler;
            }
        }
        return array_values($profilers);
    }

    /** @param list<QueryProfiler> $profilers */
    public static function end(array $profilers): void
    {
        foreach ($profilers as $profiler) {
            $profiler->endRelation();
        }
    }
}
