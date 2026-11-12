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
