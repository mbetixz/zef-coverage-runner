<?php
declare(strict_types=1);

namespace Zef\Middleware {
    use Psr\Http\Message\ServerRequestInterface;

    /**
     *  Core code now depends on Psr\Log\LoggerInterface.
     * Retained temporarily for legacy modules that still reference this helper.
     */
    final class ErrorLogger
    {
        public function __construct(private readonly bool $includeMessage=false){}
        public function log(string $correlationId,\Throwable $e,ServerRequestInterface $request):void
        {
            $message=\Zef\Framework\Observability\TelemetrySanitizer::redact($e->getMessage());
            $entry=['timestamp'=>date(DATE_ATOM),'level'=>'error','correlation_id'=>$correlationId,'method'=>$request->getMethod(),'path'=>$request->getUri()->getPath(),'exception'=>get_class($e)];
            if($this->includeMessage)$entry['message']=$message;
            $json=json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
            error_log($json===false?'ZEF error logging failure':$json);
        }
    }
}
