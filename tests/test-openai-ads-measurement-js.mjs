// OpenAI Ads measurement loader (BLUE-2725): runs assets/js/bluerails-openai-ads-measurement.js
// against a fake window/document and checks what it queues on `oaiq` and which scripts it inserts.
//
// Run:  node tests/test-openai-ads-measurement-js.mjs
// Exits 0 on all-pass, 1 on any failure.

import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(
  join(here, "../assets/js/bluerails-openai-ads-measurement.js"),
  "utf8",
);

let failures = 0;
function check(label, passed) {
  if (!passed) failures++;
  console.log(`[${passed ? "PASS" : "FAIL"}] ${label}`);
}

function makeTarget() {
  const listeners = {};
  return {
    addEventListener(type, fn) {
      (listeners[type] ||= []).push(fn);
    },
    dispatch(type, event = {}) {
      for (const fn of listeners[type] || []) fn({ type, ...event });
    },
    listeners,
  };
}

function makeEnv({
  readyState = "complete",
  extra = {},
  assumeConsent = false,
} = {}) {
  const inserted = [];
  const doc = makeTarget();
  doc.readyState = readyState;
  doc.createElement = (tag) => ({ tagName: tag.toUpperCase() });
  const firstScript = {
    parentNode: { insertBefore: (el) => inserted.push(el) },
  };
  doc.getElementsByTagName = () => [firstScript];
  const win = makeTarget();
  Object.assign(win, {
    location: { href: "https://schwitzers.com/" },
    bluerailsOaiAds: {
      pixelId: "WJiUXtEVpLN3BqnY3LKTx2",
      bookingHosts: [
        "reservation.one.dirs21.de",
        "schwitzers.com/hotel-buchen",
      ],
      rcbService: "openai-ads-measurement",
      assumeConsent,
    },
    URL,
    Date,
    ...extra,
  });
  // Fake interval clock: advance(ms) fires every live interval once per 250 ms step.
  const intervals = new Map();
  let nextId = 1;
  const setInterval = (fn) => {
    intervals.set(nextId, fn);
    return nextId++;
  };
  const clearInterval = (id) => intervals.delete(id);
  const advance = (ms) => {
    for (let t = 250; t <= ms; t += 250)
      for (const fn of [...intervals.values()]) fn();
  };
  const context = {
    window: win,
    document: doc,
    URL,
    Date,
    Promise,
    setInterval,
    clearInterval,
  };
  return {
    win,
    doc,
    inserted,
    advance,
    intervals,
    run: () => vm.runInNewContext(source, context),
  };
}

const calls = (win) =>
  win.oaiq && win.oaiq.q ? win.oaiq.q.map((args) => Array.from(args)) : [];
const last = (win) => JSON.stringify(calls(win).at(-1));
const link = (href) => ({ tagName: "A", href, parentNode: null });
const borlabs = (granted) => ({
  borlabsCookieConfig: {},
  BorlabsCookie: {
    Consents: {
      hasConsentForServiceGroup: (g) => g === "marketing" && granted(),
    },
  },
});

// 1. A Pixel already on the page: do nothing.
{
  const pre = () => {};
  const env = makeEnv({ extra: { oaiq: pre } });
  env.run();
  check(
    "existing window.oaiq: no script inserted, oaiq untouched",
    env.inserted.length === 0 && env.win.oaiq === pre,
  );
}

// 2. Borlabs Cookie, marketing group.
{
  let marketing = false;
  const env = makeEnv({ extra: borlabs(() => marketing) });
  env.run();
  check(
    "Borlabs without consent: nothing loaded",
    env.inserted.length === 0 && env.win.oaiq === undefined,
  );
  env.doc.dispatch("borlabs-cookie-handle-unblock");
  check(
    "Borlabs unblock without marketing consent: still nothing",
    env.inserted.length === 0,
  );
  marketing = true;
  env.doc.dispatch("borlabs-cookie-consent-saved");
  check(
    "Borlabs consent: SDK inserted once from bzrcdn",
    env.inserted.length === 1 &&
      env.inserted[0].src === "https://bzrcdn.openai.com/sdk/oaiq.min.js" &&
      env.inserted[0].async === true,
  );
  check(
    "Borlabs consent: consent true, init, page_viewed in that order",
    JSON.stringify(calls(env.win)) ===
      JSON.stringify([
        ["consent", true],
        ["init", { pixelId: "WJiUXtEVpLN3BqnY3LKTx2" }],
        ["measure", "page_viewed", { type: "contents" }],
      ]),
  );
  env.doc.dispatch("borlabs-cookie-consent-saved");
  env.win.dispatch("borlabs-cookie-consent-saved");
  check(
    "the same consent heard again on document and window: no second SDK, nothing queued",
    env.inserted.length === 1 && calls(env.win).length === 3,
  );

  const before = calls(env.win).length;
  env.doc.dispatch("click", {
    target: link(
      "https://reservation.one.dirs21.de/schwitzers-hotel-am-parkcom/result",
    ),
  });
  check(
    "click to the booking host: checkout_started with empty contents",
    last(env.win) ===
      JSON.stringify([
        "measure",
        "checkout_started",
        { type: "contents", contents: [] },
      ]),
  );
  env.doc.dispatch("click", {
    target: link("https://reservation.one.dirs21.de/x"),
  });
  check(
    "a double click sends one checkout_started",
    calls(env.win).length === before + 1,
  );

  const count = calls(env.win).length;
  env.doc.dispatch("click", {
    target: link("https://www.instagram.com/schwitzers"),
  });
  env.doc.dispatch("click", {
    target: link("https://schwitzers.com/restaurant/"),
  });
  env.doc.dispatch("click", {
    target: link("https://schwitzers.com/hotel-buchen-alt/"),
  });
  env.doc.dispatch("click", { target: link("mailto:info@schwitzers.com") });
  check(
    "clicks elsewhere (other host, other path, path lookalike, mailto) send nothing",
    calls(env.win).length === count,
  );

  marketing = false;
  env.doc.dispatch("borlabs-cookie-consent-saved");
  check(
    "Borlabs withdrawal: consent false",
    last(env.win) === JSON.stringify(["consent", false]),
  );
  const afterWithdraw = calls(env.win).length;
  lastClickGap();
  env.doc.dispatch("click", {
    target: link("https://reservation.one.dirs21.de/again"),
  });
  check(
    "after withdrawal a booking click sends nothing",
    calls(env.win).length === afterWithdraw,
  );
  marketing = true;
  env.win.dispatch("borlabs-cookie-consent-saved");
  check(
    "consent again, heard on window: consent true, no second SDK",
    last(env.win) === JSON.stringify(["consent", true]) &&
      env.inserted.length === 1,
  );
}

// The loader sends at most one checkout_started per second; wait that out between scenarios that need it.
function lastClickGap() {
  const until = Date.now() + 1100;
  while (Date.now() < until);
}

// 3. Booking path on the hotel's own site, nested element inside the link, middle click.
{
  const env = makeEnv({ extra: borlabs(() => true) });
  env.run();
  const anchor = link("https://schwitzers.com/hotel-buchen/");
  env.doc.dispatch("auxclick", {
    button: 1,
    target: { tagName: "SPAN", parentNode: anchor },
  });
  check(
    "middle click on a span inside a booking-path link counts",
    calls(env.win).at(-1)[1] === "checkout_started",
  );
}

// 4. Borlabs core runs after the HTML is parsed: wait for DOMContentLoaded.
{
  const env = makeEnv({
    readyState: "loading",
    extra: { borlabsCookieConfig: {} },
  });
  env.run();
  check(
    "loading document: nothing decided yet",
    env.inserted.length === 0 && env.win.oaiq === undefined,
  );
  env.win.BorlabsCookie = {
    Consents: { hasConsentForServiceGroup: () => true },
  };
  env.doc.dispatch("DOMContentLoaded");
  check(
    "after DOMContentLoaded with consent: Pixel loaded",
    env.inserted.length === 1,
  );
}

// 5. Real Cookie Banner: consentSync must name the service and report it opted in.
{
  let state = { cookie: null, consentGiven: false, cookieOptIn: true }; // RCB's own pre-load stub answer
  const asked = [];
  const env = makeEnv({
    extra: {
      consentApi: {
        consent: () => Promise.resolve(), // never relied on: it resolves at once for an unknown service
        consentSync: (name) => {
          asked.push(name);
          return state;
        },
      },
    },
  });
  env.run();
  check(
    "RCB before a decision: asks for the configured service, loads nothing",
    asked[0] === "openai-ads-measurement" && env.inserted.length === 0,
  );
  state = { cookie: null, consentGiven: true, cookieOptIn: true }; // decided, but no service has that name
  env.doc.dispatch("RCB/OptIn/All");
  check(
    "RCB with no service of that name: still nothing",
    env.inserted.length === 0,
  );
  const service = { id: 7, uniqueName: "openai-ads-measurement" };
  state = { cookie: service, consentGiven: true, cookieOptIn: true };
  env.doc.dispatch("RCB/OptIn", { detail: { service } });
  check(
    "RCB opt-in: Pixel loaded with consent true",
    env.inserted.length === 1 &&
      JSON.stringify(calls(env.win)[0]) === JSON.stringify(["consent", true]),
  );
  state = { cookie: service, consentGiven: true, cookieOptIn: false };
  env.doc.dispatch("RCB/OptOut", { detail: { service } });
  check(
    "RCB opt-out: consent false",
    last(env.win) === JSON.stringify(["consent", false]),
  );
  const n = calls(env.win).length;
  env.doc.dispatch("click", {
    target: link("https://reservation.one.dirs21.de/x"),
  });
  check(
    "after opt-out a booking click sends nothing",
    calls(env.win).length === n,
  );
  state = { cookie: service, consentGiven: true, cookieOptIn: true };
  env.doc.dispatch("RCB/OptIn", { detail: { service } });
  check(
    "RCB opt-in again: consent true again, no second SDK",
    last(env.win) === JSON.stringify(["consent", true]) &&
      env.inserted.length === 1,
  );
  env.doc.dispatch("click", {
    target: link("https://reservation.one.dirs21.de/x"),
  });
  check(
    "after opting in again a booking click counts",
    calls(env.win).at(-1)[1] === "checkout_started",
  );
}
{
  let state = { cookie: null, consentGiven: false, cookieOptIn: true };
  const env = makeEnv({ extra: { consentApi: { consentSync: () => state } } });
  env.run();
  state = { cookie: { id: 1 }, consentGiven: true, cookieOptIn: true };
  env.win.dispatch("consentApi");
  check(
    'RCB API becoming ready (window "consentApi") with consent already stored: Pixel loaded',
    env.inserted.length === 1,
  );
}

// 6. No consent tool: fail closed.
{
  const env = makeEnv();
  env.run();
  env.advance(3000);
  check(
    "no CMP after the 3 s wait: nothing inserted, no oaiq",
    env.inserted.length === 0 && env.win.oaiq === undefined,
  );
  check("the wait stops after 3 s", env.intervals.size === 0);
}
{
  const env = makeEnv({ assumeConsent: true });
  env.run();
  env.advance(2750);
  check(
    "assume-consent site: nothing before the wait ends",
    env.inserted.length === 0,
  );
  env.advance(250);
  check(
    "assume-consent site: Pixel loaded with consent true after the wait",
    env.inserted.length === 1 &&
      JSON.stringify(calls(env.win)[0]) === JSON.stringify(["consent", true]),
  );
}
{
  let marketing = false;
  const env = makeEnv();
  env.run();
  env.advance(1000);
  Object.assign(
    env.win,
    borlabs(() => marketing),
  );
  env.advance(250);
  check(
    "Borlabs appearing 1.25 s late is picked up; nothing loads without consent",
    env.inserted.length === 0 && env.intervals.size === 0,
  );
  marketing = true;
  env.doc.dispatch("borlabs-cookie-consent-saved");
  check("late Borlabs consent: Pixel loaded", env.inserted.length === 1);
}
{
  const env = makeEnv();
  env.run();
  env.advance(500);
  env.win.consentApi = {
    consentSync: () => ({
      cookie: { id: 1 },
      consentGiven: true,
      cookieOptIn: true,
    }),
  };
  env.advance(250);
  check(
    "RCB API appearing late with consent already given: Pixel loaded",
    env.inserted.length === 1,
  );
}
{
  const env = makeEnv({ assumeConsent: true });
  env.run();
  env.advance(3000);
  Object.assign(
    env.win,
    borlabs(() => false),
  );
  env.doc.dispatch("borlabs-cookie-consent-saved");
  check(
    "a consent tool appearing after the wait is not used; assume-consent already decided",
    env.inserted.length === 1,
  );
}

// 7. The site's own banner unblocks a Pixel before ours gets consent: never a second one.
{
  let marketing = false;
  const env = makeEnv({ extra: borlabs(() => marketing) });
  env.run();
  const theirs = () => {};
  env.win.oaiq = theirs;
  marketing = true;
  env.doc.dispatch("borlabs-cookie-consent-saved");
  check(
    "Pixel appeared before consent: ours never loads",
    env.inserted.length === 0 && env.win.oaiq === theirs,
  );
}

// 8. Bad config: nothing.
{
  const env = makeEnv();
  env.win.bluerailsOaiAds = { pixelId: "" };
  env.run();
  env.advance(3000);
  check(
    "no pixel id: nothing loaded",
    env.inserted.length === 0 && env.win.oaiq === undefined,
  );
}

console.log(`\n${failures} failure(s)`);
process.exit(failures > 0 ? 1 : 0);
