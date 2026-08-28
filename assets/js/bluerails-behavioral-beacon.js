/**
 * BLUE-1474 — client-side behavioral signal beacon.
 *
 * Runs in the visitor's actual rendered browser session (enqueued via wp_footer). Its ONLY job
 * is to observe mouse-movement timing/quantization and POST a compact FEATURE SUMMARY — never a
 * verdict — to this site's own REST proxy (`class-bluerails-behavioral-beacon.php`), which
 * forwards it server-side to the configured Bluerails ingest endpoint. The API key never reaches
 * this file or the browser: it lives only in wp-admin options and is attached server-side by the
 * PHP proxy, exactly like every other payload this plugin sends.
 *
 * WHY A REST PROXY, NOT A DIRECT POST TO THE INGEST ENDPOINT: the existing plugin's server-side
 * paths (bot-UA, referer) can attach the API key in PHP because they never touch the browser.
 * This is the first BEHAVIORAL signal, i.e. the first one that can only be observed client-side —
 * POSTing directly from here to the Bluerails endpoint would mean putting the Bearer API key in
 * this file or in a localized JS variable, visible to any visitor via view-source or the network
 * tab. Routing through this site's own REST route keeps the key server-side, unchanged from the
 * plugin's existing trust model.
 *
 * CONSENT (ticket AC, EDPB Guidelines 2/2023 §52-53 — the hotel is the data controller for its own
 * visitors, not Bluerails): this beacon does NOT run at all unless the site's Complianz consent
 * manager (the WP ecosystem's most-installed CMP, with a documented public JS API — see
 * https://complianz.io/developers-guide-for-third-party-integrations/) reports 'statistics'
 * consent already granted, or grants it later in the SAME page view. No Complianz on this site at
 * all means no beacon ever runs here — fail closed, never assume consent. Support for other CMPs
 * (CookieYes, Borlabs, etc.) is intentionally out of scope for this ticket; see the PR body.
 *
 * SCOPE: a HEURISTIC, not a trained classifier. `pointer_capable` (matchMedia('(pointer: fine)'))
 * is reported so the SERVER can refuse to score pointer-absence as a bot signal on an ordinary
 * mobile/touch session — this file does no scoring or gating of its own beyond consent and the
 * dwell-time floor below; every confidence decision happens server-side (ingest.ts's
 * computeBehavioralScore), which is also where BEHAVIORAL_HEURISTIC_ENABLED (default OFF) lives.
 */
( function () {
	'use strict';

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) {
		return;
	}

	var config = window.bluerailsBehavioralBeacon;
	if ( ! config || ! config.restUrl ) {
		return; // Not enqueued with a valid config — nothing to do.
	}

	// A visit shorter than this never sends: avoids a POST for every instant bounce, and gives the
	// mousemove sampler a realistic minimum window to have observed SOMETHING either way. Purely a
	// noise/cost reduction, not a security or consent boundary (the server re-validates everything).
	var MIN_DWELL_MS = 1500;
	var MAX_OBSERVE_MS = 8000; // stop observing (and send) after this even on a long-lived page view
	var SAMPLE_MIN_INTERVAL_MS = 40; // throttle mousemove sampling — no need for every raw event
	var MAX_SAMPLES = 200;
	var QUANTIZE_STEP = 0.25; // px — the cursor_v2 sub-pixel signature independent review confirmed (claim 1)
	var QUANTIZE_EPSILON = 0.01; // float tolerance for the modulo check below

	var started = false;
	var sent = false;
	var startedAtMs = 0;
	var lastSampleAtMs = 0;
	var moveCount = 0;
	var quantizedCount = 0;
	var intervals = []; // ms between consecutive samples
	var sendTimer = null;

	function pointerCapable() {
		return typeof window.matchMedia === 'function' && window.matchMedia( '(pointer: fine)' ).matches;
	}

	function isQuantized( delta ) {
		if ( delta === 0 ) {
			return false; // no movement at all is not a quantization signal, just absence
		}
		var remainder = Math.abs( delta ) % QUANTIZE_STEP;
		return remainder < QUANTIZE_EPSILON || Math.abs( remainder - QUANTIZE_STEP ) < QUANTIZE_EPSILON;
	}

	function onMouseMove( evt ) {
		var now = Date.now();
		if ( now - lastSampleAtMs < SAMPLE_MIN_INTERVAL_MS ) {
			return;
		}
		if ( lastSampleAtMs !== 0 ) {
			intervals.push( now - lastSampleAtMs );
		}
		lastSampleAtMs = now;
		moveCount += 1;

		// movementX/Y are the per-event browser-reported delta, the closest analogue available to
		// the raw synthesized-path deltas the cursor_v2 methodology examines — clientX/Y alone are
		// already rounded to integer CSS pixels by the time script sees them, so a modulo check on
		// clientX/Y would never see a fractional 0.25px signature at all.
		var dx = typeof evt.movementX === 'number' ? evt.movementX : 0;
		var dy = typeof evt.movementY === 'number' ? evt.movementY : 0;
		if ( isQuantized( dx ) || isQuantized( dy ) ) {
			quantizedCount += 1;
		}

		if ( moveCount >= MAX_SAMPLES ) {
			finish();
		}
	}

	function mean( values ) {
		if ( values.length === 0 ) {
			return 0;
		}
		var sum = 0;
		for ( var i = 0; i < values.length; i++ ) {
			sum += values[ i ];
		}
		return sum / values.length;
	}

	function stddev( values, avg ) {
		if ( values.length < 2 ) {
			return 0;
		}
		var variance = 0;
		for ( var i = 0; i < values.length; i++ ) {
			variance += Math.pow( values[ i ] - avg, 2 );
		}
		return Math.sqrt( variance / values.length );
	}

	function finish() {
		if ( sent ) {
			return;
		}
		sent = true;
		document.removeEventListener( 'mousemove', onMouseMove );
		if ( sendTimer ) {
			clearTimeout( sendTimer );
			sendTimer = null;
		}

		var durationMs = Date.now() - startedAtMs;
		if ( durationMs < MIN_DWELL_MS ) {
			return; // too short a visit to bother sending — see MIN_DWELL_MS comment above
		}

		var avgIntervalMs = mean( intervals );
		var payload = {
			page_path: window.location.pathname || '/',
			timestamp: new Date().toISOString(),
			site_url: window.location.origin,
			behavioral: {
				pointer_capable: pointerCapable(),
				move_count: moveCount,
				duration_ms: durationMs,
				avg_interval_ms: avgIntervalMs,
				interval_stddev_ms: stddev( intervals, avgIntervalMs ),
				quantized_ratio: moveCount > 0 ? quantizedCount / moveCount : 0,
			},
		};

		var body = JSON.stringify( payload );
		if ( typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function' ) {
			// sendBeacon survives page unload, the exact moment `finish()` is most likely to run
			// from a pagehide/visibilitychange listener — fetch+keepalive is the fallback for
			// browsers where sendBeacon can't set a JSON content-type reliably.
			var blob = new Blob( [ body ], { type: 'application/json' } );
			var ok = navigator.sendBeacon( config.restUrl, blob );
			if ( ok ) {
				return;
			}
		}
		if ( typeof fetch === 'function' ) {
			fetch( config.restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: body,
				keepalive: true,
			} ).catch( function () {
				// Best-effort only — a dropped behavioral ping is not actionable client-side, and
				// this must never surface an error to the visitor.
			} );
		}
	}

	function start() {
		if ( started ) {
			return;
		}
		started = true;
		startedAtMs = Date.now();
		document.addEventListener( 'mousemove', onMouseMove, { passive: true } );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'hidden' ) {
				finish();
			}
		} );
		window.addEventListener( 'pagehide', finish, { once: true } );
		sendTimer = setTimeout( finish, MAX_OBSERVE_MS );
	}

	function hasComplianz() {
		return typeof window.cmplz_has_consent === 'function';
	}

	function consentGate() {
		if ( ! hasComplianz() ) {
			return; // No known CMP on this site — fail closed, never assume consent (ticket AC).
		}
		try {
			if ( window.cmplz_has_consent( 'statistics' ) ) {
				start();
				return;
			}
		} catch ( e ) {
			return; // A throwing CMP integration must never start the beacon.
		}
		// Consent not yet granted at page-load — Complianz's documented event API. `jQuery` is
		// Complianz's own dispatch mechanism for these events; guard for it not being present
		// rather than assuming jQuery is loaded on every WP theme.
		if ( typeof window.jQuery === 'function' ) {
			window
				.jQuery( document )
				.on( 'cmplz_enable_category cmplz_status_change', function () {
					try {
						if ( window.cmplz_has_consent( 'statistics' ) ) {
							start();
						}
					} catch ( e ) {
						// ignore — see above
					}
				} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', consentGate );
	} else {
		consentGate();
	}
} )();
