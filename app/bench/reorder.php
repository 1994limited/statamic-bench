<?php

/**
 * Reorder the flat `docs` collection the way dragging in the Control Panel's tree
 * does: move the first entry to the end and save the tree, in a fresh process with
 * a warm Stache. Every entry's position changes. Then put the order back (not timed).
 *
 *   php bench/reorder.php
 *
 * Prints one JSON line: {"ms":252.0,"entries":1000,"first_after":"<id>"}
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

bench_boot();
$tree = Collection::findByHandle('docs')?->structure()?->in('en') ?? exit("bench: no docs collection (generate the content again)\n");
$original = $tree->tree();
$moved = $original;
$moved[] = array_shift($moved);

$t = hrtime(true);
$tree->tree($moved)->save();
$ms = (hrtime(true) - $t) / 1e6;

// the order the save left, as a query sees it
$first = Entry::query()->where('collection', 'docs')->orderBy('order')->first()?->id();
$tree->tree($original)->save();

bench_emit([
    'ms' => round($ms, 3),
    'entries' => count($moved),
    'first_after' => $first,
]);

exit($first === $moved[0]['entry'] ? 0 : 1);
