<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdCategory;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Ad Builder → Categories (owner, 2026-10-22 — "category ya tag ka tab ho aur us mein mai tags ya category banao jese menu,
 * education, beer"): the platform's one list of categories, made, renamed and deleted above the organizations alone (the routes'
 * global-tier lock and the ad-category-* permissions). Every ad may be put in several, in Create Ad and in the editor; the Ads page
 * and the Premium Templates are filtered by them. Deleting a category takes it off every ad and deletes nothing else, so it is one
 * confirmation away.
 */
class AdCategoryController extends Controller
{
    use HandlesCrudData;

    public function index(): View
    {
        return view('builder.categories');
    }

    /** The list, A to Z, each with how many ads are in it. */
    public function data(Request $request): JsonResponse
    {
        return $this->paginatedResponse($request, AdCategory::query()->withCount('ads')->orderBy('name')->orderBy('id'), ['name'], 'categories');
    }

    public function store(Request $request): JsonResponse
    {
        $name = $this->validatedName($request);

        $category = $this->onceAName(fn () => AdCategory::create(['name' => $name, 'created_by' => auth()->id()]));

        ActivityLog::record('ad_category.created', $category, "Created the ad category {$category->name}");

        return response()->json(['message' => "Category {$category->name} added", 'category' => $category]);
    }

    public function update(Request $request, AdCategory $category): JsonResponse
    {
        $name = $this->validatedName($request, $category);
        $before = $category->name;

        if ($name !== $before) {
            $this->onceAName(fn () => $category->update(['name' => $name]));

            ActivityLog::record('ad_category.updated', $category, "Renamed the ad category {$before} to {$category->name}");
        }

        return response()->json(['message' => 'Category saved', 'category' => $category]);
    }

    /** Off every ad it is on, and gone: the ads themselves stay as they are (the pivot's foreign key cascades). */
    public function destroy(AdCategory $category): JsonResponse
    {
        $ads = $category->ads()->count();
        $category->delete();

        ActivityLog::record('ad_category.deleted', null, "Deleted the ad category {$category->name}".($ads > 0 ? " (taken off {$ads} ".($ads === 1 ? 'ad' : 'ads').')' : ''));

        return response()->json(['message' => "Category {$category->name} deleted"]);
    }

    /**
     * The same name typed in two tabs at once passes both checks below and meets the table's unique key in the second: said in
     * the form's words, never the database's 500.
     */
    private function onceAName(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'There is a category with that name already.']);
        }
    }

    /**
     * A name new to the list, compared with accents, case, spacing and punctuation folded away ("menu " is "Menu"), spaces
     * collapsed as it is kept.
     */
    private function validatedName(Request $request, ?AdCategory $category = null): string
    {
        $validated = $request->validate([
            'name' => ['bail', 'required', 'string', 'max:'.AdCategory::MAX_NAME,
                function (string $attribute, mixed $value, Closure $fail) use ($category) {
                    $folded = AdCategory::folded($value);

                    if ($folded === '') {
                        $fail('Give the category a name with letters or numbers in it.');

                        return;
                    }

                    $taken = AdCategory::query()->when($category, fn (Builder $query) => $query->whereKeyNot($category->id))->get(['id', 'name'])
                        ->first(fn (AdCategory $other) => AdCategory::folded($other->name) === $folded);

                    if ($taken !== null) {
                        $fail("There is a category called {$taken->name} already.");
                    }
                },
            ],
        ], [
            'name.required' => 'Give the category a name.',
            'name.max' => 'A category name is '.AdCategory::MAX_NAME.' characters at most.',
            'name.string' => 'Give the category a name.',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $validated['name']));
    }
}
