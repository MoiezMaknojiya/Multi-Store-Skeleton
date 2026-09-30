{{-- Two traps for a robot filling in the sign-up form (owner, 2026-09-30: made-up names signed up all day, and each
     one sent a confirmation email to somebody's real address — RegisteredUserController::looksLikeARobot):
     a field no person sees, which a robot fills in with the rest, and the moment the form was opened, sealed
     (encrypted), so a form sent back sooner than a person could fill it in — or with no such moment — is refused.
     The field is display:none, so no person, keyboard or screen reader ever meets it. --}}
<div class="hidden">
    <label for="website">Website</label>
    <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off" maxlength="255">
</div>
<input type="hidden" name="form_started" value="{{ \Illuminate\Support\Facades\Crypt::encryptString((string) now()->getTimestamp()) }}">
