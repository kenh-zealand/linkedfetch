// LinkedFetch – gemte LinkedIn-opslag
// Copyright (c) 2026 Kenneth Hansen
//
// Baggrundsdelen: åbner LinkedIn-fanen, styrer kørslerne og sender opslagene
// til WordPress-pluginet LinkedFetch på keha.dk.

const SAVED_URL = "https://www.linkedin.com/my-items/saved-posts/";

let running = false;
let scrapeDone = false;
let runMode = null;
let runTabId = null;
let stats = newStats();

function newStats() {
  return { seen: 0, found: 0, uploaded: 0, errors: 0 };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------- Log og status ----------

let logChain = Promise.resolve();
function log(msg) {
  logChain = logChain.then(async () => {
    const line = `${new Date().toLocaleString("da-DK")}  ${msg}`;
    const { log: arr = [] } = await chrome.storage.local.get("log");
    arr.push(line);
    while (arr.length > 300) arr.shift();
    await chrome.storage.local.set({ log: arr });
  });
  return logChain;
}

function setStatus() {
  return chrome.storage.local.set({
    status: {
      running,
      mode: runMode,
      ...stats,
      queued: kehaLeft,
      updated: Date.now()
    }
  });
}

async function settings() {
  return chrome.storage.local.get(["autoDaily", "kehaUrl", "kehaUser", "kehaPass"]);
}

function configured(s) {
  return !!(s.kehaUser && s.kehaPass);
}

// ---------- keha.dk (WordPress-pluginet LinkedFetch) ----------

let kehaKnown = new Set();
let kehaNoImage = new Set();
let kehaNeedShare = new Set(); // opslag på keha.dk, hvor delt indhold ikke er tjekket
let kehaMax = 0;
let kehaPending = []; // nye opslag, nyeste først
let kehaFills = []; // eksisterende opslag, der mangler billede
let kehaShareFills = []; // eksisterende opslag, der får titel, kilde og link på delt indhold
let kehaBusy = false;
let kehaLeft = 0;

function kehaBase(s) {
  return (s.kehaUrl || "https://keha.dk").trim().replace(/\/+$/, "");
}

async function keha(path, method = "GET", body) {
  const s = await settings();
  const auth = btoa(unescape(encodeURIComponent(`${s.kehaUser}:${(s.kehaPass || "").replace(/\s+/g, "")}`)));
  let res;
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      // Ingen cookies: er man logget ind på keha.dk i samme Chrome, ville WordPress
      // ellers bruge login-cookien (og afvise den) i stedet for applikationsadgangskoden.
      res = await fetch(`${kehaBase(s)}/wp-json/linkedfetch/v1/${path}`, {
        method,
        credentials: "omit",
        headers: { Authorization: "Basic " + auth, "Content-Type": "application/json" },
        body: body ? JSON.stringify(body) : undefined
      });
      if (res.status < 500) break;
    } catch (e) {
      if (attempt === 2) throw new Error("keha.dk kunne ikke nås: " + e.message);
    }
    await sleep(3000 * (attempt + 1));
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(`keha.dk svarede ${res.status}: ${data.message || res.statusText}`);
  return data;
}

async function loadKehaKnown() {
  const r = await keha("ids");
  kehaKnown = new Set(r.ids || []);
  kehaNoImage = new Set(r.noImage || []);
  kehaNeedShare = new Set(r.needShare || []);
  kehaMax = r.maxNr || 0;
  kehaPending = [];
  kehaFills = [];
  kehaShareFills = [];
  return r;
}

// LinkedIns opslags-ID indeholder tidspunktet for opslaget (de øverste bits
// er millisekunder siden 1970). Giver datoen som ÅÅÅÅ-MM-DD, eller null.
function postDate(id) {
  const digits = String(id || "").match(/(\d{15,})$/);
  if (!digits) return null;
  try {
    const ms = Number(BigInt(digits[1]) >> 22n);
    const d = new Date(ms);
    if (ms < Date.UTC(2003, 0, 1) || ms > Date.now() + 86400000) return null;
    return d.toLocaleDateString("sv-SE", { timeZone: "Europe/Copenhagen" });
  } catch (_) {
    return null;
  }
}

// Felter for delt indhold (kun når udvidelsen har tjekket opslaget)
function shareFields(p) {
  if (!p.shareChecked) return {};
  return { shareChecked: true, shareTitle: p.shareTitle || null, shareSource: p.shareSource || null, shareUrl: p.shareUrl || null };
}

// Sender nye opslag (ældste først, med Nr), manglende billeder og delt indhold til keha.dk
async function sendKeha() {
  kehaBusy = true;
  const ordered = kehaPending.reverse().map((p) => ({ ...p, nr: ++kehaMax }));
  kehaPending = [];
  const all = ordered.concat(kehaFills.map((p) => ({ ...p, nr: undefined })));
  kehaFills = [];
  kehaLeft = all.length;
  if (all.length) {
    await log(`keha.dk: ${ordered.length} nye opslag og ${all.length - ordered.length} manglende billeder sendes.`);
  }
  await setStatus();
  for (let i = 0; i < all.length; i += 20) {
    const chunk = all.slice(i, i + 20).map((p) => ({
      id: p.id, url: p.url, author: p.author, text: p.text,
      image: p.image || null, date: postDate(p.id), nr: p.nr, ...shareFields(p)
    }));
    try {
      await keha("posts", "POST", { posts: chunk });
      stats.uploaded += chunk.length;
    } catch (e) {
      stats.errors += chunk.length;
      await log("Fejl ved afsendelse til keha.dk: " + e.message);
    }
    kehaLeft = Math.max(0, kehaLeft - chunk.length);
    await setStatus();
  }
  await sendShareFills();
  kehaBusy = false;
  maybeFinish();
}

// Eksisterende opslag får titel, kilde og link på delt indhold (eller markeres som tjekket)
async function sendShareFills() {
  const fills = kehaShareFills.map((p) => ({ id: p.id, ...shareFields(p) }));
  kehaShareFills = [];
  if (!fills.length) return;
  const withShare = fills.filter((f) => f.shareTitle).length;
  await log(`keha.dk: ${fills.length} eksisterende opslag tjekket for delt indhold (${withShare} med artikel eller link).`);
  kehaLeft += fills.length;
  await setStatus();
  for (let i = 0; i < fills.length; i += 100) {
    const chunk = fills.slice(i, i + 100);
    try {
      await keha("posts", "POST", { posts: chunk });
    } catch (e) {
      stats.errors += chunk.length;
      await log("Fejl ved afsendelse af delt indhold til keha.dk: " + e.message);
    }
    kehaLeft = Math.max(0, kehaLeft - chunk.length);
    await setStatus();
  }
}

// ---------- Indsamling ----------

// Nye opslag samles først og sendes, når hele listen er gennemgået,
// så det ældste får det laveste nummer.
function collect(posts) {
  for (const p of posts) {
    if (!kehaKnown.has(p.id)) {
      kehaKnown.add(p.id);
      kehaPending.push(p);
      continue;
    }
    if (p.image && kehaNoImage.has(p.id)) {
      kehaNoImage.delete(p.id);
      kehaFills.push(p);
    }
    if (p.shareChecked && kehaNeedShare.has(p.id)) {
      kehaNeedShare.delete(p.id);
      kehaShareFills.push(p);
    }
  }
  stats.found = kehaPending.length;
  setStatus();
}

async function maybeFinish() {
  if (!running || !scrapeDone || kehaBusy) return;
  await log(`Færdig. Gennemgået: ${stats.seen}, sendt til keha.dk: ${stats.uploaded}, fejl: ${stats.errors}.`);
  if (runMode === "new" && runTabId != null) {
    try {
      await chrome.tabs.remove(runTabId);
    } catch (_) {}
  }
  running = false;
  runMode = null;
  runTabId = null;
  await setStatus();
}

// ---------- Kørsler ----------

// Venter på at fanen er indlæst. LinkedIn bliver af og til ved med at hente
// i baggrunden, så efter 20 sekunder fortsættes der alligevel.
async function waitForLoad(tabId, timeout = 20000) {
  const start = Date.now();
  while (Date.now() - start < timeout) {
    try {
      const t = await chrome.tabs.get(tabId);
      if (t.status === "complete") return;
    } catch (_) {
      throw new Error("LinkedIn-fanen blev lukket.");
    }
    await sleep(500);
  }
}

async function openSavedTab(active) {
  const tab = await chrome.tabs.create({ url: SAVED_URL, active });
  await waitForLoad(tab.id);
  await sleep(3000);
  const t = await chrome.tabs.get(tab.id);
  if (!t.url || !t.url.includes("/my-items/saved-posts")) {
    throw new Error("LinkedIn sendte dig videre. Er du logget ind på LinkedIn i denne Chrome?");
  }
  await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ["hook.js"], world: "MAIN" });
  await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ["content.js"] });
  return tab.id;
}

async function startRun(mode) {
  if (running) {
    await log("En kørsel er allerede i gang.");
    return;
  }
  if (!configured(await settings())) {
    await log("keha.dk er ikke sat op. Udfyld brugernavn og applikationsadgangskode i indstillingerne.");
    return;
  }
  running = true;
  scrapeDone = false;
  runMode = mode;
  stats = newStats();
  await setStatus();
  try {
    await log(mode === "full" ? "Starter fuld hentning af alle gemte opslag." : "Tjekker for nye gemte opslag.");
    await loadKehaKnown();
    await log(`${kehaKnown.size} opslag ligger allerede på keha.dk (højeste Nr: ${kehaMax}).`);
    const needShare = [...kehaNeedShare];
    if (needShare.length) {
      await log(`${needShare.length} opslag er ikke tjekket for delt indhold endnu. En fuld hentning udfylder dem.`);
    }
    // Fuld hentning åbnes i forgrunden, fordi Chrome bremser skjulte faner kraftigt.
    runTabId = await openSavedTab(mode === "full");
    await chrome.tabs.sendMessage(runTabId, { cmd: "scrape", mode, known: [...kehaKnown], needShare });
  } catch (e) {
    await log("Stoppet: " + e.message);
    running = false;
    runMode = null;
    await setStatus();
  }
}

async function stopRun() {
  if (runTabId != null) {
    try {
      await chrome.tabs.sendMessage(runTabId, { cmd: "stop" });
    } catch (_) {}
  }
  if (kehaPending.length) {
    await log(`${kehaPending.length} indsamlede opslag blev ikke sendt, så numrene ikke kommer i forkert rækkefølge.`);
  }
  kehaPending = [];
  kehaFills = [];
  kehaShareFills = [];
  scrapeDone = true;
  await log("Stoppet af dig.");
  maybeFinish();
}

async function diagnose() {
  const tabId = await openSavedTab(true);
  const result = await chrome.tabs.sendMessage(tabId, { cmd: "diagnose" });
  await chrome.storage.local.set({ diagnose: JSON.stringify(result, null, 2) });
  await log("Diagnose færdig. Se resultatet i indstillingerne.");
  return result;
}

async function captureCalls() {
  const tabId = await openSavedTab(true);
  await chrome.scripting.executeScript({ target: { tabId }, files: ["hook.js"], world: "MAIN" });
  await sleep(500);
  const result = await chrome.tabs.sendMessage(tabId, { cmd: "capture" });
  await chrome.storage.local.set({ diagnose: JSON.stringify(result, null, 2) });
  await log(
    result?.fandtGemteOpslagKald
      ? "Datakald for gemte opslag opsnappet. Kopiér resultatet og send det til Claude."
      : "Fandt ikke et datakald for gemte opslag. Kopiér resultatet og send det til Claude."
  );
  return result;
}

// ---------- Beskeder ----------

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  (async () => {
    try {
      switch (msg.cmd || msg.type) {
        case "run":
          startRun(msg.mode);
          sendResponse({ ok: true });
          break;
        case "stop":
          await stopRun();
          sendResponse({ ok: true });
          break;
        case "diagnose":
          sendResponse({ ok: true, result: await diagnose() });
          break;
        case "capture":
          sendResponse({ ok: true, result: await captureCalls() });
          break;
        case "kehaSetup": {
          await chrome.storage.local.set({
            kehaUrl: (msg.url || "https://keha.dk").trim(),
            kehaUser: (msg.user || "").trim(),
            kehaPass: (msg.pass || "").trim()
          });
          const r = await keha("ids");
          await log(`Forbindelsen til keha.dk virker (plugin ${r.version}, ${(r.ids || []).length} opslag).`);
          sendResponse({ ok: true, count: (r.ids || []).length, version: r.version });
          break;
        }
        case "setAuto":
          await chrome.storage.local.set({ autoDaily: !!msg.on });
          await ensureAlarm();
          sendResponse({ ok: true });
          break;
        case "batch":
          if (!running) {
            // Baggrundsdelen kan være genstartet af Chrome midt i en kørsel
            running = true;
            runMode = runMode || "full";
            runTabId = sender.tab?.id ?? runTabId;
            await loadKehaKnown();
          }
          collect(msg.posts || []);
          sendResponse({ ok: true });
          break;
        case "log":
          await log(msg.msg);
          sendResponse({ ok: true });
          break;
        case "progress":
          stats.seen = msg.seen;
          setStatus();
          sendResponse({ ok: true });
          break;
        case "done":
          await log(msg.reason || "LinkedIn-gennemgang færdig.");
          scrapeDone = true;
          sendKeha();
          sendResponse({ ok: true });
          break;
        default:
          sendResponse({ ok: false });
      }
    } catch (e) {
      await log("Fejl: " + e.message);
      sendResponse({ ok: false, error: e.message });
    }
  })();
  return true;
});

// ---------- Daglig kørsel ----------

async function ensureAlarm() {
  const { autoDaily } = await settings();
  await chrome.alarms.clear("daily");
  if (autoDaily) {
    chrome.alarms.create("daily", { delayInMinutes: 10, periodInMinutes: 24 * 60 });
  }
}

chrome.alarms.onAlarm.addListener((a) => {
  if (a.name === "daily") startRun("new");
});

chrome.runtime.onInstalled.addListener(async () => {
  // Notion bruges ikke længere: fjern den gemte Notion-nøgle og de gamle indstillinger
  await chrome.storage.local.remove(["token", "pageId", "databaseId", "notionEnabled", "kehaEnabled"]);
  await ensureAlarm();
});
chrome.runtime.onStartup.addListener(ensureAlarm);

chrome.action.onClicked.addListener(() => chrome.runtime.openOptionsPage());
