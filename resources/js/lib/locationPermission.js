const KEY = 'wos-location-disabled';
export function locationEnabled() { return localStorage.getItem(KEY) !== '1'; }
export function setLocationEnabled(enabled) { localStorage.setItem(KEY, enabled ? '0' : '1'); }
export function currentLocation(success, failure) {
    if (!locationEnabled()) { failure({ code: 1, appDisabled: true }); return; }
    navigator.geolocation.getCurrentPosition(success, failure, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
}
