<?php

namespace App\Console\Commands;

use App\Services\PlanningAssets;
use Illuminate\Console\Command;

class PlanningBuild extends Command
{
    protected $signature = 'planning:build';

    protected $description = 'Regenerate public/planning-assets/app.{css,js} and resources/planning/shell.html from resources/planning/index.html';

    public function handle(): int
    {
        $parts = PlanningAssets::split(file_get_contents(resource_path(PlanningAssets::SOURCE)));

        foreach (PlanningAssets::paths() as $key => $path) {
            file_put_contents($path, $parts[$key]);
            $this->line(sprintf('%-28s %6.1f KB', str_replace(base_path().'/', '', $path), strlen($parts[$key]) / 1024));
        }

        return self::SUCCESS;
    }
}
