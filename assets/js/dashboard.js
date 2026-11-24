/*!
 * Vantage dashboard (Phase 5).
 *
 * Renders the admin dashboard from the admin-only report API
 * (/wp-json/vantage/v1/reports/*): summary tiles, the multi-touch Sankey
 * (ECharts), attribution by model, top paths, and the traffic & drop-off view.
 *
 * Config: window.VantageDashboard = { api, nonce, today, timezone, settingsUrl }.
 * All report labels (campaigns, channels) are visitor-supplied data: they are
 * only ever inserted with textContent, or escaped for ECharts tooltips.
 */
(function () {
	'use strict';

	var CFG = window.VantageDashboard || {};
	var root = document.getElementById('vt-dash');
	if (!root) {
		return;
	}

	/* ------------------------------------------------------------ constants */

	var COLORS = {
		navy: '#122a3f',
		ink: '#0c1e2e',
		ink2: '#44535f',
		ink3: '#6f7b85',
		orange: '#c2561f',
		other: '#8b98a4',
		none: '#a7afb6',
		salt: '#f6f5f2'
	};
	var MONO = 'ui-monospace, "SFMono-Regular", "SF Mono", Menlo, Consolas, "Liberation Mono", monospace';
	var SANKEY_KEEP = 7;          // channels shown by name; the rest fold into "Other"
	var SANKEY_STEPS = 5;         // server max_steps
	var OTHER = 'Other';
	var RANGES = [
		['7', 'Last 7 days'],
		['30', 'Last 30 days'],
		['90', 'Last 90 days'],
		['180', 'Last 180 days'],
		['365', 'Last 12 months'],
		['custom', 'Custom range']
	];
	var DEFAULTS = { range: '90', start: '', end: '', campaign: '', channel: '', group_by: 'source', tab: 'paths', all: '0', view: 'chart' };

	/* ------------------------------------------------------------ helpers */

	var NUM = new Intl.NumberFormat();
	function fmtInt(n) { return NUM.format(Math.round(n || 0)); }
	function fmtPct(r, d) { return ((r || 0) * 100).toFixed(d === undefined ? 1 : d) + '%'; }
	function fmtDec(n, d) { return (n === null || n === undefined) ? 'n/a' : Number(n).toFixed(d); }
	function fmtCredit(n) { return Math.abs(n - Math.round(n)) < 0.005 ? fmtInt(n) : Number(n).toFixed(1); }
	function plural(n, one, many) { return fmtInt(n) + ' ' + (Math.round(n) === 1 ? one : many); }
	function ordinal(n) {
		var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
		return n + (s[(v - 20) % 10] || s[v] || s[0]);
	}
	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/**
	 * Tiny DOM builder. attrs: { class, text, ...attributes }. Strings in
	 * children become text nodes (never HTML).
	 */
	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) {
				return;
			}
			if (k === 'class') {
				el.className = v;
			} else if (k === 'text') {
				el.textContent = v;
			} else if (k === 'style') {
				el.style.cssText = v;
			} else if (k.indexOf('on') === 0) {
				el.addEventListener(k.slice(2), v);
			} else {
				el.setAttribute(k, v === true ? '' : v);
			}
		});
		(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) {
				return;
			}
			el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
		});
		return el;
	}
	function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }

	function addDays(ymd, n) {
		var p = ymd.split('-');
		var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n));
		return d.toISOString().slice(0, 10);
	}

	/* ------------------------------------------------------------ state */

	var state = readHash();

	function readHash() {
		var s = {}, q = new URLSearchParams(location.hash.slice(1));
		Object.keys(DEFAULTS).forEach(function (k) { s[k] = q.has(k) ? q.get(k) : DEFAULTS[k]; });
		if (!RANGES.some(function (r) { return r[0] === s.range; })) { s.range = DEFAULTS.range; }
		if (s.group_by !== 'campaign') { s.group_by = 'source'; }
		if (s.tab !== 'traffic') { s.tab = 'paths'; }
		return s;
	}
	function writeHash() {
		var q = new URLSearchParams();
		Object.keys(DEFAULTS).forEach(function (k) {
			if (state[k] !== DEFAULTS[k] && state[k] !== '') { q.set(k, state[k]); }
		});
		var str = q.toString();
		history.replaceState(null, '', location.pathname + location.search + (str ? '#' + str : ''));
	}
	function dates() {
		var today = CFG.today || new Date().toISOString().slice(0, 10);
		if (state.range === 'custom' && state.start && state.end) {
			return { start: state.start, end: state.end };
		}
		var n = parseInt(state.range, 10) || 90;
		return { start: addDays(today, -(n - 1)), end: today };
	}
	function hasFilters() { return state.campaign !== '' || state.channel !== ''; }

	/* ------------------------------------------------------------ API */

	function api(report, extra) {
		var d = dates();
		var q = new URLSearchParams({ start: d.start, end: d.end, group_by: state.group_by });
		if (state.campaign) { q.set('campaign', state.campaign); }
		if (state.channel) { q.set('channel', state.channel); }
		Object.keys(extra || {}).forEach(function (k) { q.set(k, extra[k]); });
		return fetch(CFG.api + report + '?' + q.toString(), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CFG.nonce }
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (body) {
				if (!r.ok) {
					throw new Error(body.message || ('HTTP ' + r.status));
				}
				return body;
			});
		});
	}

	/* ------------------------------------------------------------ tooltip */

	var tip = h('div', { class: 'vt-tip', role: 'tooltip' });
	document.body.appendChild(tip);

	/** lines: array of [strong, rest] pairs (either may be empty). */
	function showTip(evOrEl, lines) {
		clear(tip);
		lines.forEach(function (ln, i) {
			var row = h('div', null, [ln[0] ? h('b', { text: ln[0] }) : null, ln[0] && ln[1] ? ' ' : null, ln[1] ? h('span', { class: i ? 'k' : '', text: ln[1] }) : null]);
			tip.appendChild(row);
		});
		var x, y;
		if (evOrEl.clientX !== undefined) {
			x = evOrEl.clientX + 14;
			y = evOrEl.clientY + 14;
		} else {
			var r = evOrEl.getBoundingClientRect();
			x = r.left;
			y = r.bottom + 8;
		}
		tip.classList.add('is-on');
		var w = tip.offsetWidth, ht = tip.offsetHeight;
		if (x + w > window.innerWidth - 8) { x = window.innerWidth - w - 8; }
		if (y + ht > window.innerHeight - 8) { y = y - ht - 28; }
		tip.style.left = x + 'px';
		tip.style.top = y + 'px';
	}
	function hideTip() { tip.classList.remove('is-on'); }
	function withTip(el, lines) {
		el.addEventListener('pointermove', function (e) { showTip(e, lines); });
		el.addEventListener('pointerleave', hideTip);
		el.addEventListener('focus', function () { showTip(el, lines); });
		el.addEventListener('blur', hideTip);
		return el;
	}

	/* ------------------------------------------------------------ skeleton */

	var ui = {};

	function build() {
		clear(root);

		// Header
		root.appendChild(h('div', { class: 'vt-head' }, [
			h('div', { class: 'vt-brand' }, [
				h('h2', { text: 'Vantage' }),
				h('span', { class: 'vt-tag', text: 'Multi-touch attribution' })
			]),
			h('div', { class: 'vt-head-meta' }, [
				h('span', { text: 'tz ' + (CFG.timezone || 'UTC') }),
				h('a', { href: CFG.settingsUrl, text: 'Settings' })
			])
		]));

		// Filters: one row, above everything they scope.
		ui.range = h('select', { id: 'vt-range', onchange: function () {
			state.range = ui.range.value;
			if (state.range === 'custom' && !(state.start && state.end)) {
				var d = dates();
				state.start = addDays(CFG.today, -89);
				state.end = d.end;
			}
			syncControls();
			changed();
		} }, RANGES.map(function (r) { return h('option', { value: r[0], text: r[1] }); }));
		ui.start = h('input', { type: 'date', 'aria-label': 'Start date', onchange: customChanged });
		ui.end = h('input', { type: 'date', 'aria-label': 'End date', onchange: customChanged });
		ui.custom = h('div', { class: 'vt-custom' }, [
			h('label', { class: 'vt-field' }, [h('span', { text: 'From' }), ui.start]),
			h('label', { class: 'vt-field' }, [h('span', { text: 'To' }), ui.end])
		]);
		ui.campaign = h('select', { id: 'vt-campaign', onchange: function () { state.campaign = ui.campaign.value; changed(); } });
		ui.channel = h('select', { id: 'vt-channel', onchange: function () { state.channel = ui.channel.value; changed(); } });
		ui.groupBy = seg([['source', 'Source'], ['campaign', 'Campaign']], function () { return state.group_by; }, function (v) { state.group_by = v; changed(); }, 'Group channels by');
		ui.reset = h('button', { type: 'button', class: 'vt-link', text: 'Reset filters', onclick: function () {
			state.campaign = '';
			state.channel = '';
			state.range = DEFAULTS.range;
			state.group_by = DEFAULTS.group_by;
			syncControls();
			changed();
		} });
		ui.status = h('div', { class: 'vt-status', 'aria-live': 'polite' });

		root.appendChild(h('div', { class: 'vt-filters' }, [
			h('label', { class: 'vt-field', for: 'vt-range' }, [h('span', { text: 'Date range' }), ui.range]),
			ui.custom,
			h('label', { class: 'vt-field', for: 'vt-campaign' }, [h('span', { text: 'Campaign' }), ui.campaign]),
			h('label', { class: 'vt-field', for: 'vt-channel' }, [h('span', { text: 'Channel' }), ui.channel]),
			h('div', { class: 'vt-field' }, [h('span', { text: 'Group by' }), ui.groupBy.el]),
			ui.reset,
			h('div', { class: 'vt-spacer' }),
			ui.status
		]));

		ui.body = h('div', { class: 'vt-body' });
		root.appendChild(ui.body);

		ui.tiles = h('div', { class: 'vt-tiles' });
		ui.body.appendChild(ui.tiles);

		// Tabs: equal billing.
		ui.tabPaths = tab('paths', 'Conversion paths', 'The multi-touch journey to each conversion');
		ui.tabTraffic = tab('traffic', 'Traffic & drop-off', 'Everyone, including visitors who never converted');
		ui.body.appendChild(h('div', { class: 'vt-tabs', role: 'tablist', 'aria-label': 'Dashboard views' }, [ui.tabPaths, ui.tabTraffic]));

		// Panel: conversion paths
		ui.sankeyView = seg([['0', 'Converters'], ['1', '+ Non-converters']], function () { return state.all; }, function (v) { state.all = v; changed(); }, 'Paths shown');
		ui.sankeyMode = seg([['chart', 'Chart'], ['table', 'Table']], function () { return state.view; }, function (v) { state.view = v; writeHash(); renderSankey(); }, 'Sankey display');
		ui.sankeySub = h('p', { class: 'vt-card-sub' });
		ui.sankeyCols = h('div', { class: 'vt-sankey-cols', 'aria-hidden': 'true' });
		ui.sankeyEl = h('div', { class: 'vt-sankey', role: 'img' });
		ui.sankeyTable = h('div');
		ui.attr = h('div');
		ui.attrSub = h('p', { class: 'vt-card-sub' });
		ui.paths = h('div');
		ui.pathsSub = h('p', { class: 'vt-card-sub' });

		ui.panelPaths = h('div', { class: 'vt-panel', role: 'tabpanel', id: 'vt-panel-paths', 'aria-labelledby': 'vt-tab-paths' }, [
			h('div', { class: 'vt-grid' }, [
				h('section', { class: 'vt-card is-wide' }, [
					h('div', { class: 'vt-card-head' }, [
						h('div', null, [h('h3', { text: 'Path to conversion' }), ui.sankeySub]),
						h('div', { class: 'vt-card-tools' }, [ui.sankeyView.el, ui.sankeyMode.el])
					]),
					ui.sankeyCols,
					ui.sankeyEl,
					ui.sankeyTable
				]),
				h('section', { class: 'vt-card is-left' }, [
					h('div', { class: 'vt-card-head' }, [h('div', null, [h('h3', { text: 'Credit by attribution model' }), ui.attrSub])]),
					ui.attr
				]),
				h('section', { class: 'vt-card is-right' }, [
					h('div', { class: 'vt-card-head' }, [h('div', null, [h('h3', { text: 'Top converting paths' }), ui.pathsSub])]),
					ui.paths
				])
			])
		]);

		// Panel: traffic & drop-off
		ui.traffic = h('div');
		ui.trafficSub = h('p', { class: 'vt-card-sub' });
		ui.drop = h('div');
		ui.dropSub = h('p', { class: 'vt-card-sub' });
		ui.quiet = h('div');
		ui.quietSub = h('p', { class: 'vt-card-sub' });

		ui.panelTraffic = h('div', { class: 'vt-panel', role: 'tabpanel', id: 'vt-panel-traffic', 'aria-labelledby': 'vt-tab-traffic' }, [
			h('div', { class: 'vt-grid' }, [
				h('section', { class: 'vt-card is-wide' }, [
					h('div', { class: 'vt-card-head' }, [h('div', null, [h('h3', { text: 'Where traffic enters' }), ui.trafficSub])]),
					ui.traffic
				]),
				h('section', { class: 'vt-card is-left' }, [
					h('div', { class: 'vt-card-head' }, [h('div', null, [h('h3', { text: 'Where it falls off' }), ui.dropSub])]),
					ui.drop
				]),
				h('section', { class: 'vt-card is-right' }, [
					h('div', { class: 'vt-card-head' }, [h('div', null, [h('h3', { text: 'Last channel before going quiet' }), ui.quietSub])]),
					ui.quiet
				])
			])
		]);

		ui.body.appendChild(ui.panelPaths);
		ui.body.appendChild(ui.panelTraffic);
		ui.empty = h('div', { class: 'vt-empty', hidden: true });
		ui.body.appendChild(ui.empty);

		syncControls();
		showTab(state.tab, false);
	}

	function seg(options, get, set, label) {
		var buttons = options.map(function (o) {
			return h('button', { type: 'button', 'data-v': o[0], text: o[1], onclick: function () {
				set(o[0]);
				sync();
			} });
		});
		function sync() {
			buttons.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-v') === get() ? 'true' : 'false'); });
		}
		sync();
		return { el: h('div', { class: 'vt-seg', role: 'group', 'aria-label': label }, buttons), sync: sync };
	}

	function tab(id, title, desc) {
		return h('button', {
			type: 'button', class: 'vt-tab', role: 'tab', id: 'vt-tab-' + id, 'aria-controls': 'vt-panel-' + id,
			onclick: function () { showTab(id, true); },
			onkeydown: function (e) {
				if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
					var other = id === 'paths' ? 'traffic' : 'paths';
					showTab(other, true);
					document.getElementById('vt-tab-' + other).focus();
				}
			}
		}, [h('span', { class: 'vt-tab-title', text: title }), h('span', { class: 'vt-tab-desc', text: desc })]);
	}

	function showTab(id, save) {
		state.tab = id;
		var paths = id === 'paths';
		ui.tabPaths.setAttribute('aria-selected', paths ? 'true' : 'false');
		ui.tabTraffic.setAttribute('aria-selected', paths ? 'false' : 'true');
		ui.tabPaths.tabIndex = paths ? 0 : -1;
		ui.tabTraffic.tabIndex = paths ? -1 : 0;
		ui.panelPaths.hidden = !paths;
		ui.panelTraffic.hidden = paths;
		if (save) { writeHash(); }
		if (paths && data) { renderSankey(); } // ECharts needs a visible box to size.
	}

	function customChanged() {
		if (ui.start.value && ui.end.value) {
			state.start = ui.start.value;
			state.end = ui.end.value;
			changed();
		}
	}

	function syncControls() {
		ui.range.value = state.range;
		ui.custom.classList.toggle('is-on', state.range === 'custom');
		var d = dates();
		ui.start.value = d.start;
		ui.end.value = d.end;
		ui.start.max = CFG.today;
		ui.end.max = CFG.today;
		ui.groupBy.sync();
		ui.sankeyView.sync();
		ui.sankeyMode.sync();
		ui.reset.hidden = !hasFilters() && state.range === DEFAULTS.range && state.group_by === DEFAULTS.group_by;
	}

	function fillSelect(sel, rows, key, current, allLabel) {
		clear(sel);
		sel.appendChild(h('option', { value: '', text: allLabel }));
		var found = false;
		rows.forEach(function (r) {
			if (r[key] === current) { found = true; }
			sel.appendChild(h('option', { value: r[key], text: r[key] + '  (' + fmtInt(r.people) + ')' }));
		});
		if (current && !found) {
			sel.appendChild(h('option', { value: current, text: current + '  (0)' }));
		}
		sel.value = current;
	}

	/* ------------------------------------------------------------ load */

	var data = null;
	var seq = 0;

	function changed() {
		writeHash();
		syncControls();
		load();
	}

	function load() {
		var my = ++seq;
		var t0 = performance.now();
		root.classList.add('is-loading');
		ui.status.classList.remove('is-error');
		ui.status.textContent = 'Loading...';
		Promise.all([
			api('summary'),
			api('sankey', { max_steps: SANKEY_STEPS, include_nonconverters: state.all === '1' ? 'true' : 'false' }),
			api('attribution'),
			api('traffic'),
			api('dropoff'),
			api('filters')
		]).then(function (r) {
			if (my !== seq) { return; }
			data = { summary: r[0], sankey: r[1], attribution: r[2], traffic: r[3], dropoff: r[4], filters: r[5] };
			root.classList.remove('is-loading');
			render();
			var d = dates();
			ui.status.textContent = d.start + ' to ' + d.end + ' · ' + ((performance.now() - t0) / 1000).toFixed(2) + 's';
		}).catch(function (err) {
			if (my !== seq) { return; }
			root.classList.remove('is-loading');
			ui.status.classList.add('is-error');
			ui.status.textContent = 'Could not load reports: ' + err.message;
		});
	}

	/* ------------------------------------------------------------ render */

	function render() {
		fillSelect(ui.campaign, data.filters.campaigns, 'campaign', state.campaign, 'All campaigns');
		fillSelect(ui.channel, data.filters.channels, 'channel', state.channel, 'All channels');

		var empty = data.summary.people === 0;
		ui.empty.hidden = !empty;
		ui.tiles.hidden = empty;
		ui.panelPaths.parentNode.querySelector('.vt-tabs').hidden = empty;
		if (empty) {
			ui.panelPaths.hidden = true;
			ui.panelTraffic.hidden = true;
			clear(ui.empty);
			if (hasFilters()) {
				ui.empty.appendChild(h('p', { text: 'No visitors match these filters in this date range.' }));
			} else {
				ui.empty.appendChild(h('p', null, ['No visitor data in this date range yet. Capture starts as soon as the plugin is active, or ', h('a', { href: CFG.settingsUrl, text: 'seed demo data' }), ' on the Settings page.']));
			}
			return;
		}
		showTab(state.tab, false);
		renderTiles();
		renderSankey();
		renderAttribution();
		renderPaths();
		renderTraffic();
		renderDrop();
		renderQuiet();
	}

	function renderTiles() {
		var s = data.summary;
		var top = s.top_paths && s.top_paths[0];
		var tiles = [
			{ label: 'Visitors', value: fmtInt(s.people), sub: plural(s.touches, 'touch', 'touches') + ' recorded' },
			{ label: 'Conversions', value: fmtInt(s.conversions), sub: plural(s.converting_people, 'person', 'people') + ' converted' },
			{ label: 'Conversion rate', value: fmtPct(s.conversion_rate), sub: 'of visitors in range' },
			{ label: 'Touches per conversion', key: true, value: fmtDec(s.avg_touches_to_convert, 2), sub: 'median ' + fmtDec(s.median_days_to_convert, 1) + ' days to convert' },
			{ label: 'Top converting path', path: true, value: top ? top.path : 'n/a', sub: top ? plural(top.conversions, 'conversion', 'conversions') : '' }
		];
		clear(ui.tiles);
		tiles.forEach(function (t) {
			ui.tiles.appendChild(h('div', { class: 'vt-tile' + (t.key ? ' is-key' : '') + (t.path ? ' is-path' : '') }, [
				h('div', { class: 'vt-tile-label', text: t.label }),
				h('div', { class: 'vt-tile-value', text: t.value, title: t.path ? t.value : null }),
				h('div', { class: 'vt-tile-sub', text: t.sub })
			]));
		});
	}

	/* ------------------------------------------------------------ Sankey */

	/**
	 * Fold the long tail: keep the SANKEY_KEEP biggest channels (by paths across
	 * all touch columns) by name and merge the rest into "Other" in every column,
	 * so the same channel sits at the same rank in each column. Pure display:
	 * node and link values are summed, never estimated.
	 */
	function foldSankey(raw) {
		var totals = {};
		raw.nodes.forEach(function (n) {
			if (n.step !== 'end') { totals[n.label] = (totals[n.label] || 0) + n.value; }
		});
		var ranked = Object.keys(totals).sort(function (a, b) { return totals[b] - totals[a] || (a < b ? -1 : 1); });
		var kept = ranked.length > SANKEY_KEEP + 1 ? ranked.slice(0, SANKEY_KEEP) : ranked;
		var rank = {};
		kept.forEach(function (l, i) { rank[l] = i; });
		rank[OTHER] = kept.length;
		var folded = ranked.filter(function (l) { return rank[l] === undefined; });

		var byId = {}, map = {}, maxStep = 1;
		raw.nodes.forEach(function (n) {
			var isEnd = n.step === 'end';
			var label = isEnd || rank[n.label] !== undefined ? n.label : OTHER;
			var id = (isEnd ? 'end' : n.step) + '|' + label;
			map[n.id] = id;
			if (!isEnd) { maxStep = Math.max(maxStep, n.step); }
			if (!byId[id]) {
				byId[id] = { id: id, label: label, step: n.step, value: 0, members: [] };
			}
			byId[id].value += n.value;
			if (label === OTHER) { byId[id].members.push(n.label); }
		});
		var links = {};
		raw.links.forEach(function (l) {
			var k = map[l.source] + '>' + map[l.target];
			if (!links[k]) { links[k] = { source: map[l.source], target: map[l.target], value: 0 }; }
			links[k].value += l.value;
		});
		var nodes = Object.keys(byId).map(function (k) { return byId[k]; });
		nodes.sort(function (a, b) {
			var sa = a.step === 'end' ? 99 : a.step, sb = b.step === 'end' ? 99 : b.step;
			if (sa !== sb) { return sa - sb; }
			if (a.step === 'end') { return a.label === 'Converted' ? -1 : 1; }
			return rank[a.label] - rank[b.label];
		});
		return { nodes: nodes, links: Object.keys(links).map(function (k) { return links[k]; }), maxStep: maxStep, folded: folded };
	}

	var chart = null;
	var sankeyModel = null;

	function colName(step, maxStep) {
		return step === 'end' ? 'Outcome' : ordinal(step) + ' touch' + (step === maxStep && data.sankey.meta.truncated_paths ? ' (last)' : '');
	}

	function renderSankey() {
		if (!data || ui.panelPaths.hidden) { return; }
		var raw = data.sankey;
		var m = foldSankey(raw);
		sankeyModel = m;
		var meta = raw.meta;
		var withNon = !!meta.include_nonconverters;

		ui.sankeySub.textContent = 'Each column is one touch, in order. Width = ' + (withNon ? 'visitor paths' : 'conversions') +
			' passing through (' + fmtInt(meta.paths) + ' paths). Repeat touches on the same channel are merged' +
			(meta.truncated_paths ? '; ' + fmtInt(meta.truncated_paths) + ' paths over ' + meta.max_steps + ' touches keep the first and last ' + (meta.max_steps - 1) : '') +
			(m.folded.length ? '. ' + m.folded.length + ' smaller channels are grouped as "Other"' : '') + '.';

		var asTable = state.view === 'table';
		ui.sankeyEl.style.display = asTable ? 'none' : '';
		ui.sankeyCols.style.display = asTable ? 'none' : '';
		clear(ui.sankeyTable);
		if (asTable) {
			renderSankeyTable(m);
			return;
		}
		if (!window.echarts) {
			ui.sankeyTable.appendChild(h('p', { class: 'vt-note', text: 'Chart library failed to load; showing the table.' }));
			renderSankeyTable(m);
			return;
		}

		// Height follows the busiest column so nodes keep readable gaps.
		var perCol = {};
		m.nodes.forEach(function (n) { perCol[n.step] = (perCol[n.step] || 0) + 1; });
		var busiest = Math.max.apply(null, Object.keys(perCol).map(function (k) { return perCol[k]; }));
		ui.sankeyEl.style.height = Math.max(380, Math.min(760, busiest * 52)) + 'px';
		ui.sankeyEl.setAttribute('aria-label', 'Sankey diagram of ' + fmtInt(meta.paths) + ' paths. Use the Table toggle for the same data as a table.');

		if (!chart) {
			chart = window.echarts.init(ui.sankeyEl, null, { renderer: 'svg' });
			window.addEventListener('resize', function () {
				if (chart && !ui.panelPaths.hidden && state.view === 'chart') {
					chart.resize();
					placeColumns();
				}
			});
		} else {
			chart.resize();
		}

		var GEO = { left: 0, right: 170, top: 6, bottom: 6, nodeWidth: 10, nodeGap: 12 };

		// Mirror ECharts' vertical scale (the tightest column sets px per path)
		// so only nodes tall enough to hold a label get one; the rest keep the
		// tooltip and the Table view.
		var colSum = {}, colN = {};
		m.nodes.forEach(function (n) {
			colSum[n.step] = (colSum[n.step] || 0) + n.value;
			colN[n.step] = (colN[n.step] || 0) + 1;
		});
		var plotH = parseInt(ui.sankeyEl.style.height, 10) - GEO.top - GEO.bottom;
		var ky = Math.min.apply(null, Object.keys(colSum).map(function (k) {
			return (plotH - (colN[k] - 1) * GEO.nodeGap) / colSum[k];
		}));
		var nodeById = {};
		m.nodes.forEach(function (n) { nodeById[n.id] = n; });

		// Labels sit right of their node and must stop before the next column.
		var colSpan = (chart.getWidth() - GEO.left - GEO.right - GEO.nodeWidth) / m.maxStep;
		var labelW = Math.max(40, Math.floor(colSpan - GEO.nodeWidth - 16));

		chart.setOption({
			animationDuration: 300,
			textStyle: { fontFamily: MONO },
			tooltip: {
				trigger: 'item',
				confine: true,
				backgroundColor: COLORS.ink,
				borderWidth: 0,
				padding: [8, 10],
				textStyle: { color: COLORS.salt, fontFamily: MONO, fontSize: 11.5 },
				extraCssText: 'border-radius:4px;box-shadow:0 4px 16px rgba(12,30,46,.18);max-width:340px;white-space:normal;',
				formatter: function (p) { return sankeyTip(p, nodeById, m, withNon); }
			},
			series: [{
				type: 'sankey',
				left: GEO.left,
				right: GEO.right,
				top: GEO.top,
				bottom: GEO.bottom,
				nodeWidth: GEO.nodeWidth,
				nodeGap: GEO.nodeGap,
				nodeAlign: 'justify',
				layoutIterations: 0,      // keep our order: same channel, same rank, every column
				draggable: false,
				emphasis: { focus: 'adjacency', lineStyle: { opacity: 0.42 } },
				blur: { lineStyle: { opacity: 0.05 }, itemStyle: { opacity: 0.35 }, label: { opacity: 0.45 } },
				lineStyle: { color: COLORS.navy, opacity: 0.15, curveness: 0.5 },
				label: {
					position: 'right',
					distance: 6,
					color: COLORS.ink,
					fontFamily: MONO,
					fontSize: 11,
					formatter: function (p) {
						var n = nodeById[p.name];
						return n.step === 'end' ? n.label + '  ' + fmtInt(n.value) : n.label;
					}
				},
				data: m.nodes.map(function (n) {
					var color = n.step === 'end' ? (n.label === 'Converted' ? COLORS.orange : COLORS.none) : (n.label === OTHER ? COLORS.other : COLORS.navy);
					return {
						name: n.id,
						value: n.value,
						depth: n.step === 'end' ? m.maxStep : n.step - 1,
						itemStyle: { color: color, borderWidth: 0, borderRadius: 2 },
						label: n.step === 'end' ? { fontWeight: 600 } : { show: n.value * ky >= 11, width: labelW, overflow: 'truncate', ellipsis: '…' }
					};
				}),
				links: m.links.map(function (l) {
					return {
						source: l.source,
						target: l.target,
						value: l.value,
					};
				})
			}]
		}, true);

		placeColumns(GEO, m.maxStep);
	}

	var lastGeo = null;
	function placeColumns(geo, maxStep) {
		geo = geo || (lastGeo && lastGeo.geo);
		maxStep = maxStep || (lastGeo && lastGeo.maxStep);
		if (!geo || !chart) { return; }
		lastGeo = { geo: geo, maxStep: maxStep };
		var w = chart.getWidth();
		var span = (w - geo.left - geo.right - geo.nodeWidth) / maxStep;
		clear(ui.sankeyCols);
		for (var d = 0; d <= maxStep; d++) {
			var step = d === maxStep ? 'end' : d + 1;
			ui.sankeyCols.appendChild(h('span', { style: 'left:' + Math.round(geo.left + d * span) + 'px', text: colName(step, maxStep) }));
		}
	}

	function sankeyTip(p, nodeById, m, withNon) {
		var unit = withNon ? 'paths' : 'conversions';
		if (p.dataType === 'edge') {
			var a = nodeById[p.data.source], b = nodeById[p.data.target];
			return '<b>' + esc(fmtInt(p.data.value)) + '</b> ' + unit + '<br>' +
				esc(a.label) + ' <span style="color:#a9b6c1">(' + esc(colName(a.step, m.maxStep)) + ')</span><br>&rarr; ' +
				esc(b.label) + ' <span style="color:#a9b6c1">(' + esc(colName(b.step, m.maxStep)) + ')</span>';
		}
		var n = nodeById[p.name];
		if (!n) { return ''; }
		var html = '<b>' + esc(fmtInt(n.value)) + '</b> ' + (n.step === 'end' ? (n.label === 'Converted' ? 'conversions' : 'visitors did not convert') : unit) +
			'<br>' + esc(n.label) + ' <span style="color:#a9b6c1">(' + esc(colName(n.step, m.maxStep)) + ')</span>';
		if (n.label === OTHER && n.members.length) {
			var list = n.members.slice(0, 8).map(esc).join(', ');
			html += '<br><span style="color:#a9b6c1">' + list + (n.members.length > 8 ? ', +' + (n.members.length - 8) + ' more' : '') + '</span>';
		}
		return html;
	}

	function renderSankeyTable(m) {
		var rows = m.nodes.map(function (n) {
			return h('tr', null, [
				h('td', { text: colName(n.step, m.maxStep) }),
				h('td', { style: 'text-align:left', text: n.label + (n.label === OTHER && n.members.length ? ' (' + n.members.join(', ') + ')' : '') }),
				h('td', { text: fmtInt(n.value) })
			]);
		});
		ui.sankeyTable.appendChild(h('table', { class: 'vt-table' }, [
			h('thead', null, [h('tr', null, [h('th', { text: 'Column' }), h('th', { style: 'text-align:left', text: 'Channel' }), h('th', { text: data.sankey.meta.include_nonconverters ? 'Paths' : 'Conversions' })])]),
			h('tbody', null, rows)
		]));
	}

	/* ------------------------------------------------------------ attribution */

	function renderAttribution() {
		var a = data.attribution;
		var models = [['first_touch', 'First'], ['last_touch', 'Last'], ['linear', 'Linear'], ['position_based', 'Position']];
		ui.attrSub.textContent = 'Conversion credit each channel earns under four models. Every column sums to ' + plural(a.conversions, 'conversion', 'conversions') + '. Position = 40% first, 40% last, 20% middle.';
		var rows = a.rows.slice().sort(function (x, y) { return y.position_based - x.position_based; });
		var max = {};
		models.forEach(function (md) { max[md[0]] = Math.max.apply(null, rows.map(function (r) { return r[md[0]]; }).concat([0])); });
		clear(ui.attr);
		ui.attr.appendChild(h('table', { class: 'vt-table' }, [
			h('thead', null, [h('tr', null, [h('th', { text: 'Channel' })].concat(models.map(function (md) {
				return h('th', { text: md[1], title: a.models[md[0]] });
			})))]),
			h('tbody', null, rows.map(function (r) {
				return h('tr', null, [h('td', { text: r.channel })].concat(models.map(function (md) {
					return h('td', { class: r[md[0]] === max[md[0]] && r[md[0]] > 0 ? 'is-max' : '', text: fmtCredit(r[md[0]]) });
				})));
			}))
		]));
	}

	/* ------------------------------------------------------------ top paths */

	function renderPaths() {
		var s = data.summary;
		var rows = s.top_paths || [];
		ui.pathsSub.textContent = 'Exact channel sequences, repeat touches merged. Share of ' + plural(s.conversions, 'conversion', 'conversions') + '.';
		clear(ui.paths);
		if (!rows.length) {
			ui.paths.appendChild(h('p', { class: 'vt-note', text: 'No conversions in this range.' }));
			return;
		}
		var max = rows[0].conversions;
		ui.paths.appendChild(h('table', { class: 'vt-table' }, [
			h('thead', null, [h('tr', null, [h('th', { text: 'Path' }), h('th', { text: 'Conversions' })])]),
			h('tbody', null, rows.map(function (r) {
				var steps = r.path.split(' → ');
				var chips = [];
				steps.forEach(function (st, i) {
					if (i) { chips.push(h('span', { class: 'vt-arrow', text: '→' })); }
					chips.push(h('span', { class: 'vt-chip', text: st }));
				});
				return h('tr', null, [
					h('td', null, [h('div', { class: 'vt-path' }, chips)]),
					h('td', { class: 'vt-barcell' }, [meter(r.conversions, max, fmtInt(r.conversions) + '  ' + fmtPct(r.conversions / (s.conversions || 1), 0), false,
						[[fmtInt(r.conversions), 'conversions'], ['', r.path]])])
				]);
			}))
		]));
	}

	function meter(value, max, label, quiet, tipLines) {
		var fill = h('div', { class: 'vt-bar-fill' + (quiet ? ' is-quiet' : ''), style: 'width:' + (max ? (value / max) * 100 : 0).toFixed(2) + '%' });
		var track = h('div', { class: 'vt-bar-track' }, [fill]);
		var el = h('div', { class: 'vt-bar' }, [track, h('span', { class: 'vt-bar-val vt-num', text: label })]);
		if (tipLines) { withTip(track, tipLines); }
		return el;
	}

	/* ------------------------------------------------------------ traffic */

	function renderTraffic() {
		var t = data.traffic;
		ui.trafficSub.textContent = 'Every visitor with a touch in range, converters and non-converters, by channel. ' + (t.note || '');
		var rows = t.rows.slice().sort(function (a, b) { return b.people - a.people; });
		var maxPeople = rows.length ? rows[0].people : 0;
		var bestRate = Math.max.apply(null, rows.map(function (r) { return r.conversion_rate; }).concat([0]));
		clear(ui.traffic);
		ui.traffic.appendChild(h('table', { class: 'vt-table' }, [
			h('thead', null, [h('tr', null, [
				h('th', { text: 'Channel' }),
				h('th', { style: 'text-align:left', text: 'Visitors' }),
				h('th', { text: 'First touch', title: 'Visitors whose first-ever touch was this channel' }),
				h('th', { text: 'Converted' }),
				h('th', { text: 'Conv. rate' }),
				h('th', { text: 'Avg touches' }),
				h('th', { text: 'Active days', title: 'Average days between first and last touch' })
			])]),
			h('tbody', null, rows.map(function (r) {
				return h('tr', null, [
					h('td', { text: r.channel }),
					h('td', { class: 'vt-barcell' }, [meter(r.people, maxPeople, fmtInt(r.people), false,
						[[fmtInt(r.people), 'visitors'], ['', r.channel + ' · ' + fmtInt(r.touches) + ' touches']])]),
					h('td', { text: fmtInt(r.first_touch_people) }),
					h('td', { text: fmtInt(r.converters) }),
					h('td', { class: r.conversion_rate === bestRate && bestRate > 0 ? 'is-max' : '', text: fmtPct(r.conversion_rate) }),
					h('td', { text: fmtDec(r.avg_touches, 2) }),
					h('td', { text: fmtDec(r.avg_active_days, 1) })
				]);
			}))
		]));
	}

	/* ------------------------------------------------------------ drop-off */

	var STATES = [
		['converted', 'c', 'Converted'],
		['still_active', 'a', 'Still active'],
		['went_quiet', 'q', 'Went quiet']
	];

	function renderDrop() {
		var d = data.dropoff;
		var tot = d.totals;
		ui.dropSub.textContent = 'Visitors by how many touches they have had. "Went quiet" = no touch for ' + d.meta.quiet_days + ' days and never converted. Converted counts conversions at any time.';
		clear(ui.drop);

		// Legend (names + totals), then the whole split as a tall segmented bar.
		ui.drop.appendChild(h('ul', { class: 'vt-legend' }, STATES.map(function (s) {
			return h('li', null, [h('span', { class: 'vt-swatch', style: 'background:var(--vt-d-' + (s[1] === 'c' ? 'converted' : s[1] === 'a' ? 'active' : 'quiet') + ')' }), s[2] + ' ', h('strong', { text: fmtInt(tot[s[0]]) })]);
		})));
		var bar = h('div', { class: 'vt-seg-bar', role: 'img', 'aria-label': STATES.map(function (s) { return s[2] + ' ' + fmtInt(tot[s[0]]) + ' (' + fmtPct(tot[s[0]] / (tot.people || 1), 0) + ')'; }).join(', ') });
		STATES.forEach(function (s) {
			var n = tot[s[0]];
			if (!n) { return; }
			var share = n / (tot.people || 1);
			var part = h('div', { class: 'vt-seg-part ' + s[1], tabindex: '0', style: 'flex:' + n + ' 1 0', text: share >= 0.09 ? fmtPct(share, 0) : '' });
			withTip(part, [[fmtInt(n), s[2].toLowerCase()], ['', fmtPct(share) + ' of ' + fmtInt(tot.people) + ' visitors']]);
			bar.appendChild(part);
		});
		ui.drop.appendChild(bar);

		// Per touch-count bucket: 100% stacked, with people and conversion rate.
		ui.drop.appendChild(h('table', { class: 'vt-drop' }, [
			h('thead', null, [h('tr', null, [h('th', { text: 'Touches' }), h('th', { text: 'Split' }), h('th', { class: 'r', text: 'Visitors' }), h('th', { class: 'r', text: 'Conv.' })])]),
			h('tbody', null, d.by_touch_count.map(function (b) {
				var label = b.touches + (b.touches === '1' ? ' touch' : ' touches');
				var stack = h('div', { class: 'vt-stack' });
				STATES.forEach(function (s) {
					var n = b[s[0]];
					if (!n) { return; }
					var share = n / (b.people || 1);
					var seg = h('div', { class: s[1], tabindex: '0', style: 'flex:' + n + ' 1 0', text: share >= 0.16 ? fmtPct(share, 0) : '', 'aria-label': s[2] + ': ' + fmtInt(n) + ' of ' + fmtInt(b.people) });
					withTip(seg, [[fmtInt(n), s[2].toLowerCase()], ['', fmtPct(share) + ' of ' + fmtInt(b.people) + ' visitors with ' + label]]);
					stack.appendChild(seg);
				});
				return h('tr', null, [
					h('td', { class: 'lbl', text: label }),
					h('td', null, [stack]),
					h('td', { class: 'ppl', text: fmtInt(b.people) }),
					h('td', { class: 'rate', text: fmtPct(b.conversion_rate) })
				]);
			}))
		]));
	}

	function renderQuiet() {
		var d = data.dropoff;
		var rows = (d.quiet_by_last_channel || []).slice().sort(function (a, b) { return b.people - a.people; });
		ui.quietSub.textContent = 'Where the ' + fmtInt(d.totals.went_quiet) + ' visitors who went quiet were last seen, and how many touches they had first.';
		clear(ui.quiet);
		if (!rows.length) {
			ui.quiet.appendChild(h('p', { class: 'vt-note', text: 'Nobody went quiet in this range.' }));
			return;
		}
		var max = rows[0].people;
		ui.quiet.appendChild(h('table', { class: 'vt-table' }, [
			h('thead', null, [h('tr', null, [h('th', { text: 'Last channel' }), h('th', { style: 'text-align:left', text: 'Went quiet' }), h('th', { text: 'Avg touches' })])]),
			h('tbody', null, rows.map(function (r) {
				return h('tr', null, [
					h('td', { text: r.channel }),
					h('td', { class: 'vt-barcell' }, [meter(r.people, max, fmtInt(r.people), true,
						[[fmtInt(r.people), 'went quiet'], ['', 'last seen via ' + r.channel]])]),
					h('td', { text: fmtDec(r.avg_touches, 2) })
				]);
			}))
		]));
	}

	/* ------------------------------------------------------------ go */

	build();
	load();

	// Hand-edited URLs and back/forward. Our own writes use replaceState, which
	// does not fire hashchange, so this only reacts to the user.
	window.addEventListener('hashchange', function () {
		state = readHash();
		syncControls();
		showTab(state.tab, false);
		load();
	});
})();
