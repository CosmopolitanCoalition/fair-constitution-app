# Wave 4 — the multi-track video player, app-ported (lane 5)

_Lane 5 · L5W4 item ①. Handoffs to lane 6 (nav + Learn-flyout seam) and lane 11
(coverage report-back) are at the bottom._

## What landed

The operator's Coalition multi-track player (cosmopolitancoalition.org ·
`functions/video_player.php`), wired into the app as a real surface. ONE silent
master video + a per-language audio track kept in sync + per-language captions,
so one film speaks many languages without rendering many films.

| Artifact | What it is |
|---|---|
| `scripts/i18n/build_media_registry.mjs` | Generator — reads lane 11's `subjects.json` + `languages.json` (+ manifests for duration), emits the two artifacts below. Re-run when the coalition library changes. `--check` for staleness. |
| `config/cga/media.php` | GENERATED server registry — 61 videos × 77 languages. `base_url` is env-driven (`CGA_MEDIA_BASE_URL`); null → poster placeholder. |
| `resources/js/registry/media.js` | GENERATED client mirror (the player + library page render from it). |
| `app/Support/MediaMeta.php` | Resolver, modelled on `SurfaceMeta` — `for($id)` / `all()` / `languages()` / `baseUrl()`; throws on unknown id. |
| `resources/js/Components/Media/MultiTrackVideoPlayer.vue` | The player. Real `<video>` master + drift-corrected `<audio>` dub + fetched `.vtt` cues; link audio↔captions; remembers the viewer's language; graceful poster placeholder when no media host. |
| `resources/js/Pages/Learn/VideoLibrary.vue` | The surface (`/videos`) — featured player + the 61-film library + "how it works". |
| `app/Http/Controllers/Media/VideoLibraryController.php` + route `/videos` | Public, no auth. |

Styling is the shipped `.vplayer*` / `.vposter--*` / `.vtrack*` set in
`components-v2.css` — this port adds none, exactly as lane 11's APP_PORT_NOTE
predicted ("Nothing needs styling. Only a Vue component and the data are
missing.").

## The five gotchas (lane 11's APP_PORT_NOTE §5), all enforced + pinned

Pinned in `tests/Feature/VideoLibraryPageTest.php`:

1. **`<track srclang>` = `bcp47`, never `wp_code`.** The registry carries the
   language's `bcp47`; a FLORES/639-2 code (`zho_Hans`) never reaches a track.
2. **`token` ⇏ `subject`.** Both come from `subjects.json`; the subject keeps
   its spaces ("Affiliate Report"), the token its hyphens — never derived.
3. **`cmn-Hans` (track) vs `zh-Hans` (app locale).** The Chinese track code is
   the BCP-47 tag; it carries `locale: "zh-Hans"` so the player links it to a
   zh-Hans reader. Both distinct, both present.
4. **A language with no media yields no track.** Coverage is the `in_library`
   set from `languages.json`; `ht` (in the old fixture's `CAP_FULL`, no media)
   never appears.
5. **Per-segment encoding.** The player uses `encodeURIComponent` per path
   segment, never `encodeURI` on the whole path, never `+` for space.

## Media serving

No media ships in the repo. Set `CGA_MEDIA_BASE_URL` to the host that serves the
coalition `Subjects/` tree, and the player switches from the labelled poster
placeholder to real playback with zero code change. Paths built by the player:

```
{base}/Subjects/{Subject}/{Subject}-Silent.mp4          (master)
{base}/Subjects/{Subject}/audio/{Subject}-{Name}.m4a    (dub — Name = English language name)
{base}/Subjects/{Subject}/captions/{Subject}-{Name}.vtt (captions)
```

Real playback of a remote host needs CORS on that host (the `<audio>` and the
`.vtt` fetch are cross-origin). That is a host-config concern for whoever sets
`CGA_MEDIA_BASE_URL`, not an app change.

---

## Handoff → lane 6 (page markup — not lane 5's to edit)

Two optional enhancements, both touching files lane 6 owns:

1. **Nav discoverability.** `/videos` is reachable by URL and linked from the
   translation board ("See the video library"). A sidebar/nav entry (the nav
   registry) would surface it — lane 6's call. Suggested nav id: `video-library`
   under the Learn group (the mockup's `CGA_PAGE.nav`).

2. **The Learn-flyout video seam** (lane 11's APP_PORT_NOTE §1). The port
   flattened `LEARN_BY_MODULE` from `{video, about}` objects to bare strings
   (`resources/js/registry/surfaces.js`). Restoring the object shape + a
   `typeof === 'string'` branch in `LearnFlyout.vue:67-75` lets the Learn drawer
   show "▶ Watch the guide" linking into `/videos` for the surface's video. The
   player component is ready to embed; only the pointer data + the drawer branch
   are lane 6's. Incremental — a string entry keeps working until upgraded.

---

## Report-back → lane 11

- **All five gotchas honored and pinned** (above). Thank you for the interface
  doc — it made this a wiring job, not a rebuild.
- **Citations in the MT pass are already protected.** Your §6 flag about
  `i18n/index.js:58` conflates two guards: that `ID_TOKEN` is the **en-XA
  pseudo-locale** dev tool, not the MT path. The MT pass masks citations before
  the model sees them — `translate_catalog.py` `_MASKABLE` includes `CITATION`
  (`Art. … §…`), and the `qa()` rail rejects any output whose citation set
  changed. Pinned in `translate_catalog.py`'s self-test ("mask hides the
  citation from the model"). So `Art. III §6 → Artículo III §6` cannot ship. I
  left the pseudo-locale guard untouched to keep the i18n core stable.
- **Term count 36 vs 38.** Acknowledged — `Public record` and `Testimony` carry
  no rendering in any locale, and there are still no `fr`/`pt` renderings for the
  base. These are reader-verified glossary content (never machine-filled as
  settled, per the rail), tracked as translation work, not a code gap.
- **Coverage source.** For the 59 subjects without a local manifest, coverage is
  `subjects.json`'s declared 77 (uniform) intersected with `languages.json`'s
  `in_library` set — not per-subject manifest-verified. When you ship more
  manifests, re-running the generator narrows any subject that carries fewer.

---

## Note on item ⑤ (fr/pt chrome) — the rail, not a gap to paper over

The 6 product locales are now switchable (fr/pt were `enabled: false`). Their
page namespaces render; the **V1-shell monolithic chrome** (nav/header/footer)
for fr/pt is the remaining gap. `scripts/i18n/translate_monolith.py` (the
mechanism) exists, but NLLB-600M produces confidently-wrong context-free nav
labels ("Home" → "À la maison", the residence, not "Accueil") that the QA rail
cannot catch and that render **unvetted** in the always-on frame. Per the
standing rail — refusal is the answer, a stronger model or a reader is the path,
never a weakened rail — the raw output is withheld. The proper fix is to bring
the monolithic chrome into the reader-verification queue (it is not there today,
for any locale) so drafts render-and-review like the page catalogs; that is a
`TranslationReviewService` change, flagged for a later wave.
