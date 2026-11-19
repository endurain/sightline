/**
 * Sightline capture library.
 *
 * Framework-agnostic on purpose: it reads optional config from
 * window.SightlineConfig but has safe defaults, so this exact file runs on
 * WordPress, a headless front end, or any plain HTML page. It has NO WordPress
 * coupling. Phase 2 adds the POST-to-endpoint transport where marked.
 *
 * What it does (Phase 1):
 *   - ensures a first-party anonymous id cookie (sl_anon)
 *   - reads UTMs + ad click ids + referrer from the current URL
 *   - appends a "touch" to a first-party touch list (localStorage, cookie fallback)
 *   - preserves first touch (list is append-only) and dedupes refreshes
 *
 * Inspect in devtools:  window.Sightline.getTouches()  /  .getAnonId()
 */
(function (window, document) {
	'use strict';

	var defaults = {
		endpoint: null,            // host-injected; transport POSTs here
		nonce: null,               // host-injected; sent as X-WP-Nonce
		cookieName: 'sl_anon',     // anonymous visitor id
		storageKey: 'sl_touches',  // touch list
		sentKey: 'sl_sent',        // how many touches have been sent
		cookieDays: 365,
		dedupeMinutes: 30,         // identical touch within this window is ignored
		debug: false,
		clickIdKeys: [
			'gclid', 'wbraid', 'gbraid', 'fbclid', 'msclkid',
			'ttclid', 'li_fat_id', 'twclid', 'epik', 'sccid'
		]
	};

	var cfg = merge(defaults, window.SightlineConfig || {});

	function merge(a, b) {
		var out = {}, k;
		for (k in a) { if (has(a, k)) { out[k] = a[k]; } }
		for (k in b) { if (has(b, k)) { out[k] = b[k]; } }
		return out;
	}
	function has(o, k) { return Object.prototype.hasOwnProperty.call(o, k); }

	function uuid() {
		if (window.crypto && window.crypto.randomUUID) {
			try { return window.crypto.randomUUID(); } catch (e) {}
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			var v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	/* ---- Cookies (first-party) ---- */
	function setCookie(name, value, days) {
		var exp = '';
		if (days) {
			var d = new Date();
			d.setTime(d.getTime() + days * 864e5);
			exp = '; expires=' + d.toUTCString();
		}
		document.cookie = name + '=' + encodeURIComponent(value) + exp + '; path=/; SameSite=Lax';
	}
	function getCookie(name) {
		var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
		return m ? decodeURIComponent(m[1]) : null;
	}

	/* ---- Storage: localStorage with cookie fallback, all guarded ---- */
	function storeGet(key) {
		try {
			var v = window.localStorage.getItem(key);
			if (v !== null) { return v; }
		} catch (e) {}
		return getCookie(key);
	}
	function storeSet(key, value) {
		var ok = false;
		try { window.localStorage.setItem(key, value); ok = true; } catch (e) {}
		if (!ok) { setCookie(key, value, cfg.cookieDays); }
	}

	/* ---- Anonymous visitor id ---- */
	function getAnonId() {
		var id = getCookie(cfg.cookieName);
		if (!id) {
			id = uuid();
			setCookie(cfg.cookieName, id, cfg.cookieDays);
		}
		return id;
	}

	/* ---- URL parsing ---- */
	function queryParams() {
		var params = {};
		var qs = window.location.search.replace(/^\?/, '');
		if (!qs) { return params; }
		qs.split('&').forEach(function (pair) {
			var kv = pair.split('=');
			if (!kv[0]) { return; }
			var key = decodeURIComponent(kv[0]).toLowerCase();
			params[key] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
		});
		return params;
	}

	function buildCandidate() {
		var p = queryParams();
		var clickIds = {};
		cfg.clickIdKeys.forEach(function (k) { if (p[k]) { clickIds[k] = p[k]; } });

		var hasUtm = !!(p.utm_source || p.utm_medium || p.utm_campaign || p.utm_term || p.utm_content);
		var hasClick = false, kk;
		for (kk in clickIds) { if (has(clickIds, kk)) { hasClick = true; break; } }

		return {
			touch: {
				utm_source: p.utm_source || null,
				utm_medium: p.utm_medium || null,
				utm_campaign: p.utm_campaign || null,
				utm_term: p.utm_term || null,
				utm_content: p.utm_content || null,
				click_ids: hasClick ? clickIds : null,
				referrer: document.referrer || null,
				landing_url: window.location.href,
				time: new Date().toISOString()
			},
			hasAttribution: (hasUtm || hasClick)
		};
	}

	/* ---- Touch list ---- */
	function getTouches() {
		var raw = storeGet(cfg.storageKey);
		if (!raw) { return []; }
		try { return JSON.parse(raw) || []; } catch (e) { return []; }
	}
	function saveTouches(list) { storeSet(cfg.storageKey, JSON.stringify(list)); }

	function getSentCount() {
		var v = parseInt(storeGet(cfg.sentKey), 10);
		return isNaN(v) ? 0 : v;
	}
	function setSentCount(n) { storeSet(cfg.sentKey, String(n)); }

	/**
	 * Send any touches not yet delivered to the server. Flushes the backlog
	 * (including touches captured before the endpoint existed) plus new ones.
	 * No endpoint configured => no-op, so this file stays framework-agnostic.
	 */
	function sync(anonId) {
		if (!cfg.endpoint || !window.fetch) { return; }
		var list = getTouches();
		var sent = getSentCount();
		var unsent = list.slice(sent);
		if (!unsent.length) { return; }

		var total = list.length;
		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }

		window.fetch(cfg.endpoint, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			keepalive: true,
			body: JSON.stringify({ anon_id: anonId, touches: unsent })
		}).then(function (res) {
			if (res && res.ok) {
				setSentCount(total); // mark everything through this batch as sent
				if (cfg.debug && window.console) {
					window.console.debug('[Sightline] synced ' + unsent.length + ' touch(es)');
				}
			}
		}).catch(function () {
			// Leave sent count unchanged; retry on next page load.
		});
	}

	function sameSource(a, b) {
		return a && b &&
			a.utm_source === b.utm_source &&
			a.utm_medium === b.utm_medium &&
			a.utm_campaign === b.utm_campaign &&
			a.utm_term === b.utm_term &&
			a.utm_content === b.utm_content &&
			JSON.stringify(a.click_ids) === JSON.stringify(b.click_ids);
	}

	function isDuplicate(candidate, list) {
		if (!list.length) { return false; }
		var last = list[list.length - 1];
		if (!sameSource(candidate, last)) { return false; }
		var lastTime = Date.parse(last.time) || 0;
		var now = Date.parse(candidate.time) || Date.now();
		return (now - lastTime) < cfg.dedupeMinutes * 60000;
	}

	/* ---- Hidden-field injection for native forms ---- */
	// Adds a hidden sl_anon_id input to every form so the server can tie a
	// submission back to this visitor. Idempotent. (Dynamically-added forms,
	// e.g. in popups loaded later, are a known pass-two refinement.)
	function injectAnonField(anonId) {
		var forms = document.getElementsByTagName('form');
		for (var i = 0; i < forms.length; i++) {
			if (!forms[i].querySelector('input[name="sl_anon_id"]')) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = 'sl_anon_id';
				input.value = anonId;
				forms[i].appendChild(input);
			}
		}
	}

	/* ---- Main ---- */
	function record() {
		var anonId = getAnonId();
		var built = buildCandidate();
		var list = getTouches();

		var shouldRecord = false;
		if (built.hasAttribution) {
			// A campaign hit: record unless it's a refresh dupe.
			shouldRecord = !isDuplicate(built.touch, list);
		} else if (list.length === 0) {
			// First ever visit with no params: capture the organic/direct entry.
			shouldRecord = true;
		}

		if (shouldRecord) {
			list.push(built.touch);
			saveTouches(list);
			if (cfg.debug && window.console) {
				window.console.debug('[Sightline] touch recorded', built.touch);
			}
		}

		// Tie form submissions back to this visitor.
		injectAnonField(anonId);

		// Deliver any unsent touches to the server (no-op without an endpoint).
		sync(anonId);

		// Devtools inspection API.
		window.Sightline = window.Sightline || {};
		window.Sightline.getAnonId = getAnonId;
		window.Sightline.getTouches = getTouches;
		window.Sightline.getSentCount = getSentCount;
		window.Sightline.sync = function () { sync(getAnonId()); };
		window.Sightline.config = cfg;
		window.Sightline.reset = function () {
			try {
				window.localStorage.removeItem(cfg.storageKey);
				window.localStorage.removeItem(cfg.sentKey);
			} catch (e) {}
			setCookie(cfg.storageKey, '', -1);
			setCookie(cfg.sentKey, '', -1);
			setCookie(cfg.cookieName, '', -1);
		};
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', record);
	} else {
		record();
	}
})(window, document);
