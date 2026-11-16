<?php

declare(strict_types=1);

namespace Acme\Feedback;

use Fapost\Foundation\Solution\Queue\SolutionJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The fixture Solution's background job: writes one row into `feedback_job_log` of the tenant it runs in. The table is
 * not part of the manifest (the fixture ships no migrations); a test creates it in the schemas it looks at. The class
 * lives inside the package directory on purpose: Core's dispatcher accepts a job only from the package of the Solution
 * it names.
 */
final class RecordFeedbackJob extends SolutionJob
{
    public const string TABLE = 'feedback_job_log';

    /**
     * @param  mixed  $extra  anything a test wants the dispatcher to look at; the job never reads it
     */
    public function __construct(
        public string $title = 'hello',
        public mixed $extra = null,
    ) {
    }

    public function solution(): string
    {
        return 'feedback';
    }

    protected function perform(): void
    {
        DB::table(self::TABLE)->insert([
            'id'        => (string) Str::uuid(),
            'title'     => $this->title,
            'tenant_id' => (string) $this->tenantId,
        ]);
    }
}
