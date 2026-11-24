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

function makeEnv({ readyState = "complete", extra = {} } = {}) {
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
    },
    URL,
    Date,
    ...extra,
  });
  const context = { window: win, document: doc, URL, Date, Promise };
  return { win, doc, inserted, run: () => vm.runInNewContext(source, context) };
}

const calls = (win) =>
  win.oaiq && win.oaiq.q ? win.oaiq.q.map((args) => Array.from(args)) : [];
const link = (href) => ({ tagName: "A", href, parentNode: null });
const tick = () => new Promise((r) => setTimeout(r, 0));

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
  const env = makeEnv({
    extra: {
      borlabsCookieConfig: {},
      BorlabsCookie: {
        Consents: {
          hasConsentForServiceGroup: (g) => g === "marketing" && marketing,
        },
      },
    },
  });
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
  check(
    "a second consent event does not load a second SDK",
    env.inserted.length === 1,
  );

  const before = calls(env.win).length;
  env.doc.dispatch("click", {
    target: link(
      "https://reservation.one.dirs21.de/schwitzers-hotel-am-parkcom/result",
    ),
  });
  const last = calls(env.win).at(-1);
  check(
    "click to the booking host: checkout_started with empty contents",
    JSON.stringify(last) ===
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

  const env2count = calls(env.win).length;
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
    calls(env.win).length === env2count,
  );

  marketing = false;
  env.doc.dispatch("borlabs-cookie-consent-saved");
  check(
    "Borlabs withdrawal: consent false",
    JSON.stringify(calls(env.win).at(-1)) ===
      JSON.stringify(["consent", false]),
  );
}

// 3. Booking path on the hotel's own site, nested element inside the link, middle click.
{
  const env = makeEnv({
    extra: {
      borlabsCookieConfig: {},
      BorlabsCookie: { Consents: { hasConsentForServiceGroup: () => true } },
    },
  });
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

// 5. Real Cookie Banner.
{
  let resolve;
  const asked = [];
  const env = makeEnv({
    extra: {
      consentApi: {
        consent: (name) => {
          asked.push(name);
          return new Promise((r) => (resolve = r));
        },
        consentSync: () => ({
          cookie: null,
          consentGiven: false,
          cookieOptIn: true,
        }),
      },
    },
  });
  env.run();
  check(
    "RCB: asks for the configured service, loads nothing yet",
    asked[0] === "openai-ads-measurement" && env.inserted.length === 0,
  );
  resolve();
  await tick();
  check(
    "RCB consent: Pixel loaded with consent true",
    env.inserted.length === 1 &&
      JSON.stringify(calls(env.win)[0]) === JSON.stringify(["consent", true]),
  );
  env.win.dispatch("RCB/OptOut");
  check(
    "RCB opt-out: consent false",
    JSON.stringify(calls(env.win).at(-1)) ===
      JSON.stringify(["consent", false]),
  );
}

// 6. No consent tool: OpenAI's own consent API, false before init.
{
  const env = makeEnv();
  env.run();
  const q = calls(env.win);
  check(
    "no CMP: SDK loaded with consent false before init",
    env.inserted.length === 1 &&
      JSON.stringify(q[0]) === JSON.stringify(["consent", false]) &&
      q[1][0] === "init",
  );
}

// 7. The site's own banner unblocks a Pixel before ours gets consent: never a second one.
{
  let marketing = false;
  const env = makeEnv({
    extra: {
      borlabsCookieConfig: {},
      BorlabsCookie: {
        Consents: { hasConsentForServiceGroup: () => marketing },
      },
    },
  });
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
  check(
    "no pixel id: nothing loaded",
    env.inserted.length === 0 && env.win.oaiq === undefined,
  );
}

console.log(`\n${failures} failure(s)`);
process.exit(failures > 0 ? 1 : 0);
