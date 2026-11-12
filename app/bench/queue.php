<?php

/**
 * What a queue worker sees. One long-lived process handles a run of "jobs";
 * between them Statamic resets what its stores and indexes remember, and
 * another process changes content in the meantime.
 *
 * It has to be run the way a worker is, because Statamic only does that reset
 * in one (it looks at the command line):
 *
 *   php bench/queue.php queue:work
 *
 * Prints one JSON line: {"checks":[...]}
 */

require __DIR__.'/lib.php';

use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Statamic;

require_once BENCH_ROOT.'/vendor/autoload.php';
$app = require BENCH_ROOT.'/bootstrap/app.php';
// the command line, where Statamic looks for it
$app->instance('request', Request::create(BENCH_HOST.'/', 'GET', server: ['argv' => ['artisan', 'queue:work']]));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
bench_check_config();
bench_stay_offline($app);

$m = bench_manifest();
$c = new BenchChecks;
$article = $m['article'];

// What the worker's loop does before it runs each job.
$nextJob = function () use ($app) {
    $app['events']->dispatch(new JobProcessing('bench', new SyncJob($app, json_encode(['job' => 'bench']), 'bench', 'default')));
};
$title = fn (string $id) => Entry::find($id)?->get('title');
$found = fn (string $title) => Entry::query()->where('collection', 'articles')->where('title', $title)->get()->map->id()->values()->all();

$c->check('this process counts as a queue worker, so the reset between jobs happens', function () {
    return Statamic::isWorker() ? null : 'Statamic does not think this is a worker: every check below would pass or fail for the wrong reason';
});

$c->check('a title saved by another process is what the next job reads, and finds by', function () use ($nextJob, $title, $found, $article) {
    $nextJob();
    $title($article['id']);
    $found('Saved between jobs');
    try {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--title=Saved between jobs']);
        $nextJob();

        return bench_differs('title', 'Saved between jobs', $title($article['id']))
            ?? bench_differs('found by the new title', [$article['id']], $found('Saved between jobs'));
    } finally {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--restore']);
    }
});

$c->check('an entry created by another process is found by the next job, by id and by a query', function () use ($nextJob, $found) {
    $nextJob();
    Entry::query()->where('collection', 'articles')->count();
    try {
        bench_child(['bench/edit.php', '--create=articles:made-between-jobs:Made between jobs']);
        $nextJob();

        return bench_differs('found by id', 'Made between jobs', Entry::find('bench-created')?->get('title'))
            ?? bench_differs('found by title', ['bench-created'], $found('Made between jobs'))
            ?? bench_differs('its url', '/articles/made-between-jobs', Entry::find('bench-created')?->url());
    } finally {
        bench_child(['bench/edit.php', '--id=bench-created', '--delete']);
    }
});

$c->check('an entry renamed by another process has its new URL in the next job', function () use ($nextJob, $article) {
    $nextJob();
    $was = Entry::find($article['id'])->url();
    try {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--slug=renamed-between-jobs']);
        $nextJob();

        return bench_differs('url', '/articles/renamed-between-jobs', Entry::find($article['id'])?->url())
            ?? bench_differs('found by its new uri', $article['id'], Entry::findByUri('/articles/renamed-between-jobs')?->id())
            ?? bench_differs('found by its old uri', null, Entry::findByUri($was)?->id());
    } finally {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--restore']);
    }
});

$c->check('an entry deleted by another process is gone in the next job', function () use ($nextJob) {
    bench_child(['bench/edit.php', '--create=articles:made-to-be-deleted']);
    try {
        $nextJob();
        $before = Entry::find('bench-created') !== null;
        bench_child(['bench/edit.php', '--id=bench-created', '--delete']);
        $nextJob();

        return bench_differs('found before it was deleted', true, $before)
            ?? bench_differs('found afterwards', false, Entry::find('bench-created') !== null)
            ?? bench_differs('counted afterwards', 0, Entry::query()->where('collection', 'articles')->where('slug', 'made-to-be-deleted')->count());
    } finally {
        bench_child(['bench/edit.php', '--id=bench-created', '--delete']);
    }
});

$c->check('after another process renames the page a collection is mounted on, the next job has the new URLs', function () use ($nextJob, $m, $article) {
    $nextJob();
    $was = Entry::find($article['id'])->url();
    try {
        bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--slug=writing']);
        $nextJob();
        Blink::flush();   // a job's own work starts from nothing remembered about earlier jobs' entries

        return bench_differs('url', str_replace('/articles/', '/writing/', $was), Entry::find($article['id'])?->url());
    } finally {
        bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--restore']);
    }
});

$c->check('an index first built after another process saved holds the saved value', function () use ($nextJob, $article) {
    // this worker does the cold build itself...
    Stache::clear();
    $nextJob();
    Entry::query()->where('collection', 'articles')->count();
    try {
        // ...another process saves a field nothing has needed an index for yet...
        bench_child(['bench/edit.php', "--id={$article['id']}", '--set=reading_time=987']);
        $nextJob();

        // ...and the next job queries by that field
        return bench_differs('articles with reading_time 987', [$article['id']],
            Entry::query()->where('collection', 'articles')->where('reading_time', 987)->get()->map->id()->values()->all());
    } finally {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--restore']);
    }
});

bench_emit(['checks' => $c->results]);
