<?php

namespace App\Http\Controllers\Builder;

use App\Http\Controllers\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdCategory;
use App\Models\BuilderAd;
use App\Models\Organization;
use App\Services\TemplateCopier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Premium Templates (owner, 2026-10-07; docs/AD-BUILDER-SPEC.md, the addendum of that day): Create Ad, inside an organization,
 * asks which way the screen is and then Create Your Own or Premium Template — the platform's published ads for every
 * organization of that shape — and Use This Template makes one the organization's own, files and all (TemplateCopier).
 *
 * Inside an organization alone: above the organizations the platform's designs ARE the templates, and its Create Ad is as it
 * was. Both answers ask Create Ads, as making any ad does.
 */
class PremiumTemplateController extends Controller
{
    use ResolvesCurrentOrganization;

    /** The most a gallery lists at once, newest first; a search finds the rest. */
    private const LISTED = 120;

    /** The templates of one shape, as they were published: the name and poster an organization is shown, and its Preview. */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization();
        $filters = $request->validate([
            'orientation' => ['bail', 'required', 'string', 'in:'.BuilderAd::LANDSCAPE.','.BuilderAd::PORTRAIT],
            'search' => ['nullable', 'string', 'max:255'],
            // The gallery's Category list (owner, 2026-10-22): the templates in one category of the platform's list.
            'category' => ['nullable', 'integer', 'min:1'],
        ], [
            'orientation.required' => 'Choose which way the screen is first.',
            'orientation.in' => 'Choose which way the screen is first.',
            'category.integer' => 'Choose a category from the list.',
            'category.min' => 'Choose a category from the list.',
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $templates = BuilderAd::premiumTemplates()
            ->where('orientation', $filters['orientation'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereContains('published_name', $search)
                ->orWhere(fn (Builder $query) => $query->whereNull('published_name')->whereContains('name', $search))))
            ->when(isset($filters['category']), fn (Builder $query) => $query->whereHas('categories', fn (Builder $query) => $query->whereKey((int) $filters['category'])))
            // Everything the poster's address is made of: a published page's poster is drawn again in place on every publish,
            // so its address carries the page's version (`type`, `updated_at`) — without it a browser kept the old poster for
            // as long as the server lets it cache one, a week.
            ->with(['media:id,type,disk,thumbnail_path,updated_at', 'categories:id,name'])
            ->latest('published_at')
            ->limit(self::LISTED)
            ->get(['id', 'name', 'published_name', 'orientation', 'media_id', 'published_at']);

        return response()->json([
            // Premium Templates locked for the organization (docs/BILLING-SPEC.md §6): the gallery still shows every template and
            // its Preview, with Contact Us to Unlock in place of Use This Template.
            'locked' => ! $organization->premium_templates_unlocked,
            'organization_name' => $organization->name,
            'templates' => $templates->map(fn (BuilderAd $template) => [
                'id' => $template->id,
                'name' => $template->published_name ?? $template->name,
                'orientation' => $template->orientation,
                'thumbnail_url' => $template->media?->thumbnail_url,
                'categories' => $template->categories->pluck('name')->all(),
                'preview_url' => route('builder.preview', $template),
            ])->all(),
        ]);
    }

    /**
     * Use This Template: the organization's own ad, opened in the editor — in the template's categories and those ticked in
     * Create Ad beside its shape (the ones still on the list, ten at most).
     */
    public function use(Request $request, BuilderAd $ad, TemplateCopier $copier): JsonResponse
    {
        $organization = $this->organization();
        $organizationId = $organization->id;
        $template = BuilderAd::premiumTemplates()->findOrFail($ad->id);

        abort_unless($organization->premium_templates_unlocked, 403,
            "Premium Templates are locked for {$organization->name}. Contact us to unlock them.");
        $asked = $request->validate([
            'categories' => ['bail', 'nullable', 'array', 'list', 'max:'.AdCategory::MAX_PER_AD],
            'categories.*' => ['bail', 'integer', 'min:1', 'distinct'],
        ], [
            'categories.array' => 'Choose the categories from the list.',
            'categories.list' => 'Choose the categories from the list.',
            'categories.max' => 'An ad may be in at most '.AdCategory::MAX_PER_AD.' categories.',
            'categories.*.integer' => 'Choose the categories from the list.',
            'categories.*.min' => 'Choose the categories from the list.',
            'categories.*.distinct' => 'Choose each category once.',
        ])['categories'] ?? [];
        $name = $template->published_name ?? $template->name;

        $copy = $copier->copy($template, $organizationId, BuilderAd::nameForCopy($name, $organizationId, keepItIfFree: true), auth()->id());
        $copy->categories()->sync(AdCategory::whereKey([...$template->categories()->pluck('ad_categories.id')->all(), ...$asked])
            ->orderBy('name')->limit(AdCategory::MAX_PER_AD)->pluck('id')->all());

        ActivityLog::record('ad.copied', $copy, "Made ad {$copy->name} from the premium template {$name}");

        return response()->json([
            'message' => 'Template copied to your ads',
            'ad' => ['id' => $copy->id, 'name' => $copy->name],
            'redirect' => route('builder.edit', $copy),
        ]);
    }

    /** The organization an organization's person works in; none above the organizations, where this page has no Premium Template. */
    private function organization(): Organization
    {
        abort_if(auth()->user()->globalRole() !== null, 404);

        return $this->currentOrganization();
    }
}
