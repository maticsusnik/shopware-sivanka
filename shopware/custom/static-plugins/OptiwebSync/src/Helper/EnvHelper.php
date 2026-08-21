<?php declare(strict_types=1);

namespace OptiwebSync\Helper;

class EnvHelper
{
    public static function read(string $key, string $className = ''): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (empty($value) && $value !== '0') {
            throw new \RuntimeException("$className: required environment variable '$key' is not set.");
        }
        return (string) $value;
    }
}
