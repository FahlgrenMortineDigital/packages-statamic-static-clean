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
    protected $signature = 'static-cache:clean
        {--dry-run : Show what would be deleted without actually deleting anything}
        {--format= : Output format: text (default) or json}';

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
            if ($this->option('format') === 'json') {
                $this->line(json_encode(['status' => 'error', 'message' => 'static-cache:clean only supports the static cache file driver.']));
            } else {
                $this->error('static-cache:clean only supports the static cache file driver.');
            }
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $json = $this->option('format') === 'json';
        $deleted = 0;
        $orphanedFiles = [];

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
            ->each(function (string $dir) use (&$deleted, &$orphanedFiles, $expected, $dryRun, $json) {

                if (!File::isDirectory($dir)) {
                    return;
                }

                collect(File::allFiles($dir))->each(function (\SplFileInfo $file) use (&$deleted, &$orphanedFiles, $expected, $dryRun, $json, $dir) {

                    // statamic static cache only writes .html files
                    if ($file->getExtension() !== 'html') {
                        return;
                    }

                    $fullPath = $file->getPathname();
                    $inRoot = $dir === dirname($fullPath, 1);

                    if (!$expected->has($fullPath)) {
                        $orphanedFiles[] = $fullPath;

                        if ($dryRun) {
                            if (!$json) {
                                $this->line("Would delete: {$fullPath}");
                            }
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

        if ($json) {
            $this->line(json_encode([
                'status'    => 'success',
                'dry_run'   => $dryRun,
                'count'     => $deleted,
                'files'     => $orphanedFiles,
            ]));
        } else {
            $msg = $dryRun
                ? "Dry-run complete - {$deleted} orphaned files found."
                : "Static prune complete - deleted {$deleted} orphaned files.";
            $this->info($msg);
        }

        return self::SUCCESS;
    }
}