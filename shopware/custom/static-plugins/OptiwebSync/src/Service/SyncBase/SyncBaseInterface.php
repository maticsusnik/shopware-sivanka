<?php declare(strict_types=1);

namespace OptiwebSync\Service\SyncBase;

interface SyncBaseInterface
{
    /**
     * Run the sync.
     *
     * Recognised options: test (bool), dryRun (bool), ignoreHash (bool),
     * ignoreMedia (bool), setId (?string).
     *
     * @param array<string, mixed> $options
     */
    public function sync(array $options): void;

    public function getName(): string;

    public function getSyncType(): string;

    public function getSyncOrigin(): string;

    /** @return array<int, string> */
    public function getSyncCommandNames(): array;
}
