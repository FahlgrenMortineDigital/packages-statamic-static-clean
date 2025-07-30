<?php

namespace Fahlgrendigital\StaticCacheClean\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\FileCacher;

class CleanStaticPageCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'static-cache:clean {--dry-run : Show what would be deleted without actually deleting anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up any orphan static page cache files.';

    public function handle(Cacher $cacher)
    {
        /** @var FileCacher $cacher */
        if (!$cacher instanceof FileCacher) {
            $this->error('static-cache:clean only supports the static cache file driver.');
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $deleted = 0;

        // 1. Build expected file paths from all Redis entries
        $expected = collect();

        $cacher->getDomains()->each(function (string $domain) use (&$expected, $cacher) {
            $cacher->getUrls($domain)->each(function (string $url) use ($domain, $cacher, &$expected) {
                $site = optional(Site::findByUrl($domain . $url))->handle();
                $expected->push(
                    preg_replace(
                        '#/+/#',
                        '/',
                        $cacher->getFilePath($domain . $url, $site)
                    )
                );
            });
        });

        $expected = $expected->flip(); // for fast lookup

        // 2. Walk through cache directories and delete strays
        collect($cacher->getCachePaths())              // [siteHandle => '/public/static/site/']
            ->each(function (string $dir) use (&$deleted, $expected, $dryRun) {

                if (!File::isDirectory($dir)) {
                    return;
                }

                collect(File::allFiles($dir))->each(function (\SplFileInfo $file) use (&$deleted, $expected, $dryRun, $dir) {

                    // statamic static cache only writes .html files
                    if ($file->getExtension() !== 'html') {
                        return;
                    }

                    $fullPath = $file->getPathname();
                    $inRoot = $dir === dirname($fullPath, 1);

                    if (!$expected->has($fullPath)) {
                        if ($dryRun) {
                            $this->line("Would delete: {$fullPath}");
                        } else {
                            File::delete($fullPath);
                        }

                        $deleted++;

                        // Optional tidy-up: remove now-empty parent dir (page folder) as long as it isn't the root folder
                        $parent = dirname($fullPath, 2);

                        if (!$inRoot && File::isDirectory($parent) && File::isEmptyDirectory($parent)) {
                            File::deleteDirectory($parent);
                        }
                    }

                    return true;
                });
            });

        $msg = $dryRun
            ? "Dry-run complete - {$deleted} orphaned files found."
            : "Static prune complete - deleted {$deleted} orphaned files.";
        $this->info($msg);

        return self::SUCCESS;
    }
}