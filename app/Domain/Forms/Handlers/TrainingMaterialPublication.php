<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Education\EducationCatalogService;
use Illuminate\Support\Facades\DB;

/**
 * F-EDU-002 — Training Material Publication (R-23). Art. III §5.
 *
 * The content plane's act, distinct from the learner's (F-EDU-001):
 * publishing or revising a training module under the public-domain
 * dedication. Filed by the authoring body's agent — per operator ruling 3
 * (2026-07-25) the authoring org is the Cosmopolitan Coalition of United
 * Earth (the Δ4 bridge, K2_ENGINE_PLAN §7).
 *
 * The IP dedication itself goes through CgcIpRegisterService::dedicate()
 * and NOTHING else — that service's dedicate-only surface is pinned by
 * CgcIpPublicDomainTest and this handler extends the contract, never
 * bypasses it. This filing records the publication act, carries the
 * dedication's register reference, AND writes the education_modules row
 * (LE-2, operator ruling rubric lesson-publication-shape = A: structural
 * publish). The write goes through EducationCatalogService — the single
 * owner of every education-table write — inside the engine's one
 * transaction, so it rolls back with any rejection and, on a demo box, is
 * captured against the demo session (education_modules is NOT
 * capture-excluded). Lesson PROSE is NOT written here: it stays in the K-2
 * source and the generated registry, keyed by surface_id.
 *
 * This act is the content plane's. It awards NOTHING and pays NOTHING — the
 * achievement and the civic stipend belong to the learner's F-EDU-001, never
 * to publication.
 *
 * What it never records (K2_ENGINE_PLAN §5.0): the question bank's
 * correct_keys — the answer catalog is server-side only, and a payload
 * carrying any answer-key spelling is refused outright.
 */
class TrainingMaterialPublication implements FormHandler
{
    /** Payload keys that must never approach the chain (K2_ENGINE_PLAN §2/§5.0). */
    private const FORBIDDEN_KEYS = ['correct_keys', 'answer_key', 'answers'];

    private const ACTIONS = ['publish', 'revise'];

    private const STATUSES = ['draft', 'live'];

    public function __construct(private readonly EducationCatalogService $catalog) {}

    public function module(): string
    {
        return 'education';
    }

    public function event(): string
    {
        return 'education.material_published';
    }

    public function requiredRoles(): array
    {
        return ['R-23'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'Publication is the authoring body\'s agent\'s act — system filing is not defined.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        $this->refuseAnswerContent($payload);

        $moduleKey = trim((string) ($payload['module_key'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $action = trim((string) ($payload['action'] ?? ''));

        if ($moduleKey === '' || $title === '') {
            throw new ConstitutionalViolation(
                'A publication names its module and title.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        if (! in_array($action, self::ACTIONS, true)) {
            throw new ConstitutionalViolation(
                'A publication is a publish or a revise.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        // The extra content-write keys (LE-2). Every DB touch sits BELOW every
        // shape refusal, so the refusal paths stay storage-free.
        $trackKey = trim((string) ($payload['track_key'] ?? ''));
        $surfaceId = trim((string) ($payload['surface_id'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $minutes = $payload['minutes'] ?? null;

        if ($trackKey === '') {
            throw new ConstitutionalViolation(
                'A publication names the track it belongs to.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        if (! in_array($status, self::STATUSES, true)) {
            throw new ConstitutionalViolation(
                'A module is published as a draft or as live.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        if ($minutes !== null && ! is_int($minutes)) {
            throw new ConstitutionalViolation(
                'A module\'s minutes are a whole number, or omitted.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        // The surface must be a registered CGA surface — a module points at a
        // real screen's Learn content, never an invented id.
        if (! is_array(config("cga.surfaces.{$surfaceId}"))) {
            throw new ConstitutionalViolation(
                'A module points at a registered surface — add it to config/cga/surfaces.php first.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        // The named track must exist. A publication cannot orphan a module.
        $trackExists = DB::table('education_tracks')
            ->where('key', $trackKey)->whereNull('deleted_at')->exists();

        if (! $trackExists) {
            throw new ConstitutionalViolation(
                'This track does not exist — a module joins a published track.',
                'CGA Forms Catalog (F-EDU-002)'
            );
        }

        // A revise names a module that was actually published. There is
        // nothing to revise otherwise.
        if ($action === 'revise') {
            $moduleExists = DB::table('education_modules as m')
                ->join('education_tracks as t', 't.id', '=', 'm.track_id')
                ->where('t.key', $trackKey)->whereNull('t.deleted_at')
                ->where('m.key', $moduleKey)->whereNull('m.deleted_at')
                ->exists();

            if (! $moduleExists) {
                throw new ConstitutionalViolation(
                    'This module was never published — a revise names an existing module.',
                    'CGA Forms Catalog (F-EDU-002)'
                );
            }
        }

        $dedicationRef = trim((string) ($payload['ip_register_entry_id'] ?? ''));

        // The single-owner content write, riding the engine's one transaction.
        $row = $this->catalog->publishModule(
            $moduleKey,
            $title,
            $action,
            $trackKey,
            $surfaceId,
            $minutes,
            $status,
            (string) $actor->getKey(),
            $dedicationRef !== '' ? $dedicationRef : null,
        );

        $record = [
            'module_key'      => $moduleKey,
            'title'           => $title,
            'action'          => $action,
            'track_key'       => $trackKey,
            'surface_id'      => $surfaceId,
            'status'          => $status,
            'revision_number' => (int) ($row['revision_number'] ?? 1),
        ];

        if ($dedicationRef !== '') {
            $record['ip_register_entry_id'] = $dedicationRef;
        }

        return $record;
    }

    /**
     * Refuse any payload carrying answer content at any depth — the
     * question bank never rides a publication filing (server-side catalog
     * only). Same posture as F-EDU-001's refusal; the engine's
     * SENSITIVE_KEYS guarantees even the rejection cannot leak a key.
     */
    private function refuseAnswerContent(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                throw new ConstitutionalViolation(
                    'A publication never carries the question bank\'s answers — the catalog is server-side only.',
                    'K2_ENGINE_PLAN §2 (the answer-key rail)'
                );
            }

            if (is_array($value)) {
                $this->refuseAnswerContent($value);
            }
        }
    }
}
