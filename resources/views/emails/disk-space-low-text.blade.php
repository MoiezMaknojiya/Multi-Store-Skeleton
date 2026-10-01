The server is running low on space.

The {!! config('app.name') !!} server has only {!! $free !!} of free space left.
Uploads still work for now, but they stop for every organization once only {!! $reserve !!} is left.

To make room before then, delete files nobody uses, or give the server a bigger disk.

{!! $url !!}

The disk is checked every hour. This warning is sent at most once every {{ $hours }} hours while less than {!! $warning !!} is free.

{!! config('app.name') !!}
{{-- Plain text, so every value is printed as it is, never HTML-escaped. --}}
