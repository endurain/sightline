/**
 * Vantage iframe form attribution.
 *
 * Framework-agnostic (no CMS references). Reads window.VantageConfig.iframe
 * = { enabled, domains[] } and the visitor's attribution from window.Vantage
 * (capture.js), then:
 *
 *   - Server-deferred iframes (data-vt-src + data-vt-pending): append first/last
 *     touch params and set src. One load, attribution in the URL.
 *   - Allowlisted iframes added later by script: rewrite src as they appear
 *     (may cost a reload of that iframe; unavoidable without server deferral).
 *   - HubSpot: on the hsFormCallback "onFormReady" message, fill matching
 *     vt_* fields in any same-origin form iframe (HubSpot's script-built embed).
 *
 * Only iframes whose host is on the allowlist are ever touched. Existing query
 * params on the iframe URL are never overwritten. If anything fails, pending
 * iframes are released with their original src so a lead form never stays blank.
 */
(function (window, document) {
	'use strict';

	// First + last touch only: Pardot and others have URL length limits.
	var URL_KEYS = [
		'vt_first_utm_source', 'vt_first_utm_medium', 'vt_first_utm_campaign',
		'vt_last_utm_source', 'vt_last_utm_medium', 'vt_last_utm_campaign'
	];

	var root = window.VantageConfig || {};
	var cfg = root.iframe || {};
	var domains = [];
	(cfg.domains || []).forEach(function (d) {
		if (typeof d === 'string' && d) { domains.push(d.toLowerCase()); }
	});

	function hostAllowed(host) {
		host = String(host || '').toLowerCase();
		if (!host) { return false; }
		for (var i = 0; i < domains.length; i++) {
			var d = domains[i];
			if (host === d || host.slice(-(d.length + 1)) === '.' + d) { return true; }
		}
		return false;
	}

	function attribution() {
		try {
			if (window.Vantage && window.Vantage.getAttribution) {
				return window.Vantage.getAttribution();
			}
		} catch (e) {}
		return {};
	}

	function withParams(url, attr) {
		URL_KEYS.forEach(function (k) {
			if (attr[k] && !url.searchParams.has(k)) { url.searchParams.set(k, attr[k]); }
		});
		return url.toString();
	}

	function release(frame) {
		if (frame.hasAttribute('data-vt-pending')) {
			frame.removeAttribute('data-vt-pending');
			frame.src = frame.getAttribute('data-vt-src');
		}
	}

	function processFrame(frame, attr) {
		if (frame.__vtDone) { return; }
		var pending = frame.hasAttribute('data-vt-pending');
		var raw = pending ? frame.getAttribute('data-vt-src') : frame.getAttribute('src');
		if (!raw) { return; }
		frame.__vtDone = true;

		var url;
		try { url = new URL(raw, window.location.href); } catch (e) { release(frame); return; }
		if (!hostAllowed(url.hostname)) { release(frame); return; }

		var before = url.toString();
		var next;
		try { next = withParams(url, attr); } catch (e) { release(frame); return; }

		if (pending) {
			frame.removeAttribute('data-vt-pending');
			frame.src = next;
		} else if (next !== before) {
			frame.src = next;
		}
	}

	function processAll(scope) {
		var attr = attribution();
		var frames = (scope || document).getElementsByTagName('iframe');
		for (var i = 0; i < frames.length; i++) {
			try { processFrame(frames[i], attr); } catch (e) { release(frames[i]); }
		}
	}

	function releaseAll() {
		var l = document.querySelectorAll('iframe[data-vt-pending]');
		for (var i = 0; i < l.length; i++) { release(l[i]); }
	}

	/* ---- HubSpot postMessage: fill fields in same-origin form iframes ---- */
	function onMessage(ev) {
		var data = ev && ev.data;
		if (!data || data.type !== 'hsFormCallback' || data.eventName !== 'onFormReady') { return; }
		if (!window.Vantage || !window.Vantage.fillFields) { return; }
		var frames = document.getElementsByTagName('iframe');
		for (var i = 0; i < frames.length; i++) {
			var doc = null;
			try { doc = frames[i].contentDocument; } catch (e) { doc = null; } // cross-origin: skip
			if (doc) {
				try { window.Vantage.fillFields(doc); } catch (e2) {}
			}
		}
		// Raw (non-iframe) HubSpot embeds render into this page.
		try { window.Vantage.fillFields(document); } catch (e3) {}
	}

	function start() {
		if (!cfg.enabled || !domains.length) { releaseAll(); return; }

		processAll(document);
		window.addEventListener('message', onMessage);

		if (window.MutationObserver) {
			new window.MutationObserver(function (mutations) {
				var attr = null;
				mutations.forEach(function (m) {
					for (var i = 0; i < m.addedNodes.length; i++) {
						var n = m.addedNodes[i];
						if (n.nodeType !== 1) { continue; }
						var list = n.tagName === 'IFRAME' ? [n] : n.getElementsByTagName('iframe');
						for (var j = 0; j < list.length; j++) {
							attr = attr || attribution();
							try { processFrame(list[j], attr); } catch (e) { release(list[j]); }
						}
					}
				});
			}).observe(document.documentElement, { childList: true, subtree: true });
		}
	}

	var started = false;
	function once() {
		if (started) { return; }
		started = true;
		try { start(); } catch (e) { releaseAll(); }
	}

	if (window.Vantage && window.Vantage.getAttribution) {
		once();
	} else {
		document.addEventListener('vantage:ready', once);
		// capture.js missing or broken: don't hold forms hostage.
		var kick = function () { setTimeout(function () { if (!started) { started = true; releaseAll(); } }, 1500); };
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', kick);
		} else {
			kick();
		}
	}
})(window, document);
