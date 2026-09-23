/*
 * Mirrors Platforms::detect() in PHP. The host table itself comes from PHP
 * (the fieldtype's preload), longest host first, so only the matching rule
 * lives here: the host or any subdomain of it, `www.` stripped.
 */
export function detect(url, hosts) {
    if (typeof url !== 'string' || !/^https?:\/\/[^\s/?#]+/i.test(url.trim())) return null;

    let host;
    try {
        host = new URL(url.trim()).hostname.toLowerCase().replace(/\.$/, '').replace(/^www\./, '');
    } catch {
        return null;
    }

    for (const [known, platform] of Object.entries(hosts)) {
        if (host === known || host.endsWith('.' + known)) return platform;
    }

    return 'other';
}
