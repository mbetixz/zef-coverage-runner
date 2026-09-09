<?php

declare(strict_types=1);

namespace Zef\Framework {
    use Psr\Http\Message\ResponseInterface;

    /**
     * Serializes a PSR-7 ResponseInterface to the SAPI output.
     *
     * Headers are emitted only while output buffering has not started
     * (headers_sent() === false). Because ZEF validates every header name and
     * value on write (HeaderValidator: no CR/LF/NUL in values, RFC token names),
     * the raw concatenation below is injection-safe by construction; no extra
     * escaping is required at emit time.
     *
     * Body streaming rules: statuses 1xx, 204, 205 and 304 never carry a body;
     * suppressBody additionally disables output; the stream is read in 8 KiB
     * chunks until EOF (a zero-length read also terminates the loop).
     */
    final class ResponseEmitter
    {
        public function emit(ResponseInterface $response, bool $closeBody = false, bool $suppressBody = false): void
        {
            if (!headers_sent()) {
                http_response_code($response->getStatusCode());
                foreach ($response->getHeaders() as $name => $values) {
                    foreach ($values as $value) {
                        header($name . ': ' . $value, false);
                    }
                }
            }
            $body = $response->getBody();
            try {
                $status = $response->getStatusCode();
                if (!$suppressBody && $status >= 200 && $status !== 204 && $status !== 205 && $status !== 304) {
                    if (!$body->isReadable()) {
                        throw new \RuntimeException('Response body stream is not readable.');
                    }
                    while (!$body->eof()) {
                        $chunk = $body->read(8192);
                        if ($chunk === '') {
                            break;
                        } echo $chunk;
                    }
                }
            } finally {
                if ($closeBody) {
                    $body->close();
                }
            }
        }
    }
}
