<?php

/**
 * Shared by the bench scripts: boot the app, send a request through the HTTP
 * kernel, clear caches, normalise HTML.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Stache;

const BENCH_ROOT = __DIR__.'/..';
const BENCH_HOST = 'http://bench.test';

/** Settings that would hide or distort what the bench measures. */
const BENCH_CONFIG = [
    'statamic.static_caching.strategy' => null,
    'statamic.stache.watcher' => false,
    'statamic.antlers.debugbar' => false,
    'statamic.git.enabled' => false,
    'statamic.revisions.enabled' => false,
    'app.debug' => false,
];

/**
 * Boot the app the way a web server does: with the request it is about to
 * handle already bound, because providers (and anything they resolve, like
 * Statamic's Cascade) read it while booting.
 */
function bench_boot(string $uri = '/'): Kernel
{
    require_once BENCH_ROOT.'/vendor/autoload.php';
    $app = require BENCH_ROOT.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $app->instance('request', Request::create(BENCH_HOST.$uri));
    $kernel->bootstrap();
    bench_check_config();
    bench_stay_offline($app);

    return $kernel;
}

/**
 * The Control Panel asks statamic.com about the licence. A bench has no
 * business making that request, and a network call in a timed request would
 * be measuring the network. Every attempt fails at once, as if offline.
 */
function bench_stay_offline($app): void
{
    $app->bind(Statamic\Licensing\Outpost::class, fn () => new Statamic\Licensing\Outpost(new GuzzleHttp\Client([
        'handler' => fn ($request) => GuzzleHttp\Promise\Create::rejectionFor(new GuzzleHttp\Exception\ConnectException('the bench makes no outside requests', $request)),
    ])));
}

function bench_check_config(): void
{
    foreach (BENCH_CONFIG as $key => $want) {
        if (config($key) !== $want) {
            fwrite(STDERR, "bench: config $key must be ".var_export($want, true).', got '.var_export(config($key), true)."\n");
            exit(2);
        }
    }
}

/** One request through the kernel, as a web server would send it. */
function bench_request(Kernel $kernel, string $uri, array $server = []): array
{
    $request = Request::create(BENCH_HOST.$uri, 'GET', server: $server);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), (string) $response->getContent()];
}

/** The Stache built from nothing. */
function bench_build(): void
{
    Stache::clear();
    Stache::warm();
}

function bench_clear_caches(): void
{
    Stache::clear();
    Cache::flush();
    foreach (glob(BENCH_ROOT.'/storage/framework/views/*') as $file) {
        is_file($file) && unlink($file);
    }
}

/** Strip what legitimately differs between two renders of the same content. */
function bench_normalise(string $html): string
{
    $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="CSRF"', $html);
    $html = preg_replace('/<meta name="csrf-token" content="[^"]*">/', '<meta name="csrf-token" content="CSRF">', $html);
    // Control Panel responses carry the time and a few ids made up on the spot
    // (as JSON, and as JSON inside an HTML attribute)
    $html = preg_replace('/"(csrfToken|csrf_token|token|nonce)":"[^"]*"/', '"$1":"X"', $html);
    $html = preg_replace('/&quot;(csrfToken|csrf_token|token|nonce)&quot;:&quot;.*?&quot;/', '&quot;$1&quot;:&quot;X&quot;', $html);
    $html = preg_replace('/[ \t]+$/m', '', $html);
    $html = preg_replace('/\n{2,}/', "\n", $html);

    return trim($html)."\n";
}

function bench_manifest(): array
{
    return json_decode(file_get_contents(BENCH_ROOT.'/bench/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
}

function bench_opts(array $long): array
{
    return getopt('', $long);
}

function bench_emit(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
}

function bench_uuid(string $seed): string
{
    $h = md5($seed);

    return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
}

/**
 * Run another bench script in its own PHP process, with the same PHP settings
 * as this one (bin/bench passes the command in BENCH_CHILD).
 */
function bench_child(array $script): array
{
    $php = json_decode((string) getenv('BENCH_CHILD'), true) ?: [PHP_BINARY];
    $proc = proc_open([...$php, ...$script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BENCH_ROOT);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($proc);
    $lines = array_filter(explode("\n", trim($out)));
    $data = json_decode((string) end($lines), true);
    if ($code !== 0 || ! is_array($data)) {
        throw new RuntimeException(implode(' ', $script)." failed (exit $code): ".substr(trim($err.' '.$out), 0, 600));
    }

    return $data;
}

/** Collects named pass/fail checks; an exception inside one is a failure, not a crash. */
final class BenchChecks
{
    public array $results = [];

    public function check(string $name, callable $test): void
    {
        $started = hrtime(true);
        try {
            $detail = $test();
            $result = ['name' => $name, 'pass' => $detail === null, 'detail' => (string) $detail];
        } catch (Throwable $e) {
            $result = ['name' => $name, 'pass' => false, 'detail' => get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine()];
        }
        $this->results[] = $result + ['seconds' => round((hrtime(true) - $started) / 1e9, 1)];
    }
}

/** null when the two values are equal, otherwise a short description of the difference. */
function bench_differs(string $what, mixed $expected, mixed $actual): ?string
{
    return $expected === $actual ? null : sprintf('%s: expected %s, got %s', $what, substr(json_encode($expected), 0, 160), substr(json_encode($actual), 0, 160));
}
