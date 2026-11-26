<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * MVC: a view only lays out what its controller / composer gives it (CLAUDE.md "Views do not query"). This reads every
 * Blade file and fails on a query, a service resolved with app(...), a controller, or a helper known to read records.
 */
class ViewsDoNotQueryTest extends TestCase
{
    private const FORBIDDEN = [
        'a query on a model' => '/\\\\?App\\\\Models\\\\[A-Za-z]+::(where\w*|query|with|find\w*|all|count|pluck|orderBy|latest|first\w*|types|filesOf|formData)\(/',
        // $model->relation()->… builds a query; an in-memory collection step (->unique()->count()) does not
        'a query on a relation' => '/->(?!(?:unique|keys|values|filter|flatten|collapse|reverse|sort\w*|toBase|all)\(\))[a-zA-Z]+\(\)->(where\w*|get|count|exists|first|pluck|latest|orderBy|with|sum|max|min|avg|paginate)\b/',
        'the query builder' => '/\bDB::/',
        'a service from the container' => '/\bapp\(\s*\\\\?App\\\\/',
        'a controller' => '/App\\\\Http\\\\Controllers\\\\/',
        'a service helper that reads records' => '/(EventDates::(history|reasons)|ProjectDates::(departments|linkedEvents)|ProjectLock::isLocked|NotificationFeed::for)\(/',
    ];

    public function test_no_view_queries_the_database_or_calls_a_service(): void
    {
        $found = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            // comments may name the class that does the work
            $code = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
            foreach (explode("\n", $code) as $i => $line) {
                foreach (self::FORBIDDEN as $what => $pattern) {
                    if (preg_match($pattern, $line)) {
                        $found[] = $file->getRelativePathname().':'.($i + 1)." ({$what}): ".trim(mb_substr($line, 0, 140));
                    }
                }
            }
        }

        $this->assertSame([], $found, "Views must not query - move it to the controller, a page service or a composer:\n".implode("\n", $found));
    }
}
