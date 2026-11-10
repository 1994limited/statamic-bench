<?php

/**
 * One request in a fresh PHP process, the way a PHP-FPM worker serves it:
 * nothing from an earlier request survives (statics, singletons, Blink, the
 * Stache's in-memory indexes), only what's on disk (the Stache and app cache,
 * compiled views) and compiled code (opcache, through its file cache).
 *
 *   php bench/request.php --url=/some/page [--expect=200] [--html=out.html] [--clear]
 *
 * Booting the app and handling the request are timed separately. Prints one
 * JSON line: {"status":200,"boot_ms":21.4,"ms":12.3,"peak_mem_mb":14.5,"bytes":48211}
 */

$started = hrtime(true);
require __DIR__.'/lib.php';

$opts = bench_opts(['url:', 'expect:', 'html:', 'clear']);
isset($opts['url']) || exit("bench: --url is required\n");

$kernel = bench_boot($opts['url']);
if (isset($opts['clear'])) {
    bench_clear_caches();
}
$booted = hrtime(true);

[$status, $html] = bench_request($kernel, $opts['url']);
$done = hrtime(true);

if (isset($opts['html'])) {
    file_put_contents($opts['html'], bench_normalise($html));
}

bench_emit([
    'status' => $status,
    'boot_ms' => round(($booted - $started) / 1e6, 3),
    'ms' => round(($done - $booted) / 1e6, 3),
    'peak_mem_mb' => round(memory_get_peak_usage() / 1048576, 2),
    'bytes' => strlen($html),
]);

exit(isset($opts['expect']) && (int) $opts['expect'] !== $status ? 1 : 0);
