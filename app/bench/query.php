<?php

/**
 * The first query on a Stache that has nothing built: what a request pays
 * when it arrives before `stache:warm` has run. The app boots first and the
 * views aren't involved, so the time is the Stache's alone.
 *
 *   php bench/query.php
 *
 * It's a listing: twelve published articles ordered by title. Answering it
 * needs four of the collection's indexes, not all of them.
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Entry;

bench_boot();
Illuminate\Support\Facades\Cache::flush();
Statamic\Facades\Stache::clear();

gc_collect_cycles();
memory_reset_peak_usage();
$t = hrtime(true);
$ids = Entry::query()->where('collection', 'articles')->where('site', 'en')->where('published', true)->orderBy('title')->limit(12)->get()->map->id()->all();
$ms = (hrtime(true) - $t) / 1e6;

bench_emit([
    'ms' => round($ms, 3),
    'peak_mem_mb' => round(memory_get_peak_usage() / 1048576, 2),
    'entries' => count($ids),
    'first' => $ids[0] ?? null,
]);

exit(count($ids) === 12 ? 0 : 1);
