<?php

namespace App\Services;

use RuntimeException;

/**
 * Splits the Avatar Planning source page (resources/planning/index.html: one 600 KB file) into
 *   public/planning-assets/app.css   - the page's styles          (cacheable by the browser, versioned by content hash)
 *   public/planning-assets/app.js    - the page's main script     (cacheable by the browser, versioned by content hash)
 *   resources/planning/shell.html - what is left (~15 KB of markup + the config script), served per user with their data
 * so a repeat visit downloads only the shell + the user's data instead of the whole 600 KB (the controller sends
 * `Cache-Control: no-store` on every page it serves, so a single big file was re-downloaded each time).
 *
 * NOT public/planning/: a real folder with the route's name would make the web server serve (and forbid) the folder
 * instead of handing /planning to Laravel.
 *
 * The three files are generated: edit the SOURCE, then run `php artisan planning:build`. A test fails if they are stale.
 */
class PlanningAssets
{
    public const SOURCE = 'planning/index.html';

    public const SHELL = 'planning/shell.html';

    /** @return array{css: string, js: string, shell: string} */
    public static function split(string $source): array
    {
        // 1) every <style> block (all in <head>) -> one stylesheet, in order
        if (! preg_match_all('#<style[^>]*>(.*?)</style>\n?#s', $source, $styles) || count($styles[0]) < 1) {
            throw new RuntimeException('planning: no <style> block found in the source page');
        }
        $css = implode("\n", $styles[1]);

        // 2) the main script: the one big <script>\n... block (the tiny config script before it stays inline)
        if (! preg_match('#<script>\n(/\* =.*?)</script>\n?#s', $source, $script)) {
            throw new RuntimeException('planning: main <script> block not found in the source page');
        }
        $js = $script[1];

        $cssV = substr(md5($css), 0, 10);
        $jsV = substr(md5($js), 0, 10);

        // replace the first style block with the <link>, drop the rest; replace the script with the <script src>
        // (plain string replacement: a 470 KB search pattern is far beyond what preg_* accepts)
        $shell = $source;
        foreach ($styles[0] as $i => $block) {
            $shell = self::replaceFirst($shell, $block, $i === 0 ? '<link rel="stylesheet" href="__PLANNING_ASSETS__/app.css?v='.$cssV.'">'."\n" : '');
        }
        $shell = self::replaceFirst($shell, $script[0], '<script src="__PLANNING_ASSETS__/app.js?v='.$jsV.'"></script>'."\n");

        return ['css' => $css, 'js' => $js, 'shell' => $shell];
    }

    private static function replaceFirst(string $subject, string $search, string $replace): string
    {
        $pos = strpos($subject, $search);
        if ($pos === false) {
            throw new RuntimeException('planning: expected block not found while splitting the source page');
        }

        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    /** Where the generated files live: [relative-to-public-or-resources => absolute path]. */
    public static function paths(): array
    {
        return [
            'css' => public_path('planning-assets/app.css'),
            'js' => public_path('planning-assets/app.js'),
            'shell' => resource_path(self::SHELL),
        ];
    }
}
