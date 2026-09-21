import { ref } from 'vue';

export const installAvailable = ref(false);
export const appInstalled = ref(window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true);
let installPrompt = null;
window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
    installAvailable.value = true;
});
window.addEventListener('appinstalled', () => {
    appInstalled.value = true;
    installAvailable.value = false;
    installPrompt = null;
});
export async function installApp() {
    if (!installPrompt) return;
    const prompt = installPrompt;
    installPrompt = null;
    installAvailable.value = false;
    await prompt.prompt();
    await prompt.userChoice;
}
export function registerAppWorker() {
    if (window.isSecureContext && 'serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' })
            .catch(error => console.warn('App background support is unavailable', error));
    }
}
