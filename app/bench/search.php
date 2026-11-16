<?php

/**
 * A search with two wheres, paginated, in a fresh process with a warm Stache: what
 * `{{ search:results paginate="10" }}` asks for (it always adds a status where, and
 * a site where on a multi-site). The index is a local (Comb) one over the articles'
 * titles and excerpts, defined here so every target has the same one; it's built
 * the first time, which the bench does before timing.
 *
 *   php bench/search.php [--build]
 *
 * Prints one JSON line: {"ms":61.6,"total":303,"ids":"<md5>"}
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Search;

$opts = bench_opts(['build']);
bench_boot();
config(['statamic.search.indexes.bench_articles' => [
    'driver' => 'local',
    'searchables' => ['collection:articles'],
    'fields' => ['title', 'excerpt'],
]]);

$index = Search::index('bench_articles');
if (isset($opts['build'])) {
    $index->update();
    bench_emit(['ms' => 0, 'built' => true]);
    exit(0);
}

$t = hrtime(true);
$page = $index->ensureExists()->search('amber')->where('status', 'published')->where('site', 'en')->paginate(10);
$ms = (hrtime(true) - $t) / 1e6;

bench_emit([
    'ms' => round($ms, 3),
    'total' => $page->total(),
    'ids' => md5(implode(',', collect($page->items())->map(fn ($r) => $r->getSearchable()->id())->all())),
]);

exit($page->total() > 0 ? 0 : 1);
