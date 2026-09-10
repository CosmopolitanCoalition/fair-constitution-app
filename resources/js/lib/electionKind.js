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
export function electionKindLabel(kind) {
    return LABELS[kind] ?? `${String(kind ?? '').replace(/_/g, ' ')} election`;
}
