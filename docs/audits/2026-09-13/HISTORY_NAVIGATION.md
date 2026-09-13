# Independent history navigation

13 September 2026 — follow-up to the finance and civic archive readers.

Independent Inertia partial updates leave other pagers' server-generated URLs unchanged. Following a stale URL could therefore reset another history's cursor or account/place selection in the address bar while its existing rows remained visible. HistoryPager now owns an explicit `cursor-key` and changes only that parameter in the current Inertia URL on same-route visits. First-page recovery removes only its own cursor. Different routes retain their original destination. Wallet, organization finance, public finance, setting history and the new committee/advocate readers use this behavior.

**4 actual compiled HistoryPager component tests passed**: alternating histories with stale links, current place/account retention, first-page recovery, partial-prop isolation, duplicate-click blocking, loading/error callbacks and rendered navigation/status semantics. The finance and committee render regression group passed with it: **10 component tests total**. Production Vite build was not run.

Read-only browser acceptance also completed against the populated local public-finance page: Next displayed loading feedback and advanced the ledger from first visible sequence 3516785 to 3516687; Previous restored 3516785. Selecting Poland reached “Public finance in Poland,” its public account and its child places. Wallet/help pages and their dedicated Learn drawers rendered earlier in the same pass. The signed-in operator had no personal wallet, so that browser check does not establish populated-wallet acceptance; private fixtures provide that reader coverage.

These are navigation and selected-reader checks. They do not establish planet-scale throughput, final assistive-device usability or full economic journeys.
