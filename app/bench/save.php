<?php

/**
 * Save entries through Statamic's API in a fresh process with a warm Stache:
 * update --k existing articles and create --k new ones, then put the content
 * back the way it was (not timed).
 *
 *   php bench/save.php [--k=5]
 *
 * Prints one JSON line: {"ms":812.3,"per_save_ms":81.2,"peak_mem_mb":64.1,"saves":10}
 */

require __DIR__.'/lib.php';

use Illuminate\Support\Arr;
use Statamic\Facades\Entry;

$k = (int) (bench_opts(['k:'])['k'] ?? 5);
bench_boot();
$manifest = bench_manifest();
$updateIds = array_slice($manifest['save']['update_ids'], 0, $k);

// warm what a CP request would already have
Entry::find($manifest['save']['template_id']);
$template = Arr::except(Entry::find($manifest['save']['template_id'])->data()->all(), ['id', 'updated_at', 'updated_by']);
$originals = [];
$created = [];

gc_collect_cycles();
memory_reset_peak_usage();
$t = hrtime(true);

foreach ($updateIds as $n => $id) {
    $entry = Entry::find($id);
    $originals[$id] = $entry->data()->all();
    $entry->set('title', $entry->get('title')." (rev $n)")->set('excerpt', "Revised excerpt $n.")->save();
}
for ($i = 0; $i < $k; $i++) {
    $slug = "bench-new-$i";
    $entry = Entry::make()->id(bench_uuid($slug))->collection('articles')->blueprint('article')
        ->slug($slug)->date('2025-06-'.sprintf('%02d', $i + 1))->data(['title' => "New article $i"] + $template);
    $entry->save();
    $created[] = $entry->id();
}

$ms = (hrtime(true) - $t) / 1e6;
$peak = memory_get_peak_usage();

foreach ($created as $id) {
    Entry::find($id)?->delete();
}
foreach ($originals as $id => $data) {
    Entry::find($id)->data($data)->save();
}

bench_emit([
    'ms' => round($ms, 3),
    'per_save_ms' => round($ms / (2 * $k), 3),
    'peak_mem_mb' => round($peak / 1048576, 2),
    'saves' => 2 * $k,
]);
