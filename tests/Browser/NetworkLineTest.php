<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * DuskTestCase::network() changes the line for real, every kind it offers, timed with real requests.
 *
 * The slow-line tests lean on it, and most of their own assertions also hold on a fast line (made a no-op on
 * 2026-10-15, the helper left 42 of the 44 tests that use it green; only the offline and the slow-upload ones went red).
 * So if Chrome ever stops honouring the commands — it deprecated Network.emulateNetworkConditions for the ones used
 * now — they would go on testing a fast line without a word. This is the word: it fails the moment a line is not what
 * was asked, offline included (a page told it is offline while its requests still went through would let an upload
 * test pass for the wrong reason).
 */
class NetworkLineTest extends DuskTestCase
{
    public function test_every_line_the_helper_offers_is_the_line_the_page_gets(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login');
            $script = $browser->script("return [...document.querySelectorAll('script[src]')].map((s) => s.src).find((s) => s.includes('/build/'));")[0];
            $get = fn (string $url) => $browser->script("const t = performance.now(); return fetch('{$url}' + (('{$url}'.includes('?')) ? '&' : '?') + 'n=' + Math.random(), { cache: 'no-store' }).then((r) => r.text()).then(() => Math.round(performance.now() - t)).catch((e) => 'failed: ' + e.name);")[0];
            $post = fn () => $browser->script("const t = performance.now(); return fetch('/up', { method: 'POST', body: new Uint8Array(200 * 1024) }).then(() => Math.round(performance.now() - t)).catch((e) => 'failed: ' + e.name);")[0];

            try {
                // Latency: every answer starts no sooner than asked, and it holds across a visit to another page.
                $this->network($browser, 1500);
                $this->assertGreaterThanOrEqual(1400, $get('/up'), 'a 1.5 s line answered sooner');
                $browser->visit('/register');
                $this->assertGreaterThanOrEqual(1400, $get('/up'), 'the slow line did not outlive a visit');

                // Download: about 220 KB at 50 KB a second takes some four seconds.
                $this->network($browser, download: 50 * 1024);
                $this->assertGreaterThanOrEqual(3000, $get($script), 'a 50 KB/s download came in faster');

                // Upload: 200 KB at 50 KB a second, the same.
                $this->network($browser, upload: 50 * 1024);
                $this->assertGreaterThanOrEqual(3000, $post(), 'a 50 KB/s upload went out faster');

                // Offline: the page is told so, and its requests really fail.
                $this->network($browser, offline: true);
                $this->assertFalse($browser->script('return navigator.onLine;')[0], 'offline, the page still thinks it is online');
                $this->assertSame('failed: TypeError', $get('/up'), 'offline, a request still went through');
            } finally {
                $this->network($browser);
            }

            // And back to a normal line, for whatever comes next.
            $this->assertTrue($browser->script('return navigator.onLine;')[0]);
            $this->assertLessThan(1400, $get('/up'), 'the line stayed slow after it was put back');
            $this->assertLessThan(3000, $post(), 'uploads stayed slow after the line was put back');
        });
    }
}
