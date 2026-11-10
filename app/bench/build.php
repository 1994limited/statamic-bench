<?php

/**
 * Build the Stache from nothing in a fresh process, the way `php please
 * stache:warm` does after a deploy.
 *
 *   php bench/build.php
 *
 * Prints one JSON line: {"ms":1234.5,"peak_mem_mb":210.4,"entries":2590}
 */

require __DIR__.'/lib.php';

bench_boot();
Illuminate\Support\Facades\Cache::flush();
Statamic\Facades\Stache::clear();

gc_collect_cycles();
memory_reset_peak_usage();
$t = hrtime(true);
bench_build();
$ms = (hrtime(true) - $t) / 1e6;

bench_emit([
    'ms' => round($ms, 3),
    'peak_mem_mb' => round(memory_get_peak_usage() / 1048576, 2),
    'entries' => Statamic\Facades\Entry::query()->count(),
]);
