<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Contracts\Queue\Job as JobContract;

class SqsDiskJob extends SqsJob implements JobContract
{
    use SqsDiskBaseJob;
}
