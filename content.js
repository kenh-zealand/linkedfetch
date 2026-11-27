// LinkedIn til Notion – gemte opslag
// Copyright (c) 2026 Kenneth Hansen
//
// Kører på LinkedIns side "Gemte indlæg". Læser opslagene, der vises,
// og henter flere ved at trykke "Vis flere resultater" eller scrolle.

(() => {
  if (window.__liNotionSaver) return;
  window.__liNotionSaver = true;

  const ID_RE = /urn:li:(?:activity|share|ugcPost):\d+/;
  const MORE_TEXT_RE = /^(…|\.\.\.)?\s*(mere|vis mere|se mere|more|see more|show more)$/i;
  let stop = false;

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const clean = (s) =>
    (s || "")
      .replace(/ /g, " ")
      .replace(/[ \t]+\n/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .trim();

  function postLinks() {
    return [...document.querySelectorAll('a[href*="/feed/update/"]')].filter((a) =>
      ID_RE.test(decodeURIComponent(a.getAttribute("href") || ""))
    );
  }

  function containerFor(a) {
    return (
      a.closest("li") ||
      a.closest("[data-urn]") ||
      a.closest("[data-chameleon-result-urn]") ||
      a.parentElement?.parentElement?.parentElement ||
      a
    );
  }

  function firstLine(el) {
    return (
      clean(el?.innerText)
        .split("\n")
        .map((s) => s.trim())
        .filter(Boolean)[0] || ""
    );
  }

  function expand(c) {
    c.querySelectorAll("button").forEach((b) => {
      const t = (b.innerText || "").trim();
      if (t.length < 25 && MORE_TEXT_RE.test(t)) {
        try {
          b.click();
        } catch (_) {}
      }
    });
  }

  function extract(c, id) {
    const authorLink = [...c.querySelectorAll('a[href*="/in/"], a[href*="/company/"]')].find((a) =>
      firstLine(a)
    );
    const author = authorLink ? firstLine(authorLink) : "";

    // Opslagsteksten er typisk det længste tekststykke, der ikke rummer forfatterlinket
    let best = "";
    for (const el of c.querySelectorAll("p, span, div")) {
      if (authorLink && el.contains(authorLink)) continue;
      const t = clean(el.innerText);
      if (t.length > best.length) best = t;
    }
    if (!best) best = clean(c.innerText);
    best = best.replace(/\s*(…|\.\.\.)\s*(mere|vis mere|se mere|more|see more)\s*$/i, "").trim();

    return {
      id,
      url: `https://www.linkedin.com/feed/update/${id}/`,
      author,
      text: best
    };
  }

  function collectNew(seen) {
    const fresh = [];
    for (const a of postLinks()) {
      const m = decodeURIComponent(a.getAttribute("href") || "").match(ID_RE);
      if (!m || seen.has(m[0])) continue;
      seen.add(m[0]);
      fresh.push({ id: m[0], c: containerFor(a) });
    }
    return fresh;
  }

  function moreButton() {
    return [...document.querySelectorAll("button")].find((b) =>
      /flere resultater|more results/i.test(b.innerText || "")
    );
  }

  function send(msg) {
    try {
      chrome.runtime.sendMessage(msg);
    } catch (_) {}
  }

  async function scrape(mode, knownArr) {
    const known = new Set(knownArr || []);
    const seen = new Set();
    let idleRounds = 0;
    let knownStreak = 0;
    let total = 0;

    while (!stop) {
      const fresh = collectNew(seen);
      fresh.forEach((f) => expand(f.c));
      if (fresh.length) await sleep(700);

      const newPosts = [];
      for (const f of fresh) {
        if (known.has(f.id)) {
          knownStreak++;
        } else {
          knownStreak = 0;
          newPosts.push(extract(f.c, f.id));
        }
      }
      total += fresh.length;
      if (newPosts.length) send({ type: "batch", posts: newPosts });
      send({ type: "progress", seen: total });

      if (mode === "new" && knownStreak >= 15) {
        return send({ type: "done", reason: `Nåede til opslag, der allerede er i Notion (${total} gennemgået).` });
      }
      idleRounds = fresh.length ? 0 : idleRounds + 1;
      if (idleRounds >= 5) {
        return send({ type: "done", reason: `Ikke flere opslag at hente (${total} gennemgået på LinkedIn).` });
      }

      const more = moreButton();
      if (more && !more.disabled) {
        more.click();
      } else {
        const last = postLinks().at(-1);
        if (last) containerFor(last).scrollIntoView({ block: "end" });
        window.scrollTo(0, document.body.scrollHeight);
      }
      await sleep(2000 + Math.random() * 1500);
    }
    send({ type: "done", reason: "Stoppet." });
  }

  function diagnose() {
    const links = postLinks();
    const seen = new Set();
    const samples = [];
    for (const a of links) {
      const m = decodeURIComponent(a.getAttribute("href") || "").match(ID_RE);
      if (!m || seen.has(m[0])) continue;
      seen.add(m[0]);
      if (samples.length < 3) {
        const p = extract(containerFor(a), m[0]);
        samples.push({ ...p, text: p.text.slice(0, 300) });
      }
    }
    return {
      side: location.href,
      opslagslinks: links.length,
      unikkeOpslag: seen.size,
      knapFlereResultater: !!moreButton(),
      eksempler: samples
    };
  }

  // ---------- Opsnapning af LinkedIns datakald ----------

  const captured = [];
  window.addEventListener("message", (e) => {
    if (e.source === window && e.data && e.data.__liNotionCapture) captured.push(e.data.rec);
  });

  // Kun ufarlige header-værdier tages med; resten vises kun som navne
  const SAFE_HEADERS = ["accept", "x-restli-protocol-version", "x-li-lang", "content-type"];

  function describe(rec) {
    const headers = {};
    for (const [k, v] of Object.entries(rec.headers || {})) {
      headers[k] = SAFE_HEADERS.includes(k.toLowerCase()) ? v : "(skjult)";
    }
    const body = rec.body || "";
    let udsnit;
    try {
      udsnit = prune(JSON.parse(body), 0);
    } catch (_) {
      udsnit = body.slice(0, 20000);
    }
    return { url: rec.url, headers, svarLaengde: body.length, udsnit };
  }

  // Forkorter svaret: lange tekster afkortes, lister viser få eksempler,
  // men alle typer ($type) i "included" er med, så strukturen kan ses.
  function prune(v, depth) {
    if (typeof v === "string") return v.length > 250 ? v.slice(0, 250) + "…" : v;
    if (v === null || typeof v !== "object") return v;
    if (depth > 9) return "…";
    if (Array.isArray(v)) {
      const out = v.slice(0, 3).map((x) => prune(x, depth + 1));
      if (v.length > 3) out.push(`… (${v.length} i alt)`);
      return out;
    }
    const out = {};
    for (const [k, val] of Object.entries(v)) {
      if (k === "included" && Array.isArray(val)) {
        const byType = {};
        for (const item of val) {
          const t = item?.$type || "ukendt";
          byType[t] = byType[t] || { antal: 0, eksempler: [] };
          byType[t].antal++;
          if (byType[t].eksempler.length < 2) byType[t].eksempler.push(prune(item, depth + 1));
        }
        out.included = byType;
      } else {
        out[k] = prune(val, depth + 1);
      }
    }
    return out;
  }

  async function capture() {
    captured.length = 0;
    const more = moreButton();
    if (more && !more.disabled) {
      more.click();
    } else {
      const last = postLinks().at(-1);
      if (last) containerFor(last).scrollIntoView({ block: "end" });
      window.scrollTo(0, document.body.scrollHeight);
    }
    for (let i = 0; i < 20 && !captured.some((r) => /saved/i.test(r.url)); i++) await sleep(500);
    await sleep(1000);

    const saved = captured.filter((r) => /saved/i.test(r.url));
    const chosen = saved.length ? saved : captured.slice(-3);
    return {
      side: location.href,
      knapFlereResultater: !!more,
      antalDatakald: captured.length,
      fandtGemteOpslagKald: saved.length > 0,
      alleUrler: captured.map((r) => r.url.slice(0, 300)),
      datakald: chosen.slice(0, 2).map(describe)
    };
  }

  // ---------- Hurtig metode: LinkedIns eget datakald ----------
  // Bygger på det opsnappede kald: 10 opslag pr. side, og svaret
  // indeholder en paginationToken til næste side.

  const FALLBACK_QUERY_ID = "voyagerSearchDashClusters.e438ab99259203e9c1cd3f358e217282";

  function cookieCsrf() {
    const m = document.cookie.match(/JSESSIONID="?([^";]+)"?/);
    return m ? m[1] : null;
  }

  async function learnApi() {
    captured.length = 0;
    const more = moreButton();
    if (more && !more.disabled) more.click();
    else window.scrollTo(0, document.body.scrollHeight);
    for (let i = 0; i < 20 && !captured.some((r) => /SAVED_POSTS/.test(r.url)); i++) await sleep(500);
    const rec = captured.find((r) => /SAVED_POSTS/.test(r.url));
    const headers = rec?.headers || {};
    const csrf = headers["csrf-token"] || cookieCsrf();
    if (!csrf) throw new Error("fandt ikke LinkedIns sikkerhedsnøgle");
    return {
      queryId: rec?.url.match(/queryId=([^&]+)/)?.[1] || FALLBACK_QUERY_ID,
      csrf,
      lang: headers["x-li-lang"] || "da_DK"
    };
  }

  async function fetchPage(api, start, token, count = api.pageSize) {
    const tok = token ? `,paginationToken:${encodeURIComponent(token)}` : "";
    const cnt = count ? `,count:${count}` : "";
    const vars = `(start:${start}${cnt}${tok},query:(flagshipSearchIntent:SEARCH_MY_ITEMS_SAVED_POSTS))`;
    const res = await fetch(`/voyager/api/graphql?variables=${vars}&queryId=${api.queryId}`, {
      credentials: "include",
      headers: {
        "csrf-token": api.csrf,
        accept: "application/vnd.linkedin.normalized+json+2.1",
        "x-restli-protocol-version": "2.0.0",
        "x-li-lang": api.lang
      }
    });
    if (!res.ok) throw new Error(`LinkedIn svarede ${res.status}`);
    return res.json();
  }

  function parsePage(json) {
    const included = json.included || [];
    const byUrn = new Map(included.map((i) => [i.entityUrn, i]));
    const coll = json.data?.data?.searchDashClustersByAll;
    const order = [];
    for (const el of coll?.elements || []) {
      for (const it of el.items || []) {
        const u = it.item?.["*entityResult"];
        if (u) order.push(u);
      }
    }
    const entities = order.length
      ? order.map((u) => byUrn.get(u)).filter(Boolean)
      : included.filter((i) => i.$type === "com.linkedin.voyager.dash.search.EntityResultViewModel");

    // Billedlink: vælg en udgave på mindst 480 px bredde (lavformat), ellers den største
    function imageUrl(e) {
      for (const a of e.entityEmbeddedObject?.image?.attributes || []) {
        const d = a?.detailData || {};
        const v = d.vectorImage;
        if (v?.artifacts?.length) {
          const arts = [...v.artifacts].sort((x, y) => (x.width || 0) - (y.width || 0));
          const pick = arts.find((x) => (x.width || 0) >= 480) || arts[arts.length - 1];
          const seg = pick.fileIdentifyingUrlPathSegment || "";
          return seg.startsWith("http") ? seg : (v.rootUrl || "") + seg;
        }
        const direct = typeof d.imageUrl === "string" ? d.imageUrl : d.imageUrl?.url;
        if (direct) return direct;
      }
      return null;
    }

    const posts = [];
    for (const e of entities) {
      const id = (e.trackingUrn || "").match(ID_RE)?.[0] || (e.entityUrn || "").match(ID_RE)?.[0];
      if (!id) continue;
      posts.push({
        id,
        url: `https://www.linkedin.com/feed/update/${id}/`,
        author: e.title?.text || "",
        text: (e.summary?.text || "").trim(),
        image: imageUrl(e)
      });
    }
    return { posts, count: order.length || entities.length, token: coll?.metadata?.paginationToken || null };
  }

  async function fastScrape(mode, known) {
    const api = await learnApi();

    // Forsøg med 50 opslag pr. side. Accepterer LinkedIn det ikke,
    // bruges standarden på 10.
    api.pageSize = null;
    try {
      const probe = parsePage(await fetchPage(api, 0, null, 50));
      if (probe.posts.length > 10) api.pageSize = 50;
    } catch (_) {}
    send({
      type: "log",
      msg: `Henter via LinkedIns datakald (hurtig metode, ${api.pageSize || 10} opslag pr. side).`
    });
    const seen = new Set();
    let start = 0, token = null, total = 0, knownStreak = 0, emptyPages = 0;

    while (!stop) {
      // Hent siden; hvis den ser ud som slutningen, prøves igen op til 3 gange,
      // da LinkedIn af og til svarer ufuldstændigt.
      let page, fresh;
      for (let attempt = 0; attempt < 4; attempt++) {
        page = parsePage(await fetchPage(api, start, token));
        fresh = page.posts.filter((p) => !seen.has(p.id));
        if (fresh.length && page.token) break;
        if (attempt < 3) {
          send({
            type: "log",
            msg: `Side ved nr. ${start}: ${page.posts.length} opslag, ${fresh.length} nye, sidemarkør: ${page.token ? "ja" : "nej"}. Prøver igen…`
          });
          await sleep(3000 * (attempt + 1));
        }
      }
      const { posts, count, token: next } = page;
      if (start === 0 && !posts.length) throw new Error("første side var tom");
      fresh.forEach((p) => seen.add(p.id));

      const newPosts = [];
      for (const p of fresh) {
        if (known.has(p.id)) knownStreak++;
        else {
          knownStreak = 0;
          newPosts.push(p);
        }
      }
      total += fresh.length;
      // Alle opslag sendes med, så eksisterende rækker kan få billede; baggrunden springer kendte over
      if (fresh.length) send({ type: "batch", posts: fresh });
      send({ type: "progress", seen: total });

      if (mode === "new" && knownStreak >= 15) {
        return send({ type: "done", reason: `Nåede til opslag, der allerede er i Notion (${total} gennemgået).` });
      }
      emptyPages = fresh.length ? 0 : emptyPages + 1;
      if (!next || emptyPages >= 2) {
        let decoded = "";
        try {
          decoded = token ? atob(token) : "";
        } catch (_) {}
        send({
          type: "log",
          msg: `Stopper ved nr. ${start}: ${posts.length} opslag på siden, ${fresh.length} nye, sidemarkør: ${next ? "ja" : "nej"}, tomme sider i træk: ${emptyPages}. Sidste markør: ${decoded}`
        });
        break;
      }
      token = next;
      start += count || 10;
      await sleep(400 + Math.random() * 300);
    }
    send({
      type: "done",
      reason: stop ? "Stoppet." : `Ikke flere opslag (${total} gennemgået via hurtig metode).`
    });
  }

  // ---------- Statusskærm over LinkedIn-siden ----------

  let overlay = null;

  function esc(s) {
    return String(s ?? "").replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  }

  function showOverlay() {
    if (overlay) return;
    const host = document.createElement("div");
    host.style.cssText = "position:fixed;inset:0;z-index:2147483647;";
    const root = host.attachShadow({ mode: "open" });
    root.innerHTML = `
      <style>
        .bg { position:fixed; inset:0; background:rgba(10,14,20,.92); display:flex; align-items:center; justify-content:center;
              font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; color:#e8edf3; }
        .card { width:min(520px,90vw); background:#151b23; border:1px solid #2a3441; border-radius:14px; padding:28px 32px; }
        h1 { font-size:20px; margin:0 0 4px; font-weight:600; }
        .ver { color:#7d8a99; font-size:12px; margin-bottom:22px; }
        .num { font-size:54px; font-weight:700; line-height:1; margin:4px 0; }
        .lbl { color:#9aa7b5; margin-bottom:18px; }
        .phase { font-weight:600; margin-bottom:8px; }
        .bar { height:8px; background:#2a3441; border-radius:4px; overflow:hidden; margin-bottom:8px; }
        .fill { height:100%; width:0; background:#0a66c2; transition:width .3s; }
        .fill.busy { width:30%; animation:slide 1.2s ease-in-out infinite; }
        @keyframes slide { 0% { margin-left:-30%; } 100% { margin-left:100%; } }
        .small { color:#7d8a99; font-size:13px; min-height:20px; word-break:break-word; }
        .btns { margin-top:20px; display:none; gap:10px; }
        .btns.show { display:flex; }
        button, a.btn { font:inherit; padding:8px 16px; border-radius:8px; border:1px solid #3a4655; background:#222b36;
                        color:#e8edf3; cursor:pointer; text-decoration:none; }
        a.btn { background:#0a66c2; border-color:#0a66c2; }
        .stop { margin-top:16px; }
      </style>
      <div class="bg"><div class="card">
        <h1>LinkedIn til Notion</h1>
        <div class="ver">Version ${esc(chrome.runtime.getManifest().version)}</div>
        <div class="num" id="num">0</div>
        <div class="lbl" id="lbl">gemte opslag gennemgået</div>
        <div class="phase" id="phase">Henter fra LinkedIn…</div>
        <div class="bar"><div class="fill busy" id="fill"></div></div>
        <div class="small" id="detail"></div>
        <div class="small" id="last"></div>
        <button class="stop" id="stop">Stop</button>
        <div class="btns" id="btns">
          <a class="btn" id="dash" target="_blank" rel="noopener" hidden>Åbn dashboard</a>
          <a class="btn" id="notion" target="_blank" rel="noopener">Åbn Notion</a>
          <button id="close">Luk</button>
        </div>
      </div></div>`;
    document.documentElement.appendChild(host);
    const $ = (id) => root.getElementById(id);
    overlay = { host, $ };
    $("close").onclick = () => {
      host.remove();
      overlay = null;
    };
    $("stop").onclick = () => chrome.runtime.sendMessage({ cmd: "stop" });
    chrome.storage.local.get(["status", "log", "pageId", "kehaEnabled", "kehaUrl", "notionEnabled"]).then(renderOverlay);
  }

  function renderOverlay(d) {
    if (!overlay) return;
    const { $ } = overlay;
    if (d.pageId) $("notion").href = "https://www.notion.so/" + d.pageId.replace(/-/g, "");
    if (d.notionEnabled === false || ("pageId" in d && !d.pageId)) $("notion").hidden = true;
    if (d.kehaEnabled) {
      $("dash").href = (d.kehaUrl || "https://keha.dk").replace(/\/+$/, "") + "/linkedfetch";
      $("dash").hidden = false;
    }
    if (d.log && d.log.length) $("last").textContent = d.log[d.log.length - 1].replace(/^\S+,\s*\S+\s+/, "");
    const s = d.status;
    if (!s) return;
    const total = (s.uploaded || 0) + (s.queued || 0);
    if (s.running && total === 0) {
      $("num").textContent = s.seen || 0;
      $("lbl").textContent = "gemte opslag gennemgået på LinkedIn";
      $("phase").textContent = "Henter fra LinkedIn…";
      $("fill").className = "fill busy";
      $("fill").style.width = "";
      $("detail").textContent = `${s.found || 0} nye, som ikke ligger i Notion endnu`;
    } else if (s.running) {
      $("num").textContent = `${s.uploaded} / ${total}`;
      $("lbl").textContent = "sendt eller opdateret";
      $("phase").textContent = "Sender til Notion…";
      $("fill").className = "fill";
      $("fill").style.width = Math.round((100 * s.uploaded) / Math.max(total, 1)) + "%";
      $("detail").textContent = `${s.seen || 0} gennemgået på LinkedIn${s.errors ? `, ${s.errors} fejl` : ""}`;
    } else {
      $("num").textContent = s.uploaded || 0;
      $("lbl").textContent = "nye opslag lagt i Notion";
      $("phase").textContent = "Færdig";
      $("fill").className = "fill";
      $("fill").style.width = "100%";
      $("detail").textContent = `${s.seen || 0} gennemgået på LinkedIn${s.errors ? `, ${s.errors} fejl` : ""}`;
      $("stop").style.display = "none";
      $("btns").classList.add("show");
    }
  }

  chrome.storage.onChanged.addListener((changes) => {
    const d = {};
    for (const [k, v] of Object.entries(changes)) d[k] = v.newValue;
    renderOverlay(d);
  });

  async function run(mode, knownArr) {
    showOverlay();
    try {
      await fastScrape(mode, new Set(knownArr || []));
    } catch (e) {
      send({ type: "log", msg: `Hurtig metode fejlede (${e.message}). Skifter til den langsomme metode.` });
      await scrape(mode, knownArr);
    }
  }

  chrome.runtime.onMessage.addListener((msg, _s, sendResponse) => {
    if (msg.cmd === "capture") {
      capture().then(sendResponse);
      return true;
    }
    if (msg.cmd === "scrape") {
      stop = false;
      run(msg.mode, msg.known);
      sendResponse({ ok: true });
    } else if (msg.cmd === "stop") {
      stop = true;
      sendResponse({ ok: true });
    } else if (msg.cmd === "diagnose") {
      sendResponse(diagnose());
    }
  });
})();
