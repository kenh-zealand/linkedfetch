// LinkedIn til Notion – gemte opslag
// Copyright (c) 2026 Kenneth Hansen
//
// Baggrundsdelen: taler med Notion, åbner LinkedIn-fanen og styrer kørslerne.

const NOTION_VERSION = "2022-06-28";
const SAVED_URL = "https://www.linkedin.com/my-items/saved-posts/";
const DB_TITLE = "LinkedIn – gemte opslag";

let running = false;
let scrapeDone = false;
let runMode = null;
let runTabId = null;
let queue = [];
let pending = []; // nye opslag fra LinkedIn, nyeste først, venter på nummerering
let processing = false;
let known = new Set();
let maxNr = 0;
let stats = newStats();

function newStats() {
  return { seen: 0, found: 0, uploaded: 0, skipped: 0, errors: 0 };
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
      queued: queue.length + kehaLeft,
      found: stats.found,
      updated: Date.now()
    }
  });
}

// ---------- Notion ----------

async function settings() {
  return chrome.storage.local.get([
    "token", "pageId", "databaseId", "autoDaily",
    "notionEnabled", "kehaEnabled", "kehaUrl", "kehaUser", "kehaPass"
  ]);
}

// ---------- keha.dk (WordPress-pluginet LinkedFetch) ----------

let runNotion = false;
let runKeha = false;
let kehaKnown = new Set();
let kehaNoImage = new Set();
let kehaNeedShare = new Set(); // opslag på keha.dk, hvor delt indhold ikke er tjekket
let kehaShareFills = []; // eksisterende opslag, der får titel, kilde og link på delt indhold
let kehaMax = 0;
let kehaPending = []; // nye opslag til keha.dk, nyeste først
let kehaFills = []; // eksisterende opslag på keha.dk, der mangler billede
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

// Sender nye opslag (ældste først, med Nr) og manglende billeder til keha.dk i bundter
// Felter for delt indhold (kun når udvidelsen har tjekket opslaget)
function shareFields(p) {
  if (!p.shareChecked) return {};
  return { shareChecked: true, shareTitle: p.shareTitle || null, shareSource: p.shareSource || null, shareUrl: p.shareUrl || null };
}

async function sendKeha() {
  kehaBusy = true;
  // Egne kopier, så Notions nummerering ikke blandes sammen med keha.dk's
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

// Notion tillader i gennemsnit omkring 3 kald i sekundet. Alle kald går
// gennem denne "port", som holder mindst 340 ms mellem hvert kald.
const NOTION_GAP_MS = 340;
let nextSlot = 0;
async function rateGate() {
  const now = Date.now();
  const slot = Math.max(now, nextSlot);
  nextSlot = slot + NOTION_GAP_MS;
  if (slot > now) await sleep(slot - now);
}

// Filupload bruger den nyere API-version, som Notions dokumentation viser for upload.
const NOTION_VERSION_FILES = "2026-03-11";

async function notion(path, method = "GET", body, version = NOTION_VERSION) {
  const { token } = await settings();
  const isForm = typeof FormData !== "undefined" && body instanceof FormData;
  for (let attempt = 0; attempt < 6; attempt++) {
    let res;
    await rateGate();
    try {
      const headers = { Authorization: "Bearer " + token, "Notion-Version": version };
      if (!isForm) headers["Content-Type"] = "application/json";
      res = await fetch("https://api.notion.com/v1/" + path, {
        method,
        headers,
        body: body ? (isForm ? body : JSON.stringify(body)) : undefined
      });
    } catch (e) {
      await sleep(2000 * (attempt + 1));
      continue;
    }
    if (res.status === 429 || res.status >= 500) {
      const wait = (Number(res.headers.get("Retry-After")) || 2 * (attempt + 1)) * 1000;
      await sleep(wait);
      continue;
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(`Notion svarede ${res.status}: ${data.message || res.statusText}`);
    }
    return data;
  }
  throw new Error("Notion svarer ikke efter flere forsøg.");
}

function pageIdFromUrl(input) {
  const s = (input || "").trim().split("?")[0].replace(/-/g, "");
  const m = s.match(/([0-9a-f]{32})$/i);
  if (!m) return null;
  const h = m[1].toLowerCase();
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

async function setup(token, pageUrl) {
  const pageId = pageIdFromUrl(pageUrl);
  if (!token) throw new Error("Notion-nøglen mangler.");
  if (!pageId) throw new Error("Kunne ikke finde side-ID i linket. Kopiér linket til Notion-siden igen.");

  const prev = await settings();
  await chrome.storage.local.set({ token: token.trim(), pageId });

  // Tjek at nøglen har adgang til siden
  try {
    await notion(`pages/${pageId}`);
  } catch (e) {
    throw new Error(
      "Ingen adgang til Notion-siden. Har du tilføjet din integration til siden (Forbindelser)? Detaljer: " + e.message
    );
  }

  // Genbrug eksisterende database, hvis den stadig findes under samme side
  if (prev.databaseId && prev.pageId === pageId) {
    try {
      const old = await notion(`databases/${prev.databaseId}`);
      // En slettet database kan stadig hentes, men ikke bruges
      if (!old.archived && !old.in_trash) {
        await notion(`databases/${prev.databaseId}/query`, "POST", { page_size: 1 });
        return { databaseId: prev.databaseId, created: false };
      }
    } catch (_) {
      /* opret en ny */
    }
  }

  const db = await notion("databases", "POST", {
    parent: { type: "page_id", page_id: pageId },
    title: [{ type: "text", text: { content: DB_TITLE } }],
    properties: {
      Titel: { title: {} },
      Nr: { number: {} },
      Forfatter: { rich_text: {} },
      Link: { url: {} },
      Dato: { date: {} },
      Billede: { files: {} },
      "Post-ID": { rich_text: {} },
      Hentet: { date: {} }
    }
  });
  await chrome.storage.local.set({ databaseId: db.id });
  return { databaseId: db.id, created: true };
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

// Tilføjer kolonner, der mangler i en database fra en ældre version
async function ensureColumns() {
  const { databaseId } = await settings();
  const db = await notion(`databases/${databaseId}`);
  const add = {};
  if (!db.properties?.Nr) add.Nr = { number: {} };
  if (!db.properties?.Dato) add.Dato = { date: {} };
  if (!db.properties?.Billede) add.Billede = { files: {} };
  if (Object.keys(add).length) {
    await notion(`databases/${databaseId}`, "PATCH", { properties: add });
    await log(`Kolonne tilføjet i Notion: ${Object.keys(add).join(", ")}.`);
  }
}

let missingDates = []; // eksisterende rækker uden dato: { pageId, start }
let noImage = new Map(); // Post-ID -> pageId for eksisterende rækker uden billede
let imageFills = []; // billeder, der skal sættes på eksisterende rækker

async function loadKnown() {
  const { databaseId } = await settings();
  known = new Set();
  missingDates = [];
  noImage = new Map();
  imageFills = [];
  maxNr = 0;
  let cursor = null;
  do {
    const body = { page_size: 100 };
    if (cursor) body.start_cursor = cursor;
    const r = await notion(`databases/${databaseId}/query`, "POST", body);
    for (const p of r.results || []) {
      const t = p.properties?.["Post-ID"]?.rich_text?.[0]?.plain_text;
      if (t) known.add(t.trim());
      const n = p.properties?.Nr?.number;
      if (typeof n === "number" && n > maxNr) maxNr = n;
      if (t && p.properties?.Billede && !(p.properties.Billede.files || []).length) {
        noImage.set(t.trim(), p.id);
      }
      if (t && p.properties?.Dato && !p.properties.Dato.date) {
        const start = postDate(t.trim());
        if (start) missingDates.push({ pageId: p.id, start });
      }
    }
    cursor = r.has_more ? r.next_cursor : null;
  } while (cursor);
}

function splitText(text, size = 1900) {
  const out = [];
  let rest = text;
  while (rest.length > size) {
    let cut = rest.lastIndexOf(" ", size);
    if (cut < size * 0.5) cut = size;
    out.push(rest.slice(0, cut));
    rest = rest.slice(cut).trimStart();
  }
  if (rest) out.push(rest);
  return out;
}

function paragraph(content) {
  return {
    object: "block",
    type: "paragraph",
    paragraph: { rich_text: [{ type: "text", text: { content } }] }
  };
}

// ---------- Billeder ----------

// Henter billedet fra LinkedIn og uploader det til Notion. Giver Notions fil-id.
async function uploadImage(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`billedet kunne ikke hentes (${res.status})`);
  const blob = await res.blob();
  if (!blob.size || blob.size > 20 * 1024 * 1024) return null;
  const type = blob.type || "image/jpeg";
  const ext = { "image/png": "png", "image/gif": "gif", "image/webp": "webp" }[type] || "jpg";
  const filename = `billede.${ext}`;
  const up = await notion("file_uploads", "POST", { filename, content_type: type }, NOTION_VERSION_FILES);
  const form = new FormData();
  form.append("file", blob, filename);
  await notion(`file_uploads/${up.id}/send`, "POST", form, NOTION_VERSION_FILES);
  return { id: up.id, name: filename };
}

function imageProps(file) {
  return {
    cover: { type: "file_upload", file_upload: { id: file.id } },
    properties: {
      Billede: { files: [{ type: "file_upload", file_upload: { id: file.id }, name: file.name }] }
    }
  };
}

// Sætter billedet på en eksisterende side
async function attachImage(pageId, file) {
  await notion(`pages/${pageId}`, "PATCH", imageProps(file), NOTION_VERSION_FILES);
}

// Forsøger først at sætte billedet med det samme, når siden oprettes.
// Afviser Notion det, sættes det i stedet bagefter.
let inlineImages = true;

async function uploadPost(p) {
  const { databaseId } = await settings();
  let file = null;
  if (p.image) {
    try {
      file = await uploadImage(p.image);
    } catch (e) {
      await log(`Billede til nr. ${p.nr} blev ikke gemt: ${e.message}`);
    }
  }
  const text = (p.text || "").trim();
  const oneLine = text.replace(/\s+/g, " ");
  const title =
    (oneLine.length > 90 ? oneLine.slice(0, 87) + "..." : oneLine) ||
    (p.author ? `Opslag af ${p.author}` : "LinkedIn-opslag");

  const children = [];
  for (const para of text.split(/\n{2,}/)) {
    for (const chunk of splitText(para.trim())) {
      if (chunk) children.push(paragraph(chunk));
    }
  }
  children.splice(98);
  children.push({ object: "block", type: "bookmark", bookmark: { url: p.url } });

  const page = {
    parent: { database_id: databaseId },
    properties: {
      Titel: { title: [{ type: "text", text: { content: title } }] },
      Nr: { number: p.nr ?? null },
      Forfatter: {
        rich_text: p.author ? [{ type: "text", text: { content: p.author.slice(0, 200) } }] : []
      },
      Link: { url: p.url },
      Dato: { date: postDate(p.id) ? { start: postDate(p.id) } : null },
      "Post-ID": { rich_text: [{ type: "text", text: { content: p.id } }] },
      Hentet: { date: { start: new Date().toISOString().slice(0, 10) } }
    },
    children
  };

  if (!file) {
    await notion("pages", "POST", page);
    return;
  }
  if (inlineImages) {
    const img = imageProps(file);
    try {
      await notion("pages", "POST", { ...page, cover: img.cover, properties: { ...page.properties, ...img.properties } });
      return;
    } catch (e) {
      if (!/\b400\b/.test(e.message)) throw e;
      inlineImages = false;
      await log("Billeder sættes på i et ekstra trin (Notion afviste dem ved oprettelsen).");
    }
  }
  const created = await notion("pages", "POST", page);
  await attachImage(created.id, file);
}

// ---------- Kø ----------

// Nye opslag samles først; de nummereres og sendes til Notion,
// når hele listen er gennemgået, så det ældste får det laveste nummer.
function collect(posts) {
  if (runKeha) {
    for (const p of posts) {
      if (!kehaKnown.has(p.id)) {
        kehaKnown.add(p.id);
        kehaPending.push(p);
      } else {
        if (p.image && kehaNoImage.has(p.id)) {
          kehaNoImage.delete(p.id);
          kehaFills.push(p);
        }
        if (p.shareChecked && kehaNeedShare.has(p.id)) {
          kehaNeedShare.delete(p.id);
          kehaShareFills.push(p);
        }
      }
    }
  }
  if (runNotion) {
    for (const p of posts) {
      if (known.has(p.id)) {
        stats.skipped++;
        // Eksisterende række uden billede får billedet, hvis LinkedIn har et
        if (p.image && noImage.has(p.id)) {
          imageFills.push({ kind: "image", pageId: noImage.get(p.id), image: p.image });
          noImage.delete(p.id);
        }
        continue;
      }
      known.add(p.id);
      pending.push(p);
    }
  }
  stats.found = Math.max(pending.length, kehaPending.length);
  setStatus();
}

function releasePending() {
  if (runKeha) sendKeha();
  if (!runNotion) {
    setStatus();
    maybeFinish();
    return;
  }
  const ordered = pending.reverse(); // ældste først
  pending = [];
  for (const p of ordered) {
    p.nr = ++maxNr;
    queue.push(p);
  }
  // Rækker fra tidligere kørsler, der mangler dato, får den udfyldt bagefter
  if (missingDates.length) {
    log(`${missingDates.length} eksisterende rækker får udfyldt dato.`);
    for (const m of missingDates) queue.push({ kind: "date", ...m });
    missingDates = [];
  }
  if (imageFills.length) {
    log(`${imageFills.length} eksisterende rækker får billede.`);
    queue.push(...imageFills);
    imageFills = [];
  }
  setStatus();
  pump();
}

async function setDate(item) {
  await notion(`pages/${item.pageId}`, "PATCH", { properties: { Dato: { date: { start: item.start } } } });
}

// Tre opslag sendes ad gangen; rateGate sørger for, at Notions grænse holdes.
const NOTION_WORKERS = 3;

async function pump() {
  if (processing) return;
  processing = true;
  const worker = async () => {
    while (queue.length) {
      const p = queue.shift();
      try {
        if (p.kind === "date") await setDate(p);
        else if (p.kind === "image") {
          const file = await uploadImage(p.image);
          if (file) await attachImage(p.pageId, file);
        } else await uploadPost(p);
        stats.uploaded++;
      } catch (e) {
        stats.errors++;
        if (p.kind === "date" || p.kind === "image") {
          await log(`Fejl ved udfyldning af ${p.kind === "date" ? "dato" : "billede"}: ${e.message}`);
        } else {
          known.delete(p.id);
          await log(`Fejl ved nr. ${p.nr} (${p.url}): ${e.message}`);
        }
      }
      setStatus();
    }
  };
  await Promise.all(Array.from({ length: NOTION_WORKERS }, worker));
  await setStatus();
  processing = false;
  maybeFinish();
}

// Sætter tidspunktet for seneste opdatering i databasens overskrift
async function stampDatabaseTitle() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, "0");
  const when = `${d.getDate()}/${d.getMonth() + 1}-${d.getFullYear()} kl. ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const { databaseId } = await settings();
  try {
    await notion(`databases/${databaseId}`, "PATCH", {
      title: [{ type: "text", text: { content: `${DB_TITLE} opdateret ${when}` } }]
    });
  } catch (e) {
    await log("Kunne ikke opdatere databasens overskrift: " + e.message);
  }
}

async function maybeFinish() {
  if (!running || !scrapeDone || queue.length || processing || kehaBusy) return;
  if (runNotion) await stampDatabaseTitle();
  const where = [runNotion && "Notion", runKeha && "keha.dk"].filter(Boolean).join(" og ");
  await log(
    `Færdig. Gennemgået: ${stats.seen}, sendt eller opdateret (${where}): ${stats.uploaded}, fejl: ${stats.errors}.`
  );
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
  const s = await settings();
  runNotion = s.notionEnabled !== false && !!(s.token && s.databaseId);
  runKeha = !!(s.kehaEnabled && s.kehaUser && s.kehaPass);
  if (!runNotion && !runKeha) {
    await log("Ingen destination er sat op. Udfyld Notion eller keha.dk i indstillingerne.");
    return;
  }
  running = true;
  scrapeDone = false;
  runMode = mode;
  queue = [];
  pending = [];
  known = new Set();
  stats = newStats();
  await setStatus();
  try {
    await log(mode === "full" ? "Starter fuld hentning af alle gemte opslag." : "Tjekker for nye gemte opslag.");
    if (runNotion) {
      await ensureColumns();
      await loadKnown();
      await log(`${known.size} opslag ligger allerede i Notion (højeste Nr: ${maxNr}).`);
    }
    if (runKeha) {
      await loadKehaKnown();
      await log(`${kehaKnown.size} opslag ligger allerede på keha.dk (højeste Nr: ${kehaMax}).`);
    }
    // Et opslag regnes kun som kendt, hvis det findes alle de steder, der sendes til
    const knownAll = runNotion && runKeha
      ? [...known].filter((id) => kehaKnown.has(id))
      : runNotion ? [...known] : [...kehaKnown];
    // Fuld hentning åbnes i forgrunden, fordi Chrome bremser skjulte faner kraftigt.
    runTabId = await openSavedTab(mode === "full");
    // Opslag, hvor keha.dk mangler delt indhold, får slået linket op under gennemgangen
    const needShare = runKeha ? [...kehaNeedShare] : [];
    if (runKeha && needShare.length) {
      await log(`${needShare.length} opslag på keha.dk er ikke tjekket for delt indhold endnu. En fuld hentning udfylder dem.`);
    }
    await chrome.tabs.sendMessage(runTabId, { cmd: "scrape", mode, known: knownAll, needShare });
  } catch (e) {
    if (/Could not find database/i.test(e.message)) {
      await chrome.storage.local.remove("databaseId");
      await log('Stoppet: Databasen findes ikke længere i Notion. Klik "Gem og opret database" igen, så laves en ny.');
    } else {
      await log("Stoppet: " + e.message);
    }
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
  queue = [];
  if (pending.length) {
    await log(`${pending.length} indsamlede opslag blev ikke sendt, så numrene ikke kommer i forkert rækkefølge.`);
  }
  pending = [];
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
        case "setup": {
          const r = await setup(msg.token, msg.pageUrl);
          await log(r.created ? "Database oprettet i Notion." : "Forbindelsen til Notion virker.");
          sendResponse({ ok: true, ...r });
          break;
        }
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
            kehaEnabled: !!msg.enabled,
            kehaUrl: (msg.url || "https://keha.dk").trim(),
            kehaUser: (msg.user || "").trim(),
            kehaPass: (msg.pass || "").trim()
          });
          if (!msg.enabled) {
            sendResponse({ ok: true, disabled: true });
            break;
          }
          const r = await keha("ids");
          await log(`Forbindelsen til keha.dk virker (plugin ${r.version}, ${(r.ids || []).length} opslag).`);
          sendResponse({ ok: true, count: (r.ids || []).length, version: r.version });
          break;
        }
        case "setNotion":
          await chrome.storage.local.set({ notionEnabled: !!msg.on });
          sendResponse({ ok: true });
          break;
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
            const s = await settings();
            runNotion = s.notionEnabled !== false && !!(s.token && s.databaseId);
            runKeha = !!(s.kehaEnabled && s.kehaUser && s.kehaPass);
            if (runNotion) await loadKnown();
            if (runKeha) await loadKehaKnown();
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
          if (pending.length) await log(`${pending.length} nye opslag sendes nu til Notion.`);
          scrapeDone = true;
          releasePending();
          maybeFinish();
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

chrome.runtime.onInstalled.addListener(ensureAlarm);
chrome.runtime.onStartup.addListener(ensureAlarm);

chrome.action.onClicked.addListener(() => chrome.runtime.openOptionsPage());
