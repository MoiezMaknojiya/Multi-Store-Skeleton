Uploads are paused: the server is almost full.

The server has only {!! $free !!} of free space left, and {!! config('app.name') !!} keeps at least {!! $reserve !!} free for itself.
Until there is more room, every upload is refused: pictures, videos, Ad Builder files and adverts.

Screens keep playing what they already have. To make room, delete files nobody uses, or give the server a bigger disk.

{!! $url !!}

This warning is sent at most once every {{ $hours }} hours while the disk stays this full.

{!! config('app.name') !!}
{{-- Plain text, so every value is printed as it is, never HTML-escaped. --}}
