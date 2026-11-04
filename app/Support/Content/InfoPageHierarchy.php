<?php

namespace App\Support\Content;

use App\Models\Content\Page\InfoPage;
use Illuminate\Support\Collection;

class InfoPageHierarchy
{
    /**
     * @param  Collection<int, InfoPage>  $pages  Pages in their sibling sort order.
     * @return array{pages: Collection<int, InfoPage>, context: array<int, array{depth: int, path: string}>}
     */
    public function build(Collection $pages): array
    {
        $bySourceId = [];
        foreach ($pages as $page) {
            $sourceId = (int) data_get($page->payload, 'modx.id', 0);
            if ($sourceId <= 0 && preg_match('/^lavaggio-modx-(\d+)$/', $page->code, $matches)) {
                $sourceId = (int) $matches[1];
            }
            if ($sourceId > 0 && ! isset($bySourceId[$sourceId])) {
                $bySourceId[$sourceId] = $page;
            }
        }

        $roots = [];
        $children = [];
        foreach ($pages as $page) {
            $parent = $bySourceId[(int) data_get($page->payload, 'modx.parent', 0)] ?? null;
            if ($parent && $parent->id !== $page->id) {
                $children[$parent->id][] = $page;
            } else {
                $roots[] = $page;
            }
        }

        $ordered = [];
        $context = [];
        $visit = function (InfoPage $page, int $depth, array $ancestors) use (&$visit, &$ordered, &$context, $children): void {
            if (isset($context[$page->id])) {
                return;
            }

            $ordered[] = $page;
            $context[$page->id] = ['depth' => $depth, 'path' => implode(' / ', $ancestors)];
            $ancestors[] = (string) ($page->translations->first()?->title ?: $page->code);
            foreach ($children[$page->id] ?? [] as $child) {
                $visit($child, $depth + 1, $ancestors);
            }
        };

        foreach ($roots as $root) {
            $visit($root, 0, []);
        }
        // Keep malformed or cyclic source hierarchies visible exactly once.
        foreach ($pages as $page) {
            $visit($page, 0, []);
        }

        return ['pages' => collect($ordered), 'context' => $context];
    }
}
