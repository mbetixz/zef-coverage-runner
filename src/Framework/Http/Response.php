<?php

declare(strict_types=1);

namespace Zef\Framework\Http {
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\StreamInterface;
    use Zef\Framework\Constant\HttpReasonPhrases;

    /**
     * Immutable PSR-7 response (RF-02.4 header normalization core).
     *
     * - STATUS is validated through HttpStatusValidator::assert() at
     *   construction and in withStatus() — invalid codes throw instead of
     *   producing an unserializable response line.
     * - REASON PHRASE: an explicit non-empty phrase wins; otherwise the
     *   canonical phrase from HttpReasonPhrases::MAP for the status is used,
     *   falling back to '' when the status has no registered phrase.
     * - Body convenience: a string body is converted to an in-memory stream
     *   via Stream::fromString(); a StreamInterface is used as-is.
     */
    final class Response extends MessageBase implements ResponseInterface
    {
        private int $status;
        private string $reasonPhrase;

        public function __construct(int $status = 200, array $headers = [], string|StreamInterface $body = '', string $reasonPhrase = '', string $protocolVersion = '1.1')
        {
            $bodyStream = is_string($body) ? Stream::fromString($body) : $body;
            parent::__construct($bodyStream, $headers, $protocolVersion);
            new \Zef\Framework\Validation\HttpStatusValidator()->assert($status);
            $this->status = $status;
            $this->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (HttpReasonPhrases::MAP[$status] ?? '');
        }

        #[\Override]
        public function getStatusCode(): int
        {
            return $this->status;
        }
        #[\Override]
        public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
        {
            new \Zef\Framework\Validation\HttpStatusValidator()->assert($code);
            $n = clone $this;
            $n->status = $code;
            $n->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (HttpReasonPhrases::MAP[$code] ?? '');
            return $n;
        }
        #[\Override]
        public function getReasonPhrase(): string
        {
            return $this->reasonPhrase;
        }
    }

}
