<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use Illuminate\Support\Arr;
use Illuminate\Filesystem\FilesystemAdapter;

trait ResolvesPointers
{
    /**
     * Resolves the job payload pointer.
     */
    protected function resolvePointer(): ?string
    {
        return json_decode($this->job['Body'])->pointer ?? null;
    }

    /**
     * Resolves the configured queue disk that stores large payloads.
     */
    protected function resolveDisk(): FilesystemAdapter
    {
        return $this->container->make('filesystem')->disk(Arr::get($this->diskOptions, 'disk'));
    }
}
