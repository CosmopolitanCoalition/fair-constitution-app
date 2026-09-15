/* Election kinds in plain words — mirrors Election::kindLabel() (PHP). Never show the raw enum. */
const LABELS = {
    general: 'general election',
    special: 'special election',
    executive: 'executive election',
    judicial: 'judicial election',
    referendum: 'referendum',
    org_board_owner: 'board election (owner seats)',
    org_board_worker: 'board election (worker seats)',
    restoration: 'restoration election',
};

/*
 * Pass the vue-i18n `t` to localize. Without it the English label is
 * returned, so a caller that has not yet wired i18n keeps working. Keys are
 * literal so the wiring pin can resolve them.
 */
export function electionKindLabel(kind, t) {
    const fallback = LABELS[kind] ?? `${String(kind ?? '').replace(/_/g, ' ')} election`;
    if (typeof t !== 'function' || !LABELS[kind]) return fallback;
    const localized = {
        general: t('c_gap_legislature.election_kind.general', 'general election'),
        special: t('c_gap_legislature.election_kind.special', 'special election'),
        executive: t('c_gap_legislature.election_kind.executive', 'executive election'),
        judicial: t('c_gap_legislature.election_kind.judicial', 'judicial election'),
        referendum: t('c_gap_legislature.election_kind.referendum', 'referendum'),
        org_board_owner: t('c_gap_legislature.election_kind.org_board_owner', 'board election (owner seats)'),
        org_board_worker: t('c_gap_legislature.election_kind.org_board_worker', 'board election (worker seats)'),
        restoration: t('c_gap_legislature.election_kind.restoration', 'restoration election'),
    };
    return localized[kind] ?? fallback;
}
