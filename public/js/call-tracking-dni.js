/**
 * OPBX Call Tracking — Dynamic Number Insertion (DNI) Snippet
 *
 * Embeddable, framework-agnostic script that swaps phone numbers on a page
 * based on URL attribution parameters (utm_source, utm_medium, utm_campaign).
 *
 * Example:
 *   <script async src="https://opbx.example.com/js/call-tracking-dni.js"
 *     data-organization-id="1"
 *     data-default="+14155550000"
 *     data-selector=".phone-number"></script>
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'opbx_ct_number';
  var DEFAULT_SELECTOR = '.call-tracking-number';
  var ENDPOINT = '/api/v1/call-tracking-dni/swap';

  function getCurrentScript() {
    if (document.currentScript) {
      return document.currentScript;
    }
    var scripts = document.getElementsByTagName('script');
    for (var i = scripts.length - 1; i >= 0; i--) {
      var src = scripts[i].getAttribute('src') || '';
      if (src.indexOf('call-tracking-dni.js') !== -1) {
        return scripts[i];
      }
    }
    return null;
  }

  function getQueryParam(name) {
    var match = new RegExp('[?&]' + encodeURIComponent(name) + '=([^&]*)').exec(window.location.search);
    return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
  }

  function getElements(selector) {
    try {
      return Array.prototype.slice.call(document.querySelectorAll(selector));
    } catch (e) {
      return [];
    }
  }

  function hideElement(el) {
    if (el.style && typeof el.style.opacity !== 'undefined') {
      el.style.opacity = '0';
    }
  }

  function showElement(el) {
    if (el.style && typeof el.style.opacity !== 'undefined') {
      el.style.opacity = '';
    }
  }

  function applyNumber(elements, number) {
    if (!number) {
      elements.forEach(showElement);
      return;
    }
    elements.forEach(function (el) {
      el.textContent = number;
      showElement(el);
    });
  }

  function makeRequest(url, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) {
        return;
      }
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          callback(null, JSON.parse(xhr.responseText));
          return;
        } catch (e) {
          callback(e, null);
          return;
        }
      }
      callback(new Error('DNI swap failed with status ' + xhr.status), null);
    };
    xhr.onerror = function () {
      callback(new Error('DNI swap request failed'), null);
    };
    xhr.send();
  }

  function run() {
    var script = getCurrentScript();
    if (!script) {
      return;
    }

    var organizationId = (script.getAttribute('data-organization-id') || '').trim();
    if (!organizationId) {
      return;
    }

    var fallbackNumber = (script.getAttribute('data-default') || '').trim();
    var selector = (script.getAttribute('data-selector') || DEFAULT_SELECTOR).trim();
    var elements = getElements(selector);

    elements.forEach(hideElement);

    var cachedNumber = null;
    try {
      cachedNumber = window.sessionStorage.getItem(STORAGE_KEY);
    } catch (e) {
      // sessionStorage may be unavailable in private mode or disabled.
    }

    if (cachedNumber) {
      applyNumber(elements, cachedNumber);
      return;
    }

    var utmSource = getQueryParam('utm_source');
    var utmMedium = getQueryParam('utm_medium');
    var utmCampaign = getQueryParam('utm_campaign');

    var params = [
      'organization_id=' + encodeURIComponent(organizationId),
      'utm_source=' + encodeURIComponent(utmSource),
      'utm_medium=' + encodeURIComponent(utmMedium),
      'utm_campaign=' + encodeURIComponent(utmCampaign)
    ];

    if (fallbackNumber) {
      params.push('default_number=' + encodeURIComponent(fallbackNumber));
    }

    var url = ENDPOINT + '?' + params.join('&');

    makeRequest(url, function (err, response) {
      var trackingNumber = (response && response.tracking_number) ? response.tracking_number : fallbackNumber;

      if (trackingNumber) {
        try {
          window.sessionStorage.setItem(STORAGE_KEY, trackingNumber);
        } catch (e) {
          // Ignore storage errors.
        }
      }

      applyNumber(elements, trackingNumber);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
