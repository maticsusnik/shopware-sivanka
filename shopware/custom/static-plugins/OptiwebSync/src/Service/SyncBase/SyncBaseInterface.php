<?php declare(strict_types=1);

namespace OptiwebSync\Service\SyncBase;

interface SyncBaseInterface
{
    public function getName(): string;
    public function getSyncEndpoint(): string;
    public function getSyncArrayKey(): string;
    public function getSyncType(): string;
    public function getSyncOrigin(): string;
    public function getSyncCommandNames(): array;
}
