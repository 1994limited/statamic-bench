<?php

/**
 * Change an entry through Statamic's API in a process of its own, standing in
 * for "an editor saved something in the control panel" while another process
 * keeps running.
 *
 *   php bench/edit.php --id=ID [--title=...] [--date=Y-m-d] [--set=field=value]   change it (keeps a backup)
 *   php bench/edit.php --id=ID --restore                      put the backup back
 *   php bench/edit.php --id=ID --read                         just report what's stored
 */

require __DIR__.'/lib.php';

use Statamic\Facades\Entry;

$opts = bench_opts(['id:', 'title:', 'date:', 'set:', 'restore', 'read']);
bench_boot();
$entry = Entry::find($opts['id']) ?? exit("edit: no entry {$opts['id']}\n");
$backup = BENCH_ROOT.'/storage/framework/bench-edit-'.$opts['id'].'.json';

if (isset($opts['restore'])) {
    $was = json_decode(file_get_contents($backup), true);
    $entry->data($was['data'])->date($was['date'])->save();
    unlink($backup);
} elseif (! isset($opts['read'])) {
    file_put_contents($backup, json_encode(['data' => $entry->data()->all(), 'date' => $entry->date()->format('Y-m-d')]));
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
    $entry->save();
}

$fresh = Entry::find($opts['id']);
bench_emit([
    'title' => $fresh->get('title'),
    'date' => $fresh->date()->format('Y-m-d'),
    'url' => $fresh->url(),
    'found_by_title' => Entry::query()->where('collection', $fresh->collectionHandle())->where('title', $fresh->get('title'))->count(),
]);
