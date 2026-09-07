<?php

namespace App\Contracts;

use App\Models\ManualPublicationBatch;
use Closure;

interface SelfMediaContentGenerator
{
    /**
     * @template TResult
     *
     * @param  Closure(array{title:string,summary:string,body_plain:string,body_markdown:string,body_html:?string,tags:list<string>}, array<string,mixed>|null): TResult  $persistVariant
     * @return TResult
     */
    public function generateAndPersist(
        ManualPublicationBatch $batch,
        string $platform,
        Closure $persistVariant,
    ): mixed;
}
