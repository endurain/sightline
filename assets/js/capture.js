/**
 * Vantage capture library.
 *
 * Framework-agnostic on purpose: it reads optional config from
 * window.VantageConfig but has safe defaults, so this exact file runs on
 * WordPress, a headless front end, or any plain HTML page. It has NO WordPress
 * coupling. Phase 2 adds the POST-to-endpoint transport where marked.
 *
 * What it does (Phase 1):
 *   - ensures a first-party anonymous id cookie (vt_anon)
 *   - reads UTMs + ad click ids + referrer from the current URL
 *   - appends a "touch" to a first-party touch list (localStorage, cookie fallback):
 *     every campaign hit (UTMs or an ad click id), the first-ever visit, and
 *     every return visit from another site (organic search, social, referral)
 *   - skips automated browsers, and waits for prerendered pages to be shown
 *   - preserves first touch (list is append-only) and dedupes refreshes
 *   - adds a hidden vt_anon_id field to every form (including forms added later)
 *   - fills any existing vt_first_* / vt_last_* CRM fields with attribution
 *   - fires a `vantage:ready` event on document when done
 *
 * Inspect in devtools:  window.Vantage.getTouches()  /  .getAnonId()  /  .getAttribution()
 */
(function (window, document) {
	'use strict';

	var defaults = {
		endpoint: null,            // host-injected; transport POSTs here (no nonce: cache-safe)
		cookieName: 'vt_anon',     // anonymous visitor id
		storageKey: 'vt_touches',  // touch list
		sentKey: 'vt_sent',        // how many touches have been sent
		cookieDays: 365,
		dedupeMinutes: 30,         // identical touch within this window is ignored
		debug: false,
		clickIdKeys: [
			'gclid', 'wbraid', 'gbraid', 'dclid', 'fbclid', 'msclkid',
			'ttclid', 'li_fat_id', 'twclid', 'epik', 'sccid'
		],
		// Referrers that are a step inside the visit, not a new source: a
		// return from a payment or sign-in page must not steal last-touch credit.
		ignoreReferrers: [
			'paypal.com', 'stripe.com', 'pay.google.com', 'accounts.google.com',
			'appleid.apple.com', 'login.microsoftonline.com', 'login.live.com'
		]
	};

	var cfg = merge(defaults, window.VantageConfig || {});
	// Host-supplied additions extend (never replace) the built-in exclusions.
	if (Object.prototype.toString.call(cfg.extraIgnoreReferrers) === '[object Array]') {
		cfg.ignoreReferrers = cfg.ignoreReferrers.concat(cfg.extraIgnoreReferrers);
	}

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
		// No X-WP-Nonce: a nonce baked into a cached page goes stale and makes
		// WordPress reject the request before Vantage sees it.
		var headers = { 'Content-Type': 'application/json' };

		window.fetch(cfg.endpoint, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			// The endpoint checks that requests come from this site. Under a
			// page-wide Referrer-Policy: no-referrer the Fetch spec says Origin
			// becomes "null" (Chrome still sends it; other browsers may not), so
			// pin the policy for this same-origin request. Sends nothing to
			// other sites.
			referrerPolicy: 'same-origin',
			keepalive: true,
			body: JSON.stringify({ anon_id: anonId, touches: unsent })
		}).then(function (res) {
			if (res && res.ok) {
				setSentCount(total); // mark everything through this batch as sent
				if (cfg.debug && window.console) {
					window.console.debug('[Vantage] synced ' + unsent.length + ' touch(es)');
				}
			}
		}).catch(function () {
			// Leave sent count unchanged; retry on next page load.
		});
	}

	function sameSource(a, b) {
		var same = a && b &&
			a.utm_source === b.utm_source &&
			a.utm_medium === b.utm_medium &&
			a.utm_campaign === b.utm_campaign &&
			a.utm_term === b.utm_term &&
			a.utm_content === b.utm_content &&
			JSON.stringify(a.click_ids) === JSON.stringify(b.click_ids);
		// Without UTMs or click ids, the referring site is the source.
		if (same && !a.utm_source && !a.utm_medium && !a.click_ids) {
			same = refHost(a.referrer) === refHost(b.referrer);
		}
		return same;
	}

	/* ---- Referrers ---- */
	function bareHost(h) { return String(h || '').toLowerCase().replace(/^www\./, ''); }
	function refHost(url) {
		if (!url) { return ''; }
		var m = String(url).match(/^[a-z][a-z0-9+.-]*:\/\/([^\/?#:]+)/i);
		return m ? bareHost(m[1]) : '';
	}
	function hostMatches(host, domain) {
		return host === domain || host.slice(-(domain.length + 1)) === '.' + domain;
	}
	/** A referrer from another site that counts as a new source. */
	function isExternalReferrer(url) {
		var host = refHost(url);
		if (!host || host === bareHost(window.location.hostname)) { return false; }
		for (var i = 0; i < cfg.ignoreReferrers.length; i++) {
			if (hostMatches(host, bareHost(cfg.ignoreReferrers[i]))) { return false; }
		}
		return true;
	}

	function isDuplicate(candidate, list) {
		if (!list.length) { return false; }
		var last = list[list.length - 1];
		if (!sameSource(candidate, last)) { return false; }
		var lastTime = Date.parse(last.time) || 0;
		var now = Date.parse(candidate.time) || Date.now();
		return (now - lastTime) < cfg.dedupeMinutes * 60000;
	}

	/* ---- Attribution summary (first/last touch) ---- */
	// Keys match the CRM fields documented in the user guide.
	var UTM_PARTS = ['source', 'medium', 'campaign', 'term', 'content'];

	function getAttribution() {
		var list = getTouches();
		var out = {};
		if (!list.length) { return out; }
		var first = list[0], last = list[list.length - 1];
		UTM_PARTS.forEach(function (p) {
			if (first['utm_' + p]) { out['vt_first_utm_' + p] = first['utm_' + p]; }
			if (last['utm_' + p]) { out['vt_last_utm_' + p] = last['utm_' + p]; }
		});
		if (first.landing_url) { out.vt_first_landing = first.landing_url; }
		if (last.referrer) { out.vt_last_referrer = last.referrer; }
		out.vt_touch_count = String(list.length);
		return out;
	}

	/**
	 * Fill any existing fields named like the CRM keys (vt_first_utm_source, ...)
	 * inside `scope`. Never creates fields and never overwrites a non-empty value.
	 */
	function fillFields(scope) {
		scope = scope || document;
		var attr = getAttribution();
		var k, i, els, el, ev;
		for (k in attr) {
			if (!has(attr, k)) { continue; }
			els = scope.querySelectorAll('input[name="' + k + '"], textarea[name="' + k + '"]');
			for (i = 0; i < els.length; i++) {
				el = els[i];
				if (el.value) { continue; }
				el.value = attr[k];
				// Let form libraries that track their own state notice the change.
				try {
					ev = document.createEvent('HTMLEvents');
					ev.initEvent('input', true, false); el.dispatchEvent(ev);
					ev = document.createEvent('HTMLEvents');
					ev.initEvent('change', true, false); el.dispatchEvent(ev);
				} catch (e) {}
			}
		}
	}

	/* ---- Hidden-field injection for native forms ---- */
	// Adds a hidden vt_anon_id input to a form so the server can tie a
	// submission back to this visitor. Idempotent.
	function prepareForm(form, anonId) {
		if (!form.querySelector('input[name="vt_anon_id"]')) {
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = 'vt_anon_id';
			input.value = anonId;
			form.appendChild(input);
		}
		fillFields(form);
	}

	function prepareForms(scope, anonId) {
		var forms = scope.tagName === 'FORM' ? [scope] : scope.getElementsByTagName('form');
		for (var i = 0; i < forms.length; i++) { prepareForm(forms[i], anonId); }
	}

	// Forms added after load (popups, AJAX-rendered embeds) get the same treatment.
	function watchForms(anonId) {
		if (!window.MutationObserver) { return; }
		new window.MutationObserver(function (mutations) {
			for (var m = 0; m < mutations.length; m++) {
				var added = mutations[m].addedNodes;
				for (var i = 0; i < added.length; i++) {
					if (added[i].nodeType === 1) { prepareForms(added[i], anonId); }
				}
			}
		}).observe(document.documentElement, { childList: true, subtree: true });
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
		} else if (isExternalReferrer(built.touch.referrer)) {
			// A return visit from another site (search, social, referral) is a
			// new touch. Direct returns and internal navigation are not.
			shouldRecord = !isDuplicate(built.touch, list);
		}

		if (shouldRecord) {
			list.push(built.touch);
			saveTouches(list);
			if (cfg.debug && window.console) {
				window.console.debug('[Vantage] touch recorded', built.touch);
			}
		}

		// Tie form submissions back to this visitor, and fill CRM fields.
		prepareForms(document, anonId);
		watchForms(anonId);

		// Deliver any unsent touches to the server (no-op without an endpoint).
		sync(anonId);

		// Devtools inspection API.
		window.Vantage = window.Vantage || {};
		window.Vantage.getAnonId = getAnonId;
		window.Vantage.getTouches = getTouches;
		window.Vantage.getSentCount = getSentCount;
		window.Vantage.getAttribution = getAttribution;
		window.Vantage.fillFields = fillFields;
		window.Vantage.sync = function () { sync(getAnonId()); };
		window.Vantage.config = cfg;
		window.Vantage.reset = function () {
			try {
				window.localStorage.removeItem(cfg.storageKey);
				window.localStorage.removeItem(cfg.sentKey);
			} catch (e) {}
			setCookie(cfg.storageKey, '', -1);
			setCookie(cfg.sentKey, '', -1);
			setCookie(cfg.cookieName, '', -1);
		};

		// Let add-ons (e.g. iframe attribution) know the API is ready.
		try {
			var ready = document.createEvent('Event');
			ready.initEvent('vantage:ready', false, false);
			document.dispatchEvent(ready);
		} catch (e) {}
	}

	function start() {
		// Automated browsers (headless scrapers, test rigs, some ad verifiers)
		// announce themselves here. Not proof against a determined bot; the
		// server also filters by user agent.
		if (window.navigator && window.navigator.webdriver) { return; }
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', record);
		} else {
			record();
		}
	}

	// A prerendered page (Chrome speculation rules, search-result prerender)
	// runs scripts before anyone sees it, and may never be opened. Wait until
	// it is actually shown.
	if (document.prerendering) {
		document.addEventListener('prerenderingchange', start, { once: true });
	} else {
		start();
	}
})(window, document);
