<?php declare(strict_types=1);

namespace OptiwebSync\Client;

/**
 * A POST whose outcome is unknown: the connection dropped, it timed out, or
 * Minimax answered 5xx after possibly applying it. The record may exist, so
 * the caller must look it up before ever sending it again.
 */
class MinimaxUncertainWriteException extends \RuntimeException
{
}
