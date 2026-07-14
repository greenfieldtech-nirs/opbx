/**
 * OPBX Embedded Dialer — host-side loader.
 *
 * Exposes window.OpbxDialer. Injects an iframe served by OPBX that runs the
 * dialer widget, and bridges commands/events over postMessage with an explicit
 * target origin (never "*").
 *
 * Usage (GA-style snippet):
 *   <script>
 *     (function(w,d,s,c){var j=d.createElement(s);j.async=1;j.src=c.loaderUrl;
 *     j.onload=function(){w.OpbxDialer.init(c);};d.head.appendChild(j);})
 *     (window,document,'script',{
 *       loaderUrl:'https://opbx.example.com/embed/loader.js',
 *       token:'opbxd_XXXX',
 *       iconPosition:'bottom-right',
 *       iconBackgroundColor:'#007acc'
 *     });
 *   </script>
 */
(function () {
  'use strict';

  if (window.OpbxDialer && window.OpbxDialer.__ready) {
    return;
  }

  // Derive the OPBX origin from this script's own src so postMessage/iframe
  // always target the serving origin.
  var scriptEl = document.currentScript;
  var opbxOrigin = (function () {
    try {
      return new URL(scriptEl.src).origin;
    } catch (e) {
      return window.location.origin;
    }
  })();

  var CORNER = {
    'bottom-right': 'bottom:16px;right:16px;',
    'bottom-left': 'bottom:16px;left:16px;',
    'top-right': 'top:16px;right:16px;',
    'top-left': 'top:16px;left:16px;'
  };

  var iframeEl = null;
  var handlers = {};

  function post(name, args) {
    if (!iframeEl || !iframeEl.contentWindow) {
      return;
    }
    iframeEl.contentWindow.postMessage(
      { source: 'opbx-dialer', type: 'command', name: name, args: args || [] },
      opbxOrigin
    );
  }

  function onMessage(event) {
    if (event.origin !== opbxOrigin) {
      return;
    }
    var data = event.data;
    if (!data || data.source !== 'opbx-dialer-widget' || data.type !== 'event') {
      return;
    }
    var list = handlers[data.name] || [];
    for (var i = 0; i < list.length; i++) {
      try {
        list[i](data.payload);
      } catch (e) {
        /* swallow handler errors */
      }
    }
  }

  function init(config) {
    config = config || {};
    if (iframeEl) {
      return;
    }

    var pos = CORNER[config.iconPosition] ? config.iconPosition : 'bottom-right';
    var params =
      '?token=' + encodeURIComponent(config.token || '') +
      '&iconPosition=' + encodeURIComponent(pos) +
      '&iconBackgroundColor=' + encodeURIComponent(config.iconBackgroundColor || '#007acc');

    iframeEl = document.createElement('iframe');
    iframeEl.src = opbxOrigin + '/embed/dialer' + params;
    iframeEl.title = 'OPBX Dialer';
    iframeEl.allow = 'microphone';
    iframeEl.setAttribute('aria-label', 'OPBX Dialer');
    iframeEl.style.cssText =
      'position:fixed;' + CORNER[pos] +
      'width:360px;height:600px;max-width:96vw;max-height:90vh;' +
      'border:0;z-index:2147483000;background:transparent;color-scheme:normal;';

    window.addEventListener('message', onMessage);
    document.body.appendChild(iframeEl);
  }

  window.OpbxDialer = {
    __ready: true,
    init: init,
    dial: function (number) { post('dial', [number]); },
    hangup: function () { post('hangup', []); },
    open: function () { post('open', []); },
    close: function () { post('close', []); },
    on: function (event, cb) {
      if (typeof cb !== 'function') {
        return;
      }
      (handlers[event] = handlers[event] || []).push(cb);
    }
  };
})();
