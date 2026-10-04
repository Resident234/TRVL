<?php

declare(strict_types=1);

namespace app\tests\Unit\shared\Publications\Service;

use app\shared\Forum\Contract\ForumHttpClientInterface;
use RuntimeException;

/**
 * The HTTP client the publishing queue reads pictures through, scripted per
 * address: an address it holds bytes for is answered with them, an address
 * listed as unreachable answers with a failure, and every address the queue
 * asked for is kept in the order the questions came. That is how a test tells
 * a picture the portal downloaded from a link it left to the channel.
 */
final class RecordingHttpClientStub implements ForumHttpClientInterface
{
    /** @var array<string, string> address => the bytes it answers with */
    private array $_bodies;

    /** @var string[] addresses answering with a failure */
    private array $_unreachable;

    /** @var string[] addresses the queue asked this client for */
    public array $asked = [];

    /**
     * @param array<string, string> $bodies address => the bytes it answers with
     * @param string[] $unreachable addresses answering with a failure
     */
    public function __construct(array $bodies, array $unreachable = [])
    {
        $this->_bodies = $bodies;
        $this->_unreachable = $unreachable;
    }

    public function get(string $url): string
    {
        $this->asked[] = $url;

        if (in_array($url, $this->_unreachable, true)) {
            throw new RuntimeException(sprintf('Request failed: %s [Connection timed out]', $url));
        }

        if (!isset($this->_bodies[$url])) {
            throw new RuntimeException('No answer is scripted for: ' . $url);
        }

        return $this->_bodies[$url];
    }
}
