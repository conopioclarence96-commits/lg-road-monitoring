/**
 * AdminRealtimeService
 * ────────────────────
 * Vanilla-JS client for the admin SSE stream.
 *
 * Usage:
 *   const rt = new AdminRealtimeService({ endpoint: '/lgu_staff/pages/api/admin_realtime.php' });
 *   rt.on('stats',  data => { ... });
 *   rt.on('charts', data => { ... });
 *   rt.on('table',  data => { ... });
 *   rt.start();
 *
 * Features:
 *   - Automatic reconnection with exponential backoff + jitter
 *   - Batches rapid-fire events (200 ms window) to avoid redundant renders
 *   - Connection status indicator (dot + label)
 *   - Session keepalive pings to prevent idle timeout
 *   - Emits 'connected' / 'disconnected' / 'reconnecting' lifecycle events
 */
;(function (root) {
    'use strict';

    /* ── defaults ─────────────────────────────────────────────────── */
    const DEFAULTS = {
        endpoint:         '/lgu_staff/pages/api/admin_realtime.php',
        batchMs:          200,         // debounce window for event batching
        reconnectBaseMs:  1000,        // initial reconnect delay
        reconnectMaxMs:   30000,       // ceiling for backoff
        keepaliveMs:      30000,       // client-side keepalive ping interval
        statusSelector:   '#rt-status' // DOM node for connection indicator
    };

    /* ── constructor ──────────────────────────────────────────────── */
    function AdminRealtimeService(opts) {
        this.cfg = Object.assign({}, DEFAULTS, opts);
        this._handlers   = {};          // event-name → [fn, …]
        this._pending    = new Set();   // event names queued in current batch window
        this._batchTimer = null;
        this._es         = null;        // EventSource instance
        this._retries    = 0;
        this._aliveTimer = null;
        this._connected  = false;
        this._destroyed  = false;
    }

    /* ── public API ───────────────────────────────────────────────── */

    /**
     * Register a listener for a named SSE event.
     * Pass '*' to listen to every event.
     */
    AdminRealtimeService.prototype.on = function (name, fn) {
        (this._handlers[name] || (this._handlers[name] = [])).push(fn);
        return this;
    };

    /**
     * Remove a previously registered listener.
     */
    AdminRealtimeService.prototype.off = function (name, fn) {
        var arr = this._handlers[name];
        if (arr) this._handlers[name] = arr.filter(function (f) { return f !== fn; });
        return this;
    };

    /**
     * Open the SSE connection and begin listening.
     */
    AdminRealtimeService.prototype.start = function () {
        if (this._destroyed) return this;
        this._connect();
        return this;
    };

    /**
     * Gracefully close the SSE connection and stop reconnecting.
     */
    AdminRealtimeService.prototype.stop = function () {
        this._destroyed = true;
        this._disconnect();
        this._setStatus('offline', 'Disconnected');
        return this;
    };

    /* ── internals ────────────────────────────────────────────────── */

    AdminRealtimeService.prototype._connect = function () {
        if (this._destroyed) return;
        if (this._es) this._disconnect();

        var self = this;

        this._es = new EventSource(this.cfg.endpoint);

        this._es.onopen = function () {
            self._retries = 0;
            self._connected = true;
            self._setStatus('online', 'Live');
            self._emit('connected', {});
            self._startKeepalive();
        };

        // Generic message fallback
        this._es.onmessage = function (e) {
            self._enqueue('message', e.data);
        };

        this._es.onerror = function () {
            self._connected = false;
            self._stopKeepalive();

            if (self._es.readyState === EventSource.CLOSED) {
                // Server explicitly closed — do not reconnect
                self._setStatus('offline', 'Closed');
                self._emit('disconnected', { reason: 'closed' });
                return;
            }

            // Reconnecting
            self._setStatus('reconnecting', 'Reconnecting…');
            self._emit('reconnecting', { attempt: self._retries + 1 });
            self._scheduleReconnect();
        };

        // Bind each named event type we care about
        ['stats', 'charts', 'table', 'error'].forEach(function (evt) {
            self._es.addEventListener(evt, function (e) {
                var data;
                try { data = JSON.parse(e.data); } catch (_) { data = e.data; }
                self._enqueue(evt, data);
            });
        });
    };

    AdminRealtimeService.prototype._disconnect = function () {
        if (this._es) {
            this._es.close();
            this._es = null;
        }
        this._stopKeepalive();
    };

    AdminRealtimeService.prototype._scheduleReconnect = function () {
        var self = this;
        var delay = Math.min(
            this.cfg.reconnectBaseMs * Math.pow(2, this._retries),
            this.cfg.reconnectMaxMs
        );
        // Add 0-25 % jitter
        delay += delay * Math.random() * 0.25;
        this._retries++;

        setTimeout(function () {
            self._connect();
        }, delay);
    };

    /* ── batched event dispatch ───────────────────────────────────── */

    AdminRealtimeService.prototype._enqueue = function (name, data) {
        // Store latest data for this event name
        this._pending.add(name);
        this['_' + name + '_data'] = data;

        if (!this._batchTimer) {
            var self = this;
            this._batchTimer = setTimeout(function () {
                self._flush();
            }, this.cfg.batchMs);
        }
    };

    AdminRealtimeService.prototype._flush = function () {
        this._batchTimer = null;
        var pending = this._pending;
        this._pending = new Set();

        var self = this;
        pending.forEach(function (name) {
            var data = self['_' + name + '_data'];
            self._emit(name, data);
        });
    };

    /* ── emit to handlers ─────────────────────────────────────────── */

    AdminRealtimeService.prototype._emit = function (name, data) {
        var fns = this._handlers[name] || [];
        var wild = this._handlers['*'] || [];
        var all = fns.concat(wild);
        for (var i = 0; i < all.length; i++) {
            try { all[i](data, name); } catch (e) { console.error('[RT] handler error', e); }
        }
    };

    /* ── connection status indicator ──────────────────────────────── */

    AdminRealtimeService.prototype._setStatus = function (state, label) {
        var el = document.querySelector(this.cfg.statusSelector);
        if (!el) return;
        el.className = 'rt-status rt-status--' + state;
        el.querySelector('.rt-status__label').textContent = label;
    };

    /* ── keepalive ────────────────────────────────────────────────── */

    AdminRealtimeService.prototype._startKeepalive = function () {
        this._stopKeepalive();
        var self = this;
        this._aliveTimer = setInterval(function () {
            if (self._es && self._es.readyState === EventSource.OPEN) {
                // Sending a GET-like "refresh" to keep the PHP session alive
                // We use a tiny no-cache fetch to touch the session
                fetch(self.cfg.endpoint, { method: 'HEAD', cache: 'no-store' }).catch(function () {});
            }
        }, this.cfg.keepaliveMs);
    };

    AdminRealtimeService.prototype._stopKeepalive = function () {
        if (this._aliveTimer) {
            clearInterval(this._aliveTimer);
            this._aliveTimer = null;
        }
    };

    /* ── export ───────────────────────────────────────────────────── */
    root.AdminRealtimeService = AdminRealtimeService;

})(window);
