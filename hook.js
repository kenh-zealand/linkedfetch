// LinkedIn til Notion – gemte opslag
// Copyright (c) 2026 Kenneth Hansen
//
// Kører inde i LinkedIn-siden og noterer de datakald, siden selv laver,
// så udvidelsen kan se, hvordan LinkedIn henter listen over gemte opslag.

(() => {
  if (window.__liNotionHook) return;
  window.__liNotionHook = true;

  const isVoyager = (u) => /\/voyager\/api\//.test(u || "");

  function report(url, headers, body) {
    try {
      window.postMessage({ __liNotionCapture: true, rec: { url: String(url), headers, body } }, "*");
    } catch (_) {}
  }

  const P = XMLHttpRequest.prototype;
  const open = P.open;
  const setHeader = P.setRequestHeader;
  const send = P.send;

  P.open = function (method, url) {
    this.__liUrl = url;
    this.__liHeaders = {};
    return open.apply(this, arguments);
  };
  P.setRequestHeader = function (k, v) {
    if (this.__liHeaders) this.__liHeaders[k] = v;
    return setHeader.apply(this, arguments);
  };
  P.send = function () {
    if (isVoyager(String(this.__liUrl))) {
      this.addEventListener("load", () => {
        const url = this.__liUrl;
        const headers = this.__liHeaders;
        try {
          const rt = this.responseType;
          const r = this.response;
          if (rt === "" || rt === "text") report(url, headers, this.responseText);
          else if (rt === "json") report(url, headers, JSON.stringify(r));
          else if (rt === "blob") r.text().then((t) => report(url, headers, t));
          else if (rt === "arraybuffer") report(url, headers, new TextDecoder().decode(r));
          else report(url, headers, String(r));
        } catch (_) {
          report(url, headers, "");
        }
      });
    }
    return send.apply(this, arguments);
  };

  const origFetch = window.fetch;
  window.fetch = async function (input, init) {
    const url = typeof input === "string" ? input : input?.url;
    const res = await origFetch.apply(this, arguments);
    if (isVoyager(url)) {
      try {
        const headers = {};
        const h = init?.headers || (typeof input !== "string" ? input.headers : null);
        if (h) new Headers(h).forEach((v, k) => (headers[k] = v));
        report(url, headers, await res.clone().text());
      } catch (_) {}
    }
    return res;
  };
})();
