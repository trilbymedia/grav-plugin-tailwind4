<?php

namespace Grav\Plugin\Console;

use Grav\Console\ConsoleCommand;
use Grav\Plugin\Tailwind4\BuildManifest;
use Grav\Plugin\Tailwind4\BuildService;
use Grav\Plugin\Tailwind4\ParityHarness;
use Grav\Plugin\Tailwind4\ThemeConfig;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * `bin/plugin tailwind4 compile [theme] [--watch] [--diff]`
 *
 * Compiles the given (default: active) theme's Tailwind CSS through the
 * TailwindPHP engine and prints the build stats from the manifest.
 *
 * --watch polls the sources every 500 ms through the Scanner's per-file cache
 * and recompiles when anything changes. It is a convenience for content edits;
 * the theme's own `npm run watch` remains a fine choice for heavy theme
 * development.
 *
 * --diff additionally runs the official Node CLI (when the theme has a
 * node_modules directory) and reports class-selector-set differences between
 * the two builds. This is the confidence tool for the beta period.
 */
class CompileCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('compile')
            ->setDescription('Compile Tailwind CSS for a theme with the TailwindPHP engine')
            ->addArgument('theme', InputArgument::OPTIONAL, 'Theme to compile (defaults to the active theme)')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Poll sources every 500 ms and recompile on change')
            ->addOption('diff', 'd', InputOption::VALUE_NONE, 'Compare the result against the official Node CLI build');
    }

    protected function serve(): int
    {
        // CLI is a compile context, so eagerly loading the plugin's Composer
        // autoloader (classes + engine) is fine here. Web requests must never
        // do this; there the engine loads lazily inside the compile path.
        require_once __DIR__ . '/../vendor/autoload.php';

        $this->initializeThemes();

        $io = $this->getIO();
        $themeName = $this->input->getArgument('theme');

        try {
            $service = BuildService::fromGrav(\is_string($themeName) && $themeName !== '' ? $themeName : null);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->title(sprintf('Tailwind 4 compile: %s', $service->themeConfig()->themeName));

        $manifest = $service->build();
        $this->printManifest($manifest, $service);

        if (!$manifest->success) {
            $io->error($manifest->error ?? 'Build failed');

            return 1;
        }

        if ($this->input->getOption('diff')) {
            return $this->runDiff($service);
        }

        if ($this->input->getOption('watch')) {
            return $this->watch($service, $manifest);
        }

        return 0;
    }

    /**
     * Print the build stats recorded in the manifest.
     */
    private function printManifest(BuildManifest $manifest, BuildService $service): void
    {
        $io = $this->getIO();
        $config = $service->themeConfig();

        $rows = [
            ['Theme', $manifest->theme],
            ['Input', $config->input],
            ['Output', sprintf('%s (%s)', $config->output, self::formatBytes($manifest->outputSize))],
            ['Files', sprintf(
                '%d scanned (%d cache hits, %d read)',
                $manifest->filesScanned,
                $manifest->cacheHits,
                $manifest->filesRead,
            )],
            ['Candidates', (string) $manifest->candidateCount],
            ['Compile', sprintf('%.1f ms engine, %.1f ms total', $manifest->compileMs, $manifest->durationMs)],
            ['Memory', self::formatBytes($manifest->peakMemoryBytes) . ' peak'],
            ['Engine', 'tailwindphp/tailwindphp ' . $manifest->engineVersion],
        ];
        if (($path = $service->manifestPath()) !== null) {
            $rows[] = ['Manifest', $path];
        }

        $io->table([], $rows);
    }

    /**
     * Compare the plugin build with the official Node CLI build.
     *
     * @return int 0 when no selectors are missing (extras are reported but
     *             tolerated: the plugin's scanner deliberately over-extracts),
     *             1 when the Node build contains selectors ours lacks.
     */
    private function runDiff(BuildService $service): int
    {
        $io = $this->getIO();
        $config = $service->themeConfig();

        $io->section('Parity diff against the Node CLI');

        if (!ParityHarness::nodeAvailable($config->themeDir)) {
            $io->warning(sprintf(
                'No node_modules directory in %s - cannot run the reference build. ' .
                'Run "npm install" in the theme to enable --diff.',
                $config->themeDir,
            ));

            return 0;
        }

        try {
            $nodeCss = ParityHarness::nodeBuild($config->themeDir, $config->input);
        } catch (\Throwable $e) {
            $io->error('Node reference build failed: ' . $e->getMessage());

            return 1;
        }

        $ourCss = @file_get_contents($config->outputPath());
        if ($ourCss === false) {
            $io->error('Cannot read the plugin build output at ' . $config->outputPath());

            return 1;
        }

        $diff = ParityHarness::diff($nodeCss, $ourCss);
        $nodeCount = \count(ParityHarness::classSet($nodeCss));
        $ourCount = \count(ParityHarness::classSet($ourCss));

        $io->table([], [
            ['Node classes', (string) $nodeCount],
            ['Plugin classes', (string) $ourCount],
            ['Missing (in Node, not ours)', (string) \count($diff['missing'])],
            ['Extra (in ours, not Node)', (string) \count($diff['extra'])],
        ]);

        if ($diff['missing'] !== []) {
            $io->error(sprintf('%d selector(s) missing from the plugin build:', \count($diff['missing'])));
            $io->listing($diff['missing']);
        }

        if ($diff['extra'] !== []) {
            $io->note(sprintf(
                '%d extra selector(s) in the plugin build (harmless: the scanner over-extracts by design):',
                \count($diff['extra']),
            ));
            $io->listing($diff['extra']);
        }

        if ($diff['missing'] === [] && $diff['extra'] === []) {
            $io->success('Selector sets match exactly.');
        } elseif ($diff['missing'] === []) {
            $io->success('No missing selectors - the plugin build is a superset of the Node build.');
        }

        return $diff['missing'] === [] ? 0 : 1;
    }

    /**
     * Poll the sources every 500 ms and recompile on change. Unchanged files
     * cost only a stat each poll thanks to the Scanner's per-file cache. The
     * input CSS tree is fingerprinted separately since it is compiled, not
     * scanned.
     */
    private function watch(BuildService $service, BuildManifest $lastManifest): int
    {
        $io = $this->getIO();
        $config = $service->themeConfig();

        $io->writeln('<info>Watching for changes every 500 ms - press Ctrl-C to stop.</info>');

        $lastFileCount = $lastManifest->filesScanned;
        $cssFingerprint = self::cssFingerprint($config);

        // @phpstan-ignore-next-line - intentionally endless; the user stops it.
        while (true) {
            usleep(500_000);

            $scan = $service->scan();
            $newFingerprint = self::cssFingerprint($config);

            $changed = $scan->filesRead > 0
                || $scan->filesScanned !== $lastFileCount
                || $newFingerprint !== $cssFingerprint;

            if (!$changed) {
                continue;
            }

            $lastFileCount = $scan->filesScanned;
            $cssFingerprint = $newFingerprint;

            $manifest = $service->build($scan);
            if ($manifest->success) {
                $io->writeln(sprintf(
                    '[%s] Recompiled in %.1f ms - %d candidates, %s written',
                    date('H:i:s'),
                    $manifest->durationMs,
                    $manifest->candidateCount,
                    self::formatBytes($manifest->outputSize),
                ));
            } else {
                $io->writeln(sprintf(
                    '<error>[%s] Compile failed: %s</error>',
                    date('H:i:s'),
                    $manifest->error,
                ));
            }
        }
    }

    /**
     * Fingerprint every .css file under the input CSS directory (mtime + size)
     * so edits to the input or any of its relative imports trigger a rebuild.
     */
    private static function cssFingerprint(ThemeConfig $config): string
    {
        $dir = \dirname($config->inputPath());
        if (!is_dir($dir)) {
            return '';
        }

        $parts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'css') {
                $parts[] = $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize();
            }
        }
        sort($parts);

        return md5(implode('|', $parts));
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return $bytes . ' B';
    }
}
