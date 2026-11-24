/**
 * BLUE-2725 — OpenAI Ads Measurement Pixel, loaded for the hotel by Bluerails.
 *
 * Config comes from `window.bluerailsOaiAds` (printed by class-bluerails-openai-ads-measurement.php):
 * `pixelId`, `bookingHosts` (a host, or a host with a path prefix) and `rcbService`.
 *
 * What it does, in order:
 * 1. If `window.oaiq` already exists, the site already runs a Pixel (installed by hand or by its
 *    consent banner), so this file does nothing at all, now or later.
 * 2. It waits for consent, and loads nothing from OpenAI before it:
 *    - Borlabs Cookie: `BorlabsCookie.Consents.hasConsentForServiceGroup('marketing')`, checked when
 *      Borlabs has started (`borlabs-cookie-after-init`, `borlabs-cookie-handle-unblock`) and again
 *      on `borlabs-cookie-consent-saved`. A later withdrawal calls `oaiq("consent", false)`.
 *    - Real Cookie Banner: `consentApi.consent(rcbService)`, a promise that resolves once the visitor
 *      accepts the banner service with that unique name. Withdrawal is checked on RCB's
 *      `RCB/OptOut` event with `consentApi.consentSync`.
 *    - Neither: the Pixel loads with OpenAI's own consent set to false before `init`, so it sends
 *      nothing until the site's own consent tool calls `oaiq("consent", true)`.
 * 3. On consent it runs OpenAI's install snippet unchanged (it has its own `if (w.oaiq) return;`),
 *    initialises the Pixel and sends `page_viewed`.
 * 4. A click on a link to a booking target sends `checkout_started` with
 *    `{ type: "contents", contents: [] }`, once per click.
 *
 * Reference: OpenAI Ads docs, "Measurement Pixel" (install, consent, send events) and "Supported
 * events" (`checkout_started` uses the `contents` data type; `contents` is optional).
 */
(function (w, d) {
  "use strict";

  var config = w.bluerailsOaiAds;
  if (!config || typeof config.pixelId !== "string" || !config.pixelId) return;
  if (w.oaiq) return;

  var SDK_URL = "https://bzrcdn.openai.com/sdk/oaiq.min.js";
  var targets = parseTargets(config.bookingHosts);
  var started = false;
  var lastCheckoutAt = 0;

  function parseTargets(list) {
    var out = [];
    if (!list || typeof list.length !== "number") return out;
    for (var i = 0; i < list.length; i++) {
      var entry = String(list[i] || "").toLowerCase();
      var slash = entry.indexOf("/");
      var host = slash === -1 ? entry : entry.slice(0, slash);
      var path = slash === -1 ? "" : entry.slice(slash);
      if (host) out.push({ host: host, path: path });
    }
    return out;
  }

  function isBookingUrl(href) {
    var url;
    try {
      url = new URL(href, w.location.href);
    } catch (e) {
      return false;
    }
    if (url.protocol !== "https:" && url.protocol !== "http:") return false;
    var host = url.hostname.toLowerCase();
    var path = url.pathname.toLowerCase();
    for (var i = 0; i < targets.length; i++) {
      var t = targets[i];
      var hostMatches =
        host === t.host || host.slice(-(t.host.length + 1)) === "." + t.host;
      if (!hostMatches) continue;
      if (!t.path || path === t.path || path.indexOf(t.path + "/") === 0)
        return true;
    }
    return false;
  }

  function onClick(event) {
    if (event.type === "auxclick" && event.button !== 1) return;
    var node = event.target;
    while (node && node !== d && !(node.tagName === "A" && node.href))
      node = node.parentNode;
    if (!node || node === d || !isBookingUrl(node.href)) return;
    var now = Date.now();
    if (now - lastCheckoutAt < 1000) return;
    lastCheckoutAt = now;
    w.oaiq("measure", "checkout_started", { type: "contents", contents: [] });
  }

  // OpenAI's install snippet, verbatim apart from the init call that follows it.
  function installPixel() {
    (function (w, d, s, u) {
      if (w.oaiq) return;
      var q = function () {
        q.q.push(arguments);
      };
      q.q = [];
      w.oaiq = q;
      var js = d.createElement(s);
      js.async = true;
      js.src = u;
      var f = d.getElementsByTagName(s)[0];
      f.parentNode.insertBefore(js, f);
    })(w, d, "script", SDK_URL);
  }

  function start(consentGranted) {
    if (started) {
      if (consentGranted) w.oaiq("consent", true);
      return;
    }
    // A Pixel the site's own banner unblocked in the meantime wins; never load a second one.
    if (w.oaiq) return;
    started = true;
    installPixel();
    w.oaiq("consent", consentGranted);
    w.oaiq("init", { pixelId: config.pixelId });
    w.oaiq("measure", "page_viewed", { type: "contents" });
    if (targets.length) {
      d.addEventListener("click", onClick, true);
      d.addEventListener("auxclick", onClick, true);
    }
  }

  function withdraw() {
    if (started) w.oaiq("consent", false);
  }

  function useBorlabs() {
    var check = function () {
      var api = w.BorlabsCookie && w.BorlabsCookie.Consents;
      if (!api || typeof api.hasConsentForServiceGroup !== "function") return;
      var granted = false;
      try {
        granted = api.hasConsentForServiceGroup("marketing") === true;
      } catch (e) {
        granted = false;
      }
      if (granted) start(true);
      else withdraw();
    };
    d.addEventListener("borlabs-cookie-after-init", check);
    d.addEventListener("borlabs-cookie-handle-unblock", check);
    d.addEventListener("borlabs-cookie-consent-saved", check);
    check();
  }

  function useRealCookieBanner() {
    var api = w.consentApi;
    var service = String(config.rcbService || "");
    if (!service) return;
    api.consent(service).then(
      function () {
        start(true);
      },
      function () {},
    );
    var recheck = function () {
      try {
        var state = w.consentApi.consentSync(service);
        if (!state || !state.consentGiven || !state.cookieOptIn) withdraw();
      } catch (e) {
        withdraw();
      }
    };
    d.addEventListener("RCB/OptOut", recheck);
    w.addEventListener("RCB/OptOut", recheck);
  }

  function chooseConsent() {
    if (w.oaiq && !started) return;
    if (w.BorlabsCookie || w.borlabsCookieConfig) useBorlabs();
    else if (w.consentApi && typeof w.consentApi.consent === "function")
      useRealCookieBanner();
    else start(false);
  }

  // Borlabs Cookie's core is an ES module and runs after the HTML is parsed, so the consent tool
  // is chosen once the document has loaded, not while this <head> script runs.
  if (d.readyState === "loading")
    d.addEventListener("DOMContentLoaded", chooseConsent);
  else chooseConsent();
})(window, document);
