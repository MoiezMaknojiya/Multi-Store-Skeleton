<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * A page to read rather than to fill in — the privacy policy — open to everybody, signed in or not: one readable column
 * under the brand, in the panel's light or dark.
 */
class DocumentLayout extends Component
{
    /** The page's name for the browser tab ("Privacy Policy · The Display Solution"). */
    public function __construct(public string $title) {}

    public function render(): View
    {
        return view('layouts.document');
    }
}
