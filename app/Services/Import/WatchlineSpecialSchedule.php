<?php

namespace App\Services\Import;

use Illuminate\Support\Carbon;

/** OpenCart selects the lowest priority and price among specials valid at a given time. */
class WatchlineSpecialSchedule
{
    /** @return list<array{special: mixed, starts_at: ?string, ends_at: ?string}> */
    public function segments(iterable $specials): array
    {
        $rows = [];
        $boundaries = [];
        foreach ($specials as $special) {
            // OpenCart uses date_start < NOW() and date_end > NOW().
            $start = $special->starts_at ? Carbon::parse($special->starts_at)->getTimestamp() + 1 : PHP_INT_MIN;
            $end = $special->ends_at ? Carbon::parse($special->ends_at)->getTimestamp() : PHP_INT_MAX;
            if ($end <= $start) {
                continue;
            }
            $rows[] = compact('special', 'start', 'end');
            $boundaries[$start] = $start;
            $boundaries[$end] = $end;
        }
        $boundaries = array_values($boundaries);
        sort($boundaries, SORT_NUMERIC);
        $segments = [];
        for ($index = 0; $index < count($boundaries) - 1; $index++) {
            $start = $boundaries[$index];
            $end = $boundaries[$index + 1];
            $eligible = array_values(array_filter($rows, fn (array $row): bool => $row['start'] <= $start && $row['end'] >= $end));
            usort($eligible, fn (array $left, array $right): int => [
                (int) $left['special']->priority, (float) $left['special']->price, (int) $left['special']->id,
            ] <=> [
                (int) $right['special']->priority, (float) $right['special']->price, (int) $right['special']->id,
            ]);
            if ($eligible === []) {
                continue;
            }
            $special = $eligible[0]['special'];
            $previous = array_key_last($segments);
            if ($previous !== null && $segments[$previous]['special']->id === $special->id && $segments[$previous]['end'] === $start) {
                $segments[$previous]['end'] = $end;
            } else {
                $segments[] = compact('special', 'start', 'end');
            }
        }

        return array_map(fn (array $segment): array => [
            'special' => $segment['special'],
            'starts_at' => $segment['start'] === PHP_INT_MIN ? null : Carbon::createFromTimestamp($segment['start'], config('app.timezone'))->toDateTimeString(),
            'ends_at' => $segment['end'] === PHP_INT_MAX ? null : Carbon::createFromTimestamp($segment['end'] - 1, config('app.timezone'))->toDateTimeString(),
        ], $segments);
    }
}
