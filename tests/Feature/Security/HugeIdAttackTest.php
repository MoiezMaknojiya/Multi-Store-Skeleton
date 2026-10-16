<?php

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| An id no int can hold
|--------------------------------------------------------------------------
|
| The dashboard's "Invitations for you" answers take the invitation's id as an int (InvitationResponseController::acceptHere,
| declineHere), behind a route that let any run of digits through: 99999999999999999999 reached the int parameter as a
| TypeError, a 500. The route now takes an id an int can hold (at most 18 digits, no leading zero), and anything else is a
| 404 like any invitation that is not there.
|
*/

test('the dashboard answers an invitation id no int can hold with 404, never 500', function () {
    $person = User::factory()->create(['email' => 'sana@example.com']);
    $invitation = Invitation::factory()->create(['organization_id' => Organization::factory()->create()->id, 'email' => 'sana@example.com']);
    $this->actingAs($person);

    foreach (['99999999999999999999', (string) PHP_INT_MAX, '9223372036854775808', str_repeat('9', 40), '0', '0'.$invitation->id, '999999999999999999'] as $id) {
        foreach (['accept', 'decline'] as $answer) {
            expect($this->post("/dashboard/invitations/{$id}/{$answer}")->status())->toBe(404, "{$answer} {$id}")
                ->and($this->postJson("/dashboard/invitations/{$id}/{$answer}")->status())->toBe(404, "{$answer} {$id} as JSON");
        }
    }

    // The real one still answers, and nobody joined anything on the way.
    expect(DB::table('organization_user')->where('user_id', $person->id)->exists())->toBeFalse();
    $this->post("/dashboard/invitations/{$invitation->id}/decline")->assertRedirect(route('dashboard'));
    expect(Invitation::count())->toBe(0);
});
