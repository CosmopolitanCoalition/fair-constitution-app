<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\TranslationVerification;
use App\Services\TranslationReviewService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase N (lane 5) — /system/translations/review/{locale}
 *
 * The language-detail surface from mockups/v3/translation/language.html: the
 * six kinds of content for one language, then a review queue for whichever
 * kind you picked. Every string shows the English source and the machine's
 * first draft side by side — a person never starts from a blank box, they
 * correct a draft.
 *
 * WHO MAY VERIFY. Readers of the language, per the design: "verified BY THE
 * PEOPLE WHO READ IT in that language". Someone who cannot read Arabic cannot
 * usefully confirm Arabic, so the right follows the reader rather than a title
 * — a person whose interface language is this locale, or who lists it among
 * their languages. The operator may always verify, because someone has to be
 * able to unstick a locale nobody has joined yet.
 *
 * This gates being USEFUL, not a right: it takes nothing from anyone, and any
 * person can grant it to themselves by naming the language on their record.
 */
class TranslationReviewController extends Controller
{
    public function __construct(private readonly TranslationReviewService $review)
    {
    }

    public function show(Request $request, string $locale): Response
    {
        $registry = config('locales.locales', []);
        abort_unless(isset($registry[$locale]), 404, "Unknown locale [{$locale}].");

        $modality = (string) $request->query('m', 'ui');
        $ids = array_column(TranslationReviewService::MODALITIES, 'id');
        if (! in_array($modality, $ids, true)) {
            $modality = 'ui';
        }

        $queue = $this->review->queue($locale, $modality);

        return Inertia::render('System/TranslationReview', [
            'surface'      => SurfaceMeta::for('system/translation-review'),
            /*
             * `language`, not `locale`: HandleInertiaRequests already shares a
             * global `locale` prop (the app's current UI locale, a string). A
             * page prop of the same name silently shadows it, which would make
             * this one page lie to anything that later reads it.
             */
            'language'     => ['code' => $locale] + $registry[$locale],
            'modality'     => $modality,
            'modalities'   => TranslationReviewService::MODALITIES,
            'states'       => TranslationReviewService::STATES,
            'row'          => $this->review->row($locale, $queue),
            'queue'        => $queue['items'],
            'counts'       => $queue['counts'],
            'terms'        => $this->review->terms($locale),
            'contributors' => $this->review->contributors($locale),
            'quorum'       => TranslationVerification::QUORUM,
            'canVerify'    => $this->canVerify($request, $locale),
        ]);
    }

    public function store(Request $request, string $locale): RedirectResponse
    {
        if (! $this->canVerify($request, $locale)) {
            throw ValidationException::withMessages([
                'verdict' => 'Only people who read this language can verify it. '
                    . 'Add it to your languages on your record and you can help.',
            ]);
        }

        $data = $request->validate([
            'namespace'   => ['required', 'string', 'max:64'],
            'key'         => ['required', 'string', 'max:191'],
            'source_hash' => ['required', 'string', 'size:64'],
            'verdict'     => ['required', Rule::in(TranslationVerification::VERDICTS)],
            'machine'     => ['nullable', 'string'],
            'text'        => ['nullable', 'string', 'max:5000'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ]);

        // An edit that changes nothing is an approval; recording it as an edit
        // would claim a correction that never happened.
        if ($data['verdict'] === TranslationVerification::VERDICT_EDITED
            && trim((string) ($data['text'] ?? '')) === trim((string) ($data['machine'] ?? ''))) {
            $data['verdict'] = TranslationVerification::VERDICT_APPROVED;
        }

        if ($data['verdict'] === TranslationVerification::VERDICT_EDITED
            && trim((string) ($data['text'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'text' => 'An edit needs the wording you want instead.',
            ]);
        }

        $this->review->record($data + ['locale' => $locale], (string) $request->user()->id);

        return back();
    }

    /**
     * Readers of the language, plus the operator.
     *
     * `users.locale` is the interface they chose; `users.languages` is what
     * they told us they read. Either is evidence enough — this gates
     * usefulness, not a right, and a person can grant it to themselves.
     */
    private function canVerify(Request $request, string $locale): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }
        if ($user->is_operator) {
            return true;
        }
        if ((string) $user->locale === $locale) {
            return true;
        }

        $languages = $user->languages ?? [];

        return is_array($languages) && in_array($locale, $languages, true);
    }
}
