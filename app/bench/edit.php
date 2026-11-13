<?php

/**
 * Change content through Statamic's API in a process of its own, standing in
 * for "an editor did something in the control panel" while another process
 * keeps running.
 *
 *   php bench/edit.php --id=ID [--title=...] [--date=Y-m-d] [--slug=...] [--set=field=value]   change it (keeps a backup)
 *   php bench/edit.php --id=ID --restore                    put the backup back
 *   php bench/edit.php --id=ID --read                       just report what's stored
 *   php bench/edit.php --create=collection:slug[:title]     make an entry with the id "bench-created"
 *   php bench/edit.php --id=ID --delete                     delete an entry
 *   php bench/edit.php --id=ID --move-under=PARENT_ID       move a page (and its children) under another page (keeps a backup)
 *   php bench/edit.php --id=ID --restore-tree               put the tree back
 *
 * Add --urls=ID,ID to any of them to get those entries' URLs as this process sees them afterwards.
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Entry;

$opts = bench_opts(['id:', 'title:', 'date:', 'slug:', 'set:', 'restore', 'read', 'create:', 'delete', 'move-under:', 'restore-tree', 'urls:']);
bench_boot();

$urls = fn () => isset($opts['urls'])
    ? ['urls' => array_map(fn ($id) => Entry::find($id)?->url(), array_combine(explode(',', $opts['urls']), explode(',', $opts['urls'])))]
    : [];

if (isset($opts['create'])) {
    [$collection, $slug, $title] = array_pad(explode(':', $opts['create'], 3), 3, 'Made by another process');
    $entry = Entry::make()->id('bench-created')->collection($collection)->locale('en')->slug($slug)->data(['title' => $title]);
    if ($entry->collection()->dated()) {
        $entry->date('2020-01-01');
    }
    $entry->save();
    bench_emit(['id' => 'bench-created', 'url' => Entry::find('bench-created')->url(), 'title' => $title] + $urls());
    exit;
}

$entry = Entry::find($opts['id']);
if (isset($opts['delete'])) {
    $entry?->delete();
    bench_emit(['deleted' => $entry !== null, 'still_found' => Entry::find($opts['id']) !== null] + $urls());
    exit;
}
$entry ?? exit("edit: no entry {$opts['id']}\n");
$backup = BENCH_ROOT.'/storage/framework/bench-edit-'.$opts['id'].'.json';
$treeBackup = BENCH_ROOT.'/storage/framework/bench-tree-'.$opts['id'].'.json';

if (isset($opts['move-under'])) {
    $tree = $entry->collection()->structure()->in($entry->locale());
    file_put_contents($treeBackup, json_encode($tree->tree()));
    $tree->move($entry->id(), $opts['move-under'])->save();
} elseif (isset($opts['restore-tree'])) {
    $tree = $entry->collection()->structure()->in($entry->locale());
    $tree->tree(json_decode(file_get_contents($treeBackup), true))->save();
    unlink($treeBackup);
} elseif (isset($opts['restore'])) {
    $was = json_decode(file_get_contents($backup), true);
    $entry->data($was['data'])->slug($was['slug']);
    if ($was['date']) {
        $entry->date($was['date']);
    }
    $entry->save();
    unlink($backup);
} elseif (! isset($opts['read'])) {
    file_put_contents($backup, json_encode(['data' => $entry->data()->all(), 'slug' => $entry->slug(), 'date' => $entry->hasDate() ? $entry->date()->format('Y-m-d') : null]));
    if (isset($opts['title'])) {
        $entry->set('title', $opts['title']);
    }
    if (isset($opts['set'])) {
        [$field, $value] = explode('=', $opts['set'], 2);
        $entry->set($field, is_numeric($value) ? (int) $value : $value);
    }
    if (isset($opts['date'])) {
        $entry->date($opts['date']);
    }
    if (isset($opts['slug'])) {
        $entry->slug($opts['slug']);
    }
    $entry->save();
}

$fresh = Entry::find($opts['id']);
bench_emit([
    'title' => $fresh->get('title'),
    'date' => $fresh->hasDate() ? $fresh->date()->format('Y-m-d') : null,
    'url' => $fresh->url(),
    'found_by_title' => Entry::query()->where('collection', $fresh->collectionHandle())->where('title', $fresh->get('title'))->count(),
] + $urls());
