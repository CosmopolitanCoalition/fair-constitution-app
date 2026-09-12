import { EDUCATION_BY_SURFACE } from '../registry/education.js';

// Existing worlds retain the surface IDs used when their curricula were
// seeded. Resolve them to the current authored content without changing
// education records or exposing the server-only quiz answers.
const LEGACY_SURFACES = {
    'legislature/floor': 'legislature/session-console',
    'elections/board': 'elections/board-console',
    'executive/office': 'executive/executive-home',
    'judiciary/court': 'judiciary/judiciary-home',
    'judiciary/cases': 'judiciary/advocate-console',
};

export function lessonContentFor(surfaceId) {
    return EDUCATION_BY_SURFACE[surfaceId]
        ?? EDUCATION_BY_SURFACE[LEGACY_SURFACES[surfaceId]]
        ?? null;
}
