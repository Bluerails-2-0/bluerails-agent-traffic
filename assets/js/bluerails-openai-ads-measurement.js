/**
 * BLUE-2725 — OpenAI Ads Measurement Pixel, loaded for the hotel by Bluerails.
 *
 * Config comes from `window.bluerailsOaiAds` (printed by class-bluerails-openai-ads-measurement.php):
 * `pixelId`, `bookingHosts` (a host, or a host with a path prefix), `rcbService` and `assumeConsent`.
 *
 * What it does, in order:
 * 1. If `window.oaiq` already exists, the site already runs a Pixel (installed by hand or by its
 *    consent banner), so this file does nothing at all, now or later.
 * 2. It finds the site's consent tool, waiting up to 3 seconds after the page has loaded for one to
 *    appear, and loads nothing from OpenAI before that tool reports consent:
 *    - Borlabs Cookie: `BorlabsCookie.Consents.hasConsentForServiceGroup('marketing')`, checked when
 *      Borlabs starts (`borlabs-cookie-after-init`, `borlabs-cookie-handle-unblock`) and on every
 *      `borlabs-cookie-consent-saved`. Borlabs 3.5.1 dispatches these on `document`; they are also
 *      heard on `window`.
 *    - Real Cookie Banner: `consentApi.consentSync(rcbService)` must name the service and report it
 *      opted in. Checked when RCB's API is ready (`consentApi` on `window`) and on every `RCB/OptIn`,
 *      `RCB/OptOut`, `RCB/OptIn/All` and `RCB/OptOut/All` (RCB listens for these on `document`).
 *      `consentApi.consent()` is not used: it resolves at once when no service has that name.
 *    - Neither: nothing loads (fail closed), unless the site set the `bluerails_oai_ads_assume_consent`
 *      filter, declaring that consent is handled elsewhere.
 *    Withdrawn consent calls `oaiq("consent", false)`, which stops events and deletes the Pixel's
 *    cookies; consent given again calls `oaiq("consent", true)`.
 * 3. On first consent it runs OpenAI's install snippet unchanged (it has its own `if (w.oaiq) return;`),
 *    initialises the Pixel and sends `page_viewed`.
 * 4. While consent stands, a click on a link to a booking target sends `checkout_started` with
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
  var WAIT_FOR_CMP_MS = 3000;
  var POLL_MS = 250;
  var BORLABS_EVENTS = [
    "borlabs-cookie-after-init",
    "borlabs-cookie-handle-unblock",
    "borlabs-cookie-consent-saved",
  ];
  var RCB_EVENTS = [
    "RCB/OptIn",
    "RCB/OptOut",
    "RCB/OptIn/All",
    "RCB/OptOut/All",
  ];

  var targets = parseTargets(config.bookingHosts);
  var started = false;
  var consented = false;
  var cmp = null;
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
    if (!consented) return;
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

  // Every consent signal lands here, so a repeated or duplicated event changes nothing.
  function setConsent(granted) {
    if (granted === consented) return;
    if (granted && !started) {
      // A Pixel the site's own banner unblocked in the meantime wins; never load a second one.
      if (w.oaiq) return;
      consented = true;
      started = true;
      installPixel();
      w.oaiq("consent", true);
      w.oaiq("init", { pixelId: config.pixelId });
      w.oaiq("measure", "page_viewed", { type: "contents" });
      if (targets.length) {
        d.addEventListener("click", onClick, true);
        d.addEventListener("auxclick", onClick, true);
      }
      return;
    }
    consented = granted;
    if (started) w.oaiq("consent", granted);
  }

  function listen(names, fn) {
    for (var i = 0; i < names.length; i++) {
      d.addEventListener(names[i], fn);
      w.addEventListener(names[i], fn);
    }
  }

  function checkBorlabs() {
    var api = w.BorlabsCookie && w.BorlabsCookie.Consents;
    if (!api || typeof api.hasConsentForServiceGroup !== "function") return;
    var granted = false;
    try {
      granted = api.hasConsentForServiceGroup("marketing") === true;
    } catch (e) {
      granted = false;
    }
    setConsent(granted);
  }

  function checkRealCookieBanner() {
    var api = w.consentApi;
    var service = String(config.rcbService || "");
    if (!api || typeof api.consentSync !== "function" || !service) return;
    var state = null;
    try {
      state = api.consentSync(service);
    } catch (e) {
      state = null;
    }
    setConsent(
      !!(state && state.cookie && state.consentGiven && state.cookieOptIn),
    );
  }

  function hasBorlabs() {
    return !!(w.BorlabsCookie || w.borlabsCookieConfig);
  }

  function hasRealCookieBanner() {
    return !!(w.consentApi && typeof w.consentApi.consentSync === "function");
  }

  // Returns true once a consent tool is found and wired.
  function tryChoose() {
    if (cmp) return true;
    if (hasBorlabs()) {
      cmp = "borlabs";
      listen(BORLABS_EVENTS, checkBorlabs);
      checkBorlabs();
      return true;
    }
    if (hasRealCookieBanner()) {
      cmp = "rcb";
      listen(RCB_EVENTS, checkRealCookieBanner);
      w.addEventListener("consentApi", checkRealCookieBanner);
      checkRealCookieBanner();
      return true;
    }
    return false;
  }

  function chooseConsent() {
    if (tryChoose()) return;
    // A consent tool may load after this script: give it up to WAIT_FOR_CMP_MS to appear.
    var waited = 0;
    var timer = setInterval(function () {
      waited += POLL_MS;
      if (tryChoose()) {
        clearInterval(timer);
      } else if (waited >= WAIT_FOR_CMP_MS) {
        clearInterval(timer);
        cmp = "none";
        if (config.assumeConsent === true) setConsent(true);
      }
    }, POLL_MS);
  }

  // Borlabs Cookie's core is an ES module and runs after the HTML is parsed, so the consent tool
  // is chosen once the document has loaded, not while this <head> script runs.
  if (d.readyState === "loading")
    d.addEventListener("DOMContentLoaded", chooseConsent);
  else chooseConsent();
})(window, document);
