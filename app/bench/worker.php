<?php

/**
 * Serve the pages from one long-lived Laravel Octane worker.
 *
 * This drives Octane's own Worker class in this process, with a client that
 * hands it requests and keeps the responses, so the per-request lifecycle is
 * Octane's (a sandboxed copy of the app, its listeners, its clean-up) without
 * needing Swoole, RoadRunner or FrankenPHP installed. What a real server adds
 * on top is concurrency, which this does not cover.
 *
 *   php bench/worker.php --fresh=DIR [--r=20] [--only=home,article]
 *
 * DIR holds each page's HTML as rendered by a fresh PHP process
 * (bench/request.php --html), to compare against. Prints one JSON line.
 */

require __DIR__.'/lib.php';
require_once BENCH_ROOT.'/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;

$opts = bench_opts(['fresh:', 'r:', 'only:']);
$r = max(3, (int) ($opts['r'] ?? 20));
$fresh = $opts['fresh'] ?? exit("worker: --fresh is required\n");
$m = bench_manifest();
$pages = $m['pages'];
if (isset($opts['only'])) {
    $pages = array_intersect_key($pages, array_flip(explode(',', $opts['only'])));
}

$client = new class implements Client
{
    public ?OctaneResponse $response = null;

    public ?Throwable $error = null;

    public function marshalRequest(RequestContext $context): array
    {
        return [$context->request, $context];
    }

    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        $this->response = $response;
    }

    public function error(Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        $this->error = $e;
    }
};

$worker = new Worker(new ApplicationFactory(realpath(BENCH_ROOT)), $client);
$worker->boot();
bench_check_config();
bench_stay_offline($worker->application());

$get = function (string $uri) use ($worker, $client): array {
    $client->response = $client->error = null;
    $request = Request::create(BENCH_HOST.$uri, 'GET');
    $t = hrtime(true);
    $worker->handle($request, new RequestContext(['request' => $request]));
    $ms = (hrtime(true) - $t) / 1e6;
    if ($client->error) {
        throw $client->error;
    }

    return [$client->response->response->getStatusCode(), (string) $client->response->response->getContent(), $ms];
};

// ------------------------------------------------------------ timing, leaks

$times = [];
$different = [];
$memory = [];
$requests = 0;
for ($round = 0; $round < $r; $round++) {
    foreach ($pages as $name => [$url, $expect]) {
        [$status, $html, $ms] = $get($url);
        $requests++;
        $times[$name][] = $ms;
        if ($round === 0 || $round === $r - 1) {
            // kept so two targets' workers can be compared with each other
            file_put_contents("$fresh/$name.worker-".($round === 0 ? 'first' : 'last').'.html', bench_normalise($html));
        }
        if ($status !== $expect) {
            $different[$name] ??= "request $requests returned $status";
        } elseif (bench_normalise($html) !== file_get_contents("$fresh/$name.html")) {
            $different[$name] ??= "request $requests (round ".($round + 1).') differs from a fresh process';
        }
    }
    if ($round === 1 || $round === $r - 1) {
        gc_collect_cycles();
        $memory[] = ['requests' => $requests, 'mb' => round(memory_get_usage() / 1048576, 2)];
    }
}

$report = [];
foreach ($pages as $name => $_) {
    $rest = array_slice($times[$name], 1);
    sort($rest);
    $report[$name] = [
        'first_ms' => round($times[$name][0], 3),
        'median_ms' => round($rest[intdiv(count($rest), 2)], 3),
        'same_html' => ! isset($different[$name]),
        'detail' => $different[$name] ?? '',
    ];
}

// ------------------------------------------------- what the worker remembers

$c = new BenchChecks;
$article = $m['article'];
$articleUrl = $m['pages']['article'][0];

$c->check('a title saved by another process shows on the worker\'s next request', function () use ($get, $article, $articleUrl) {
    $get($articleUrl);
    try {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--title=Edited while the worker was running']);
        [, $html] = $get($articleUrl);

        return str_contains($html, 'Edited while the worker was running') ? null : 'the article page still shows the old title';
    } finally {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--restore']);
    }
});

$c->check('a date changed by another process reorders the worker\'s listing', function () use ($get, $article) {
    [, $before] = $get('/articles');
    try {
        $edited = bench_child(['bench/edit.php', "--id={$article['id']}", '--date='.$article['newest_date'], '--title=Now the newest article']);
        [, $html] = $get('/articles');
        $first = preg_match('/<h3[^>]*><a href="([^"]+)"/', substr($html, (int) strpos($html, '<main')), $found) ? $found[1] : null;

        return bench_differs('first article on /articles', $edited['url'], $first);
    } finally {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--restore']);
    }
});

$c->check('after the other process puts things back, the worker gives the HTML it gave at the start', function () use ($get, $pages, $fresh) {
    foreach (array_intersect_key($pages, ['article' => 1, 'articles_index' => 1, 'home' => 1]) as $name => [$url]) {
        [, $html] = $get($url);
        if (bench_normalise($html) !== file_get_contents("$fresh/$name.worker-first.html")) {
            file_put_contents("$fresh/$name.after-restore.html", bench_normalise($html));

            return "$name differs from the worker's first render of it";
        }
    }
});

$c->check('request state does not carry over: each page knows its own URL', function () use ($get) {
    [, $home] = $get('/');
    [, $contact] = $get('/contact');
    [, $homeAgain] = $get('/');

    return (str_contains($home, 'section-home') ? null : 'the home page lost its body class')
        ?? (str_contains($contact, 'aria-label="Breadcrumb"') ? null : 'the contact page has no breadcrumbs (it thinks it is the home page)')
        ?? (str_contains($homeAgain, 'aria-label="Breadcrumb"') ? 'the home page has breadcrumbs after another page was served' : null);
});

$c->check('an index first built after another process saved holds the saved value', function () use ($get, $article) {
    // This worker does the cold build itself, as the first worker after a deploy does...
    $get('/__bench/clear-stache');
    $get('/articles');
    try {
        // ...another process saves a field nothing has needed an index for yet...
        bench_child(['bench/edit.php', "--id={$article['id']}", '--set=reading_time=987']);
        // ...and then a request in this worker queries by that field.
        [$status, $json] = $get('/__bench/query?field=reading_time&value=987');

        return bench_differs('query status', 200, $status)
            ?? bench_differs('articles with reading_time 987', [$article['id']], json_decode($json, true));
    } finally {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--restore']);
    }
});

$c->check('an entry created by another process has a page on the worker\'s next request', function () use ($get, $articleUrl) {
    $get($articleUrl);
    try {
        $made = bench_child(['bench/edit.php', '--create=articles:made-while-the-worker-ran:Made while the worker was running']);
        [$status, $html] = $get($made['url']);

        return bench_differs('status of '.$made['url'], 200, $status)
            ?? (str_contains($html, 'Made while the worker was running') ? null : 'the page does not show the new entry');
    } finally {
        bench_child(['bench/edit.php', '--id=bench-created', '--delete']);
    }
});

$c->check('an entry renamed by another process is at its new URL, and gone from the old one', function () use ($get, $article, $articleUrl) {
    $get($articleUrl);
    try {
        $renamed = bench_child(['bench/edit.php', "--id={$article['id']}", '--slug=renamed-while-the-worker-ran']);
        [$new] = $get($renamed['url']);
        [$old] = $get($articleUrl);

        return bench_differs('status at '.$renamed['url'], 200, $new) ?? bench_differs('status at the old URL', 404, $old);
    } finally {
        bench_child(['bench/edit.php', "--id={$article['id']}", '--restore']);
    }
});

$c->check('an entry deleted by another process is gone on the worker\'s next request', function () use ($get) {
    $made = bench_child(['bench/edit.php', '--create=articles:made-to-be-deleted']);
    try {
        [$before] = $get($made['url']);
        bench_child(['bench/edit.php', '--id=bench-created', '--delete']);
        [$after] = $get($made['url']);

        return bench_differs('status before it was deleted', 200, $before) ?? bench_differs('status after', 404, $after);
    } finally {
        bench_child(['bench/edit.php', '--id=bench-created', '--delete']);
    }
});

$c->check('after another process renames the page a collection is mounted on, its entries are at the new URLs', function () use ($get, $m, $article, $articleUrl) {
    $get($articleUrl);
    try {
        $urls = bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--slug=writing', "--urls={$article['id']}"])['urls'];
        [$new] = $get($urls[$article['id']]);
        [$old] = $get($articleUrl);

        return bench_differs('the article\'s URL', str_replace('/articles/', '/writing/', $articleUrl), $urls[$article['id']])
            ?? bench_differs('status at the new URL', 200, $new)
            ?? bench_differs('status at the old URL', 404, $old);
    } finally {
        bench_child(['bench/edit.php', "--id={$m['mount']['page']}", '--restore']);
    }
});

$c->check('once everything is put back, the worker serves the article where it was', function () use ($get, $articleUrl) {
    return bench_differs('status', 200, $get($articleUrl)[0]);
});

bench_emit([
    'pages' => $report,
    'memory' => ['early_requests' => $memory[0]['requests'], 'early_mb' => $memory[0]['mb'], 'requests' => end($memory)['requests'], 'late_mb' => end($memory)['mb']],
    'checks' => $c->results,
]);
