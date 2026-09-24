<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * Text that is not UTF-8 is refused at the door, on every route — a 400, before anything reads it.
 *
 * A browser never sends it: every page here is UTF-8, and a form is encoded as its page is. Only a request
 * made by hand does, and such text passes every `string` rule — then broke whatever turned it into JSON, a
 * database row or a cache key: a 500 from each page that answers with the name it was sent, a refused INSERT
 * on MySQL, a rate limiter that could not count. One check here, for the query, the form, the names of the
 * files uploaded and the User-Agent the session keeps, and nothing behind it ever meets such text. A JSON body
 * needs no check: PHP will not decode one that is not UTF-8, so it arrives empty.
 */
class RejectMalformedText
{
    public function handle(Request $request, Closure $next): Response
    {
        $names = array_map(
            fn (UploadedFile $file) => $file->getClientOriginalName(),
            array_filter(Arr::flatten($request->allFiles()), fn (mixed $file) => $file instanceof UploadedFile),
        );

        $wellFormed = $this->isUtf8($request->query->all())
            && $this->isUtf8($request->request->all())
            && $this->isUtf8($names)
            && mb_check_encoding((string) $request->header('User-Agent', ''), 'UTF-8');

        abort_unless($wellFormed, 400, 'The request carried text that is not valid UTF-8.');

        return $next($request);
    }

    /** Every key and every value, however deep, is UTF-8. */
    private function isUtf8(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                return false;
            }

            if (is_array($value) ? ! $this->isUtf8($value) : (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))) {
                return false;
            }
        }

        return true;
    }
}
