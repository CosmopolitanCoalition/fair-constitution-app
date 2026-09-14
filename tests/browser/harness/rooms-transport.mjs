// Browser review harness (R2 · rooms over REAL transport). Loads the REAL
// livekit-client SDK (node_modules, ^2.20) through the running Vite dev server
// and drives real Room.connect / publishTrack / subscription against the real
// SFU (fc_livekit). Every assertion the test makes reads the real WebRTC
// transport (RTCPeerConnection.iceConnectionState, getStats framesDecoded /
// bytesReceived, real RemoteTrack subscription) — never a synthetic track.
import * as LK from 'livekit-client';

// Capture EVERY RTCPeerConnection the SDK creates so the test can read the real
// ICE lifecycle (gathering/connection state, candidate errors) that decides
// whether media can flow at all from this network position.
window.__PCS__ = [];
const OrigPC = window.RTCPeerConnection;
function WrappedPC(...args) {
    const pc = new OrigPC(...args);
    const rec = { conn: [], ice: [], gather: [], candErr: [] };
    window.__PCS__.push(rec);
    pc.addEventListener('connectionstatechange', () => rec.conn.push(pc.connectionState));
    pc.addEventListener('iceconnectionstatechange', () => rec.ice.push(pc.iceConnectionState));
    pc.addEventListener('icegatheringstatechange', () => rec.gather.push(pc.iceGatheringState));
    pc.addEventListener('icecandidateerror', (e) => rec.candErr.push({ code: e.errorCode, url: e.url }));
    return pc;
}
WrappedPC.prototype = OrigPC.prototype;
window.RTCPeerConnection = WrappedPC;

// One generated video track (canvas.captureStream) + one generated audio track
// (AudioContext oscillator), created in THIS Chromium build. Real MediaStreamTracks.
function generatedTracks() {
    const c = document.createElement('canvas');
    c.width = 320; c.height = 180;
    const ctx = c.getContext('2d');
    let f = 0;
    const iv = setInterval(() => {
        ctx.fillStyle = `hsl(${(f * 12) % 360},70%,50%)`;
        ctx.fillRect(0, 0, 320, 180);
        ctx.fillStyle = '#fff';
        ctx.font = '48px sans-serif';
        ctx.fillText(String(f), 20, 110);
        f++;
    }, 66);
    const [video] = c.captureStream(15).getVideoTracks();
    const AC = window.AudioContext || window.webkitAudioContext;
    const ac = new AC();
    const dest = ac.createMediaStreamDestination();
    const osc = ac.createOscillator();
    osc.frequency.value = 330;
    osc.connect(dest);
    osc.start();
    const [audio] = dest.stream.getAudioTracks();
    return { video, audio, stop: () => { clearInterval(iv); try { osc.stop(); ac.close(); } catch { /* noop */ } } };
}

const rt = {
    LK,
    room: null,
    events: [],
    _gen: null,
    // Connect to the SFU. Records the disconnect reason label and returns the
    // signaling-layer outcome plus the reason connect() rejected (if it did).
    async connect(url, token, opts = {}) {
        this.room = new LK.Room({ adaptiveStream: false, dynacast: false });
        this.events = [];
        const ev = this.events;
        this.room.on('signalConnected', () => ev.push('signalConnected'));
        this.room.on('connectionStateChanged', (s) => ev.push('conn:' + s));
        this.room.on('trackSubscribed', (t, pub, p) => ev.push('trackSubscribed:' + p.identity + ':' + t.kind));
        this.room.on('participantConnected', (p) => ev.push('participantConnected:' + p.identity));
        this.room.on('disconnected', (reason) => {
            const name = LK.DisconnectReason ? (LK.DisconnectReason[reason] ?? String(reason)) : String(reason);
            ev.push('disconnected:' + name);
        });
        const out = { signalConnected: false, joined: false, identity: null, err: null };
        try {
            await this.room.connect(url, token, {
                peerConnectionTimeout: opts.peerConnectionTimeout ?? 10000,
                websocketTimeout: opts.websocketTimeout ?? 8000,
                autoSubscribe: true,
            });
            out.joined = true;
            out.identity = this.room.localParticipant?.identity ?? null;
        } catch (e) {
            out.err = String(e && (e.message || e));
            // localParticipant identity is assigned at the signaling join response,
            // before the PC step — so it survives a pc-timeout rejection.
            out.identity = this.room.localParticipant?.identity ?? null;
        }
        out.signalConnected = ev.includes('signalConnected');
        out.state = this.room.state;
        return out;
    },
    async publishGenerated() {
        this._gen = generatedTracks();
        const pubs = [];
        try {
            const vp = await this.room.localParticipant.publishTrack(this._gen.video, { name: 'r2-video' });
            pubs.push('video:' + (vp?.trackSid ?? 'sid?'));
        } catch (e) { pubs.push('video-err:' + String(e && e.message)); }
        try {
            const ap = await this.room.localParticipant.publishTrack(this._gen.audio, { name: 'r2-audio' });
            pubs.push('audio:' + (ap?.trackSid ?? 'sid?'));
        } catch (e) { pubs.push('audio-err:' + String(e && e.message)); }
        return pubs;
    },
    // Read inbound-rtp stats for every subscribed remote video track — the real
    // proof frames are arriving (framesDecoded / bytesReceived) from the peer.
    async inboundStats() {
        const rows = [];
        if (!this.room) return rows;
        for (const [, p] of this.room.remoteParticipants) {
            for (const [, pub] of p.trackPublications) {
                const track = pub.track;
                if (!track) { rows.push({ from: p.identity, kind: pub.kind, subscribed: false }); continue; }
                let report = null;
                try { report = await track.getRTCStatsReport(); } catch { /* noop */ }
                let framesDecoded = null; let bytesReceived = null;
                if (report) {
                    report.forEach((s) => {
                        if (s.type === 'inbound-rtp') {
                            if (typeof s.framesDecoded === 'number') framesDecoded = s.framesDecoded;
                            if (typeof s.bytesReceived === 'number') bytesReceived = s.bytesReceived;
                        }
                    });
                }
                rows.push({ from: p.identity, kind: pub.kind, subscribed: true, framesDecoded, bytesReceived });
            }
        }
        return rows;
    },
    pcSnapshot() {
        return window.__PCS__.map((p) => ({ conn: p.conn, ice: p.ice, gather: p.gather, candErr: p.candErr.slice(0, 4) }));
    },
    // True only when a real ICE connection completed (media can flow).
    iceConnected() {
        return window.__PCS__.some((p) => p.ice.includes('connected') || p.ice.includes('completed'));
    },
    async disconnect() {
        try { this._gen?.stop(); } catch { /* noop */ }
        try { await this.room?.disconnect(); } catch { /* noop */ }
    },
};

window.__rt = rt;
window.LK = LK;
window.__LK_READY__ = true;
