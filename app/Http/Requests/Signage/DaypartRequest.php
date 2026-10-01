<?php

namespace App\Http\Requests\Signage;

use App\Models\Daypart;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share one set of rules — the only difference is that an edit
 * must not collide with its own name, which Rule::unique()->ignore() handles.
 */
class DaypartRequest extends FormRequest
{
    /**
     * A daypart is changed only from where it can be seen: another organization's is not found (404) — before
     * its name is checked, so "this organization already has a daypart with that name" never answers for an
     * organization the person does not work in.
     */
    public function authorize(): bool
    {
        $daypart = $this->route('daypart');

        abort_if($daypart instanceof Daypart && ! Daypart::visibleTo($this->user())->whereKey($daypart->id)->exists(), 404);

        return true;
    }

    /**
     * Empty rows and blank times come out of the UI as it is being filled in. They
     * are noise, not input, so they are cleaned off before the rules run rather than
     * being reported back as errors the person did not make.
     */
    protected function prepareForValidation(): void
    {
        $exceptions = collect((array) $this->input('exceptions', []))
            ->filter(fn (mixed $row) => is_array($row) && ! blank($row['weekday'] ?? null))
            ->map(fn (array $row) => [
                // A weekday that is not one plain value is left as it came, for `integer` to refuse.
                'weekday' => is_scalar($row['weekday']) ? (int) $row['weekday'] : $row['weekday'],
                'start_time' => blank($row['start_time'] ?? null) ? null : $row['start_time'],
                'end_time' => blank($row['end_time'] ?? null) ? null : $row['end_time'],
            ])
            ->values()
            ->all();

        $this->merge([
            'exceptions' => $exceptions,
            'is_retired' => $this->boolean('is_retired'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $daypart = $this->route('daypart');

        return [
            'name' => [
                'bail', 'required', 'string', 'max:100',
                Rule::unique('dayparts', 'name')
                    ->where(fn (QueryBuilder $query) => $query->where('organization_id', $this->organizationIdForRules()))
                    ->ignore($daypart instanceof Daypart ? $daypart->id : null),
            ],

            // Wall-clock, to the minute. An end BEFORE the start is allowed and means
            // the window crosses midnight; an end EQUAL to the start is not, because
            // there is no honest reading of it.
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],

            'is_retired' => ['boolean'],

            // At most one row per weekday, so seven is the ceiling.
            'exceptions' => ['array', 'max:7'],
            'exceptions.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],

            // Both times, or neither. Neither means the daypart is closed that day —
            // the one thing the base window cannot express.
            'exceptions.*.start_time' => ['nullable', 'date_format:H:i', 'required_with:exceptions.*.end_time'],
            'exceptions.*.end_time' => ['nullable', 'date_format:H:i', 'required_with:exceptions.*.start_time', 'different:exceptions.*.start_time'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This organization already has a daypart with that name.',
            'end_time.different' => 'The start and end time cannot be the same. To run past midnight, set an end time EARLIER than the start.',
            'exceptions.*.weekday.distinct' => 'Each day can only be listed once.',
            'exceptions.*.weekday.required' => 'Choose a day for this exception.',
            // The row itself offers "is closed" for a day with no hours: that is what the message points to.
            'exceptions.*.start_time.required_with' => 'Give both a start and an end time, or choose "is closed".',
            'exceptions.*.end_time.required_with' => 'Give both a start and an end time, or choose "is closed".',
            'exceptions.*.end_time.different' => 'The start and end time cannot be the same. To run past midnight, set an end time EARLIER than the start.',
        ];
    }

    /**
     * Which organization the name has to be unique within.
     *
     * On an edit it is the daypart's own organization, so a global user with no organization context
     * can still rename one. On a create it is the session's organization — and when there is
     * none the controller refuses the request outright with a message that explains
     * why, so the null here only has to avoid a false collision.
     */
    private function organizationIdForRules(): ?int
    {
        $daypart = $this->route('daypart');

        if ($daypart instanceof Daypart) {
            return $daypart->organization_id;
        }

        return ((int) session('current_organization_id')) ?: null;
    }
}
