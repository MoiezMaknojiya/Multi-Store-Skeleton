<?php

namespace Tests\Browser;

use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A field never takes more than the server keeps (owner, 2026-09-29: "phone number ki jitni bhi field ha us mein 10
 * se ziyada likhne hi naah do"): a phone and a ZIP code keep to their digits, ten at most, whether typed one key at
 * a time or pasted in their usual shape — and what the page sends is that clean value. Every other text field carries
 * the server's own maxlength (tests/Feature/System/EveryFieldSaysItsLimitTest.php holds every page to it).
 *
 * The keys are typed the way a keyboard types them: a character added, then the `input` event — every one, so a
 * dropped keystroke of the driver can never make a pass or a failure out of luck.
 */
class FieldLimitsTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_sign_up_phone_and_zip_code_keep_to_ten_digits(): void
    {
        $this->browse(function (Browser $browser) {
            $this->freshSession($browser);
            $browser->visit('/register');
            $this->waitForAlpine($browser);

            // Letters and signs never appear, and the eleventh digit never does.
            $this->typeLikeAPerson($browser, '#phone', 'abc(555) 123-4567 89');
            $this->assertSame('5551234567', $browser->value('#phone'));

            // Pasted in its usual shape, a number keeps its digits.
            $this->pasteInto($browser, '#phone', '(555) 987-6543');
            $this->assertSame('5559876543', $browser->value('#phone'));

            $this->typeLikeAPerson($browser, '#zip_code', 'ZIP 90210-1234 5 6');
            $this->assertSame('9021012345', $browser->value('#zip_code'));

            // The text fields stop where the server does.
            $this->assertSame('255', $browser->attribute('#first_name', 'maxlength'));
            $this->assertSame('100', $browser->attribute('#suite', 'maxlength'));
        });
    }

    public function test_the_profile_phone_saves_the_ten_digits_it_shows(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/profile');
            $this->waitForAlpine($browser);

            $browser->script("document.querySelector('#phone').value = '';");
            $this->typeLikeAPerson($browser, '#phone', '+44 (777) 000-1111 22');
            $this->assertSame('4477700011', $browser->value('#phone'));

            // The form's own submit, as its Update button sends it.
            $browser->script("document.querySelector('#phone').form.requestSubmit();");
            $browser->waitUsing(10, 200, fn () => $owner->fresh()->phone === '4477700011');
        });
    }

    public function test_the_platform_organization_form_zip_code_reaches_the_form_clean(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $this->clickAndAwait($browser, '@add-organization', fn (Browser $b) => $b->waitFor('@organization-form', 3));

            $this->typeLikeAPerson($browser, '[dusk="organization-zip"]', '12ab34-5678-90123');
            $this->assertSame('1234567890', $browser->value('[dusk="organization-zip"]'));

            // What Alpine keeps — and so sends — is the clean value too.
            $this->assertSame('1234567890', $browser->script("return Alpine.\$data(document.querySelector('[dusk=\"organization-form\"]')).form.zip_code;")[0]);
        });
    }

    /** Type $text into the field one character at a time, each followed by its `input` event, as a keyboard does. */
    private function typeLikeAPerson(Browser $browser, string $selector, string $text): void
    {
        $field = json_encode($selector);
        $keys = json_encode($text);

        $browser->script(<<<JS
            const field = document.querySelector({$field});
            field.focus();
            for (const key of {$keys}) {
                field.value = field.value + key;
                field.dispatchEvent(new InputEvent('input', { bubbles: true, data: key, inputType: 'insertText' }));
            }
        JS);
    }

    /** Paste $text over the field's value, as a paste does: one change, one `input` event. */
    private function pasteInto(Browser $browser, string $selector, string $text): void
    {
        $field = json_encode($selector);
        $value = json_encode($text);

        $browser->script(<<<JS
            const field = document.querySelector({$field});
            field.focus();
            field.value = {$value};
            field.dispatchEvent(new InputEvent('input', { bubbles: true, data: {$value}, inputType: 'insertFromPaste' }));
        JS);
    }
}
