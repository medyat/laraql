<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Medyat\Laraql\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
uses(RefreshDatabase::class)->in('Feature/FixtureReplayTest.php', 'Feature/PushdownTest.php', 'Feature/SearchExpressionTest.php');
