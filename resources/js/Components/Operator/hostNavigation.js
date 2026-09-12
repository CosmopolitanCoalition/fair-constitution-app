export const HOST_PAGES = {
    overview: { href: '/operator', group: 'overview', label: 'Overview' },
    roles: { href: '/operator/roles', group: 'roles', label: 'Capabilities' },
    mesh: { href: '/operator/mesh', group: 'network', label: 'Peers and sync' },
    federation: { href: '/operator/federation', group: 'network', label: 'Connections and access' },
    operations: { href: '/operator/operations', group: 'settings', label: 'Resources and services' },
    dns: { href: '/operator/dns', group: 'settings', label: 'DNS and certificates' },
    identity: { href: '/operator/identity', group: 'settings', label: 'Identity and devices' },
    versioning: { href: '/operator/versioning', group: 'settings', label: 'Versions and upgrades' },
    moderation: { href: '/operator/moderation', group: 'settings', label: 'Moderation' },
};

export const HOST_SECTIONS = [
    { key: 'overview', page: 'overview', label: 'Overview' },
    { key: 'roles', page: 'roles', label: 'Capabilities' },
    { key: 'network', page: 'mesh', label: 'Network' },
    { key: 'settings', page: 'operations', label: 'Host settings' },
];

export function hostSubpages(current) {
    const group = HOST_PAGES[current]?.group;
    const pages = Object.entries(HOST_PAGES).filter(([, page]) => page.group === group);
    return pages.length > 1 ? pages.map(([key, page]) => ({ key, ...page })) : [];
}
