<?php

/**
 * A hash of everything the generator wrote: the content, the blueprints, the
 * sites, the assets and the users. Two targets with the same fingerprint hold
 * the same site, and a target whose fingerprint no longer matches the one in
 * its manifest has been changed since it was generated.
 */
function bench_fingerprint(string $root): string
{
    $files = ["$root/resources/sites.yaml"];
    foreach (['content', 'resources/blueprints', 'public/assets', 'users'] as $dir) {
        if (! is_dir("$root/$dir")) {
            // a missing directory is a difference, not a crash
            $files[] = "$root/$dir";

            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    $hash = hash_init('xxh128');
    foreach ($files as $file) {
        hash_update($hash, substr($file, strlen($root)).':'.(is_file($file) ? hash_file('xxh128', $file) : 'missing')."\n");
    }

    return hash_final($hash);
}

/**
 * Statamic lists a store's files by modified time, so the order an unordered
 * query returns entries in depends on when their files were written. Every
 * content file gets the same time, which leaves them in path order, the same
 * in every target however long ago each was generated.
 */
const BENCH_FILE_TIME = 1700000000;

/** Give the content files that time. Returns how many needed it. */
function bench_stamp(string $root): int
{
    $stamped = 0;
    foreach (['content', 'users'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getMTime() !== BENCH_FILE_TIME) {
                touch($file->getPathname(), BENCH_FILE_TIME);
                $stamped++;
            }
        }
    }

    return $stamped;
}
