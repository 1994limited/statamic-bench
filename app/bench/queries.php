<?php

/**
 * One kind of Stache query on a warm Stache, in a fresh process: run once to load
 * the indexes it needs (not timed), then timed --runs times; prints the median.
 * This is the query on its own, the way a template or controller asks it, without
 * the request around it.
 *
 *   php bench/queries.php --kind=like|like_ids|latest [--runs=20] [--first]
 *
 * --first times only the first call instead, indexes loaded from the cache included: what a request pays.
 *
 * - like: articles whose title contains "amber" (a like where), the entries fetched
 * - like_ids: the same where, ids only, so it's the filtering alone
 * - latest: the four newest published articles (orderBy date, limit)
 *
 * Prints one JSON line: {"ms":1.4,"runs_ms":[...],"found":103,"ids":"<md5>"}
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Blink;
use Statamic\Facades\Entry;

$opts = bench_opts(['kind:', 'runs:', 'first']);
$kind = $opts['kind'] ?? exit("bench: --kind is required\n");
$runs = max(1, (int) ($opts['runs'] ?? 20));

bench_boot();

$query = match ($kind) {
    'like' => fn () => Entry::query()->where('collection', 'articles')->where('title', 'like', '%amber%')->get()->map->id()->all(),
    'like_ids' => fn () => Entry::query()->where('collection', 'articles')->where('title', 'like', '%amber%')->pluck('id')->all(),
    'latest' => fn () => Entry::query()->where('collection', 'articles')->where('published', true)->orderBy('date', 'desc')->limit(4)->get()->map->id()->all(),
    default => exit("bench: unknown --kind $kind\n"),
};

if (isset($opts['first'])) {
    $t = hrtime(true);
    $ids = $query();
    $ms = (hrtime(true) - $t) / 1e6;
    bench_emit(['ms' => round($ms, 3), 'found' => count($ids), 'ids' => md5(implode(',', $ids))]);
    exit(count($ids) > 0 ? 0 : 1);
}

$ids = $query();
$times = [];
for ($i = 0; $i < $runs; $i++) {
    Blink::flush();
    $t = hrtime(true);
    $query();
    $times[] = (hrtime(true) - $t) / 1e6;
}
sort($times);
$n = count($times);
$median = $n % 2 ? $times[intdiv($n, 2)] : ($times[$n / 2 - 1] + $times[$n / 2]) / 2;

bench_emit([
    'ms' => round($median, 3),
    'runs_ms' => array_map(fn ($t) => round($t, 3), $times),
    'found' => count($ids),
    'ids' => md5(implode(',', $ids)),
]);

exit(count($ids) > 0 ? 0 : 1);
