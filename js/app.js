(async () => {
  "use strict";

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

  /* ---------------- Carrega o conteúdo da API ---------------- */
  let D;
  try {
    // Site publicado (GitHub Pages): data.json gerado pelo robô. No computador local: api.php.
    let res = await fetch("data.json", { headers: { Accept: "application/json" } }).catch(() => null);
    const isJson = res && res.ok && (res.headers.get("content-type") || "").includes("json");
    if (!isJson) res = await fetch("api.php?action=data", { headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    D = await res.json();
    D.__static = isJson;
  } catch (err) {
    console.error(err);
    $("#faq-grid").removeAttribute("aria-busy");
    $("#faq-grid").innerHTML = navigator.onLine
      ? `<div class="empty"><p><strong>Não foi possível carregar o conteúdo.</strong></p><p>Tente recarregar a página em instantes.</p></div>`
      : `<div class="empty"><p><strong>Você está sem internet.</strong></p><p>Abra o site uma vez com conexão para que ele fique salvo e funcione offline depois.</p></div>`;
    return;
  }
  const FAQ = D.faq;
  const DOCS = D.docs;
  const CATEGORIES = D.categories;
  const QUICK_SEARCHES = D.quickSearches;
  const STATUS = D.status;
  const STATS = D.stats;
  const TIMELINE = D.timeline;
  const AMENITIES = D.amenities;
  const CHECKLIST = D.checklist;
  const VENDORS = D.vendors;
  const GLOSSARY = D.glossary;
  const GALLERY = D.gallery.albums || [];
  const UPDATED_AT = D.updatedAt;

  // Rolagem suave só se o usuário não pediu "reduzir movimento" no sistema
  const SMOOTH = window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth";

  // Trava a rolagem da página de fundo enquanto um documento/foto está aberto (sem "pular" a página)
  let lockCount = 0;
  function lockPageScroll(lock) {
    lockCount = Math.max(0, lockCount + (lock ? 1 : -1));
    const root = document.documentElement;
    if (lockCount > 0 && !root.classList.contains("scroll-locked")) {
      root.style.setProperty("--scrollbar-w", `${window.innerWidth - root.clientWidth}px`);
      root.classList.add("scroll-locked");
    } else if (lockCount === 0) {
      root.classList.remove("scroll-locked");
    }
  }

  /* ---------------- Ícones (sprite SVG Lucide em img/icons.svg) ---------------- */
  // Aceita o nome de um ícone ("key-round"); qualquer outro valor (ex.: emoji digitado no painel) é exibido como texto.
  const icon = (name, cls = "") =>
    /^[a-z0-9-]+$/.test(name || "")
      ? `<svg class="ico ${cls}" aria-hidden="true" focusable="false"><use href="img/icons.svg#i-${name}"/></svg>`
      : `<span class="ico-text" aria-hidden="true">${name || ""}</span>`;

  /* ---------------- Utilidades de texto ---------------- */
  const normalize = (s) =>
    (s || "")
      .toString()
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase();

  const stripHtml = (html) => {
    const d = document.createElement("div");
    d.innerHTML = html;
    return d.textContent || "";
  };

  const escapeHtml = (s) =>
    s.replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  const STOPWORDS = new Set(
    "a o as os de da do das dos e em no na nos nas um uma uns umas para por pra com que qual quais quando como onde se sao ser tem ter vai vou eu meu minha meus minhas ja ou ao aos sobre posso pode".split(" ")
  );

  // Sinônimos: um termo buscado também encontra estes
  const SYNONYMS = {
    luz: ["energia", "eletrica", "enel"],
    energia: ["luz", "enel"],
    varanda: ["terraco", "sacada"],
    sacada: ["terraco", "varanda"],
    terraco: ["varanda", "sacada"],
    garagem: ["vaga", "estacionamento"],
    estacionamento: ["vaga", "garagem"],
    carro: ["vaga", "garagem"],
    academia: ["fitness"],
    pet: ["animal", "cachorro", "gato"],
    cachorro: ["animal", "pet"],
    gato: ["animal", "pet"],
    ar: ["ar-condicionado", "split"],
    split: ["ar-condicionado"],
    voltagem: ["110v", "220v"],
    tomada: ["110v", "220v", "voltagem"],
    chave: ["chaves"],
    entrega: ["chaves", "prazo"],
    mudanca: ["mudar", "mudanca"],
    taxa: ["condominio", "taxa"],
    camera: ["cftv"],
    cameras: ["cftv"],
    vazamento: ["ralo", "hidraulica"],
    piscina: ["lazer"],
    churrasco: ["churrasqueira"],
    mercado: ["easy market"],
    lavar: ["lavanderia"],
    maquina: ["lavanderia", "lavadora"],
    atraso: ["carencia", "prazo"],
    atrasar: ["carencia", "prazo"],
    sindica: ["sindico"],
    "habite": ["habite-se"],
  };

  const tokenize = (q) =>
    normalize(q)
      .split(/[^a-z0-9\-]+/)
      .filter((t) => t.length > 1 && !STOPWORDS.has(t));

  /* ---------------- Índice de busca ---------------- */
  const index = FAQ.map((item) => ({
    item,
    q: normalize(item.q),
    a: normalize(stripHtml(item.a)),
    tags: normalize(item.tags.join(" ")),
  }));

  function scoreItem(entry, tokens) {
    if (!tokens.length) return 1;
    let total = 0;
    let hits = 0;
    for (const t of tokens) {
      const variants = [t, ...(SYNONYMS[t] || [])];
      // plural simples: "chaves"/"chave", "tomadas"/"tomada"
      if (t.length > 4 && t.endsWith("s")) variants.push(t.slice(0, -1));
      // radical simples: "recebo"/"receber", "instalar"/"instalação"
      if (t.length >= 6) variants.push(t.slice(0, Math.max(5, t.length - 3)));
      let best = 0;
      for (const v of variants) {
        let s = 0;
        if (entry.q.includes(v)) s += 6;
        if (entry.tags.includes(v)) s += 4;
        if (entry.a.includes(v)) s += 2;
        best = Math.max(best, v === t ? s : s * 0.8);
      }
      if (best > 0) hits++;
      total += best;
    }
    // 1–2 termos: todos precisam aparecer; frases maiores: pelo menos 60%
    const needed = tokens.length <= 2 ? tokens.length : Math.ceil(tokens.length * 0.6);
    if (hits < needed) return 0;
    return total * (hits / tokens.length);
  }

  const EMPTY_HTML = $("#empty").innerHTML;

  /* ---------------- Estado ---------------- */
  const state = { query: "", cat: "todas" };

  /* ---------------- Render: status / stats ---------------- */
  function renderStatus() {
    $("#status-date").textContent = `atualizado em ${UPDATED_AT}`;
    $("#updated-at").textContent = UPDATED_AT;
    $("#status-grid").innerHTML = STATUS.map(
      (s) => `
      <div class="status-item tone-${s.tone}">
        <span class="status-label">${s.label}</span>
        <strong class="status-value">${s.value}</strong>
        <span class="status-detail">${s.detail}</span>
      </div>`
    ).join("");

    $("#stats").innerHTML = STATS.map(
      (s) => `<div class="stat"><strong>${s.value}</strong><span>${s.label}</span></div>`
    ).join("");
  }

  /* ---------------- Render: chips e filtros ---------------- */
  function renderChips() {
    $("#quick-chips").innerHTML = QUICK_SEARCHES.map(
      (q) => `<button type="button" class="chip" data-q="${escapeHtml(q)}">${escapeHtml(q)}</button>`
    ).join("");
    $("#quick-chips").addEventListener("click", (e) => {
      const b = e.target.closest(".chip");
      if (!b) return;
      setQuery(b.dataset.q);
      $("#duvidas").scrollIntoView({ behavior: SMOOTH, block: "start" });
    });
  }

  function renderFilters(counts) {
    // Computador/tablet: botões que quebram linha (todos visíveis)
    $("#filters").innerHTML = CATEGORIES.map((c) => {
      const n = counts[c.id] || 0;
      const active = state.cat === c.id;
      return `<button type="button" aria-pressed="${active}" class="filter ${active ? "active" : ""} ${n === 0 ? "dim" : ""}" data-cat="${c.id}">
        ${icon(c.icon)} ${c.label} <span class="count">${n}</span>
      </button>`;
    }).join("");
    // Celular: caixa de seleção compacta
    $("#cat-select").innerHTML = CATEGORIES.map((c) => {
      const n = counts[c.id] || 0;
      const disabled = n === 0 && c.id !== "todas" && state.cat !== c.id;
      return `<option value="${c.id}" ${state.cat === c.id ? "selected" : ""} ${disabled ? "disabled" : ""}>${escapeHtml(c.label)} (${n})</option>`;
    }).join("");
  }

  /* ---------------- Render: FAQ ---------------- */
  const STATUS_LABEL = { oficial: "Oficial", informado: "Informado em visita", pendente: "Pendente" };
  const catById = Object.fromEntries(CATEGORIES.map((c) => [c.id, c]));

  function cardHtml(item) {
    const cat = catById[item.cat];
    const sources = item.src
      .map((id) => DOCS[id] && { ...DOCS[id], id })
      .filter(Boolean)
      .map((d) => `<a href="${d.url}" target="_blank" rel="noopener" ${viewerAttrs(d)} class="src-link" title="${escapeHtml(d.desc)}">${escapeHtml(d.title)}${d.date ? ` <span>(${d.date})</span>` : ""}</a>`)
      .join("");

    return `
    <article class="faq-card" id="faq-${item.id}" data-id="${item.id}">
      <button class="faq-q" type="button" aria-expanded="false" aria-controls="ans-${item.id}">
        <span class="faq-cat" title="${cat.label}">${icon(cat.icon)}</span>
        <span class="faq-q-text">${escapeHtml(item.q)}</span>
        <span class="badge badge-${item.status}">${STATUS_LABEL[item.status]}</span>
        <svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
      </button>
      <div class="faq-a" id="ans-${item.id}" hidden>
        <div class="faq-a-body">${item.a}</div>
        <footer class="faq-foot">
          <div class="sources"><span class="src-title">Fonte:</span>${sources}</div>
          <div class="share">
            <button type="button" class="icon-btn" data-action="copy" title="Copiar link desta pergunta">${icon("link")} Copiar link</button>
            <button type="button" class="icon-btn" data-action="whats" title="Enviar no WhatsApp">${icon("message-circle")} WhatsApp</button>
          </div>
        </footer>
      </div>
    </article>`;
  }

  function highlight(root, tokens) {
    if (!tokens.length) return;
    const terms = new Set();
    tokens.forEach((t) => {
      terms.add(t);
      (SYNONYMS[t] || []).forEach((s) => terms.add(s));
      if (t.length > 4 && t.endsWith("s")) terms.add(t.slice(0, -1));
    });
    const list = [...terms].filter((t) => t.length > 1);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: (n) =>
        n.parentElement.closest("mark, a.src-link, .share, .badge") ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);

    nodes.forEach((node) => {
      const text = node.nodeValue;
      const norm = normalize(text); // NFD + remoção de acento preserva o tamanho em pt-BR
      if (norm.length !== text.length) return;
      const ranges = [];
      list.forEach((term) => {
        let i = norm.indexOf(term);
        while (i !== -1) {
          ranges.push([i, i + term.length]);
          i = norm.indexOf(term, i + term.length);
        }
      });
      if (!ranges.length) return;
      ranges.sort((a, b) => a[0] - b[0]);
      const merged = [];
      ranges.forEach((r) => {
        const last = merged[merged.length - 1];
        if (last && r[0] <= last[1]) last[1] = Math.max(last[1], r[1]);
        else merged.push([...r]);
      });
      const frag = document.createDocumentFragment();
      let pos = 0;
      merged.forEach(([s, e]) => {
        if (s > pos) frag.appendChild(document.createTextNode(text.slice(pos, s)));
        const m = document.createElement("mark");
        m.textContent = text.slice(s, e);
        frag.appendChild(m);
        pos = e;
      });
      if (pos < text.length) frag.appendChild(document.createTextNode(text.slice(pos)));
      node.parentNode.replaceChild(frag, node);
    });
  }

  function renderFaq() {
    const tokens = tokenize(state.query);
    const scored = index
      .map((e) => ({ e, s: scoreItem(e, tokens) }))
      .filter((x) => x.s > 0);

    const counts = { todas: scored.length };
    scored.forEach(({ e }) => (counts[e.item.cat] = (counts[e.item.cat] || 0) + 1));
    renderFilters(counts);

    let list = scored.filter(({ e }) => state.cat === "todas" || e.item.cat === state.cat);
    if (tokens.length) list.sort((a, b) => b.s - a.s);

    const grid = $("#faq-grid");
    grid.innerHTML = list.map(({ e }) => cardHtml(e.item)).join("");
    grid.removeAttribute("aria-busy");
    annotateGlossary(grid);
    renderGlossaryCallout(state.query);

    const n = list.length;
    $("#result-count").textContent = tokens.length
      ? `${n} ${n === 1 ? "resposta encontrada" : "respostas encontradas"} para “${state.query.trim()}”`
      : `${FAQ.length} perguntas respondidas a partir dos documentos oficiais e dos comunicados`;

    $("#empty").innerHTML = EMPTY_HTML;
    $("#empty").hidden = n > 0;

    if (tokens.length) {
      highlight(grid, tokens);
      // Com busca ativa, abre automaticamente os 3 primeiros resultados
      $$(".faq-card", grid).slice(0, 3).forEach((c) => toggleCard(c, true));
    }

    renderAlso(tokens);
    searchDocuments(state.query, tokens);
  }

  /* ---------------- Glossário: definição no topo e balões no texto ---------------- */
  const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

  // "ART / RRT" → ["ART", "RRT"]; "Carência (180 dias)" → ["Carência"]
  const GL = GLOSSARY.map((g) => {
    const aliases = g.term
      .replace(/\(.*?\)/g, "")
      .split("/")
      .map((s) => s.trim())
      .filter((s) => s.length > 1);
    return {
      ...g,
      aliases,
      // siglas curtas (AGI, ART, PCD) só casam em maiúsculas, para não pegar "parte", "agir" etc.
      regexes: aliases.map((a) => {
        const acronym = a.length <= 5 && a === a.toUpperCase();
        return new RegExp(`(^|[^\\p{L}\\d])(${escapeRe(a)})(?=[^\\p{L}\\d]|$)`, acronym ? "u" : "iu");
      }),
    };
  });

  function renderGlossaryCallout(query) {
    const box = $("#gl-callout");
    const q = normalize(query).trim();
    const hits =
      q.length < 2
        ? []
        : GL.filter((g) =>
            g.aliases.some((a) => {
              const na = normalize(a);
              return na === q || (q.length >= 3 && na.startsWith(q)) || new RegExp(`(^|\\W)${escapeRe(na)}(\\W|$)`).test(q);
            })
          ).slice(0, 2);
    box.hidden = !hits.length;
    box.innerHTML = hits
      .map(
        (g) => `<div class="gl-card"><span class="gl-icon">${icon("book-open")}</span>
          <div><span class="gl-label">O que é</span> <strong>${escapeHtml(g.term)}</strong><p>${escapeHtml(g.def)}</p></div></div>`
      )
      .join("");
  }

  // Marca os termos do glossário nas respostas (1ª ocorrência de cada termo por card)
  function annotateGlossary(root) {
    $$(".faq-a-body", root).forEach((body) => {
      const used = new Set();
      const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT, {
        acceptNode: (n) => (n.parentElement.closest("a, abbr, th") ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
      });
      const nodes = [];
      while (walker.nextNode()) nodes.push(walker.currentNode);
      nodes.forEach((node) => {
        let current = node;
        GL.forEach((g, gi) => {
          if (used.has(gi) || !current) return;
          for (const re of g.regexes) {
            const m = re.exec(current.nodeValue);
            if (!m) continue;
            const after = current.splitText(m.index + m[1].length);
            const rest = after.splitText(m[2].length);
            const abbr = document.createElement("abbr");
            abbr.className = "gl-term";
            abbr.tabIndex = 0;
            abbr.dataset.gl = gi;
            abbr.textContent = after.nodeValue;
            after.replaceWith(abbr);
            used.add(gi);
            current = rest; // segue procurando outros termos no restante do texto
            break;
          }
        });
      });
    });
  }

  // Balão com a definição (mouse, teclado e toque)
  const pop = document.createElement("div");
  pop.className = "gl-pop";
  pop.setAttribute("role", "tooltip");
  pop.hidden = true;
  document.body.appendChild(pop);
  let popFor = null;

  function showPop(el) {
    const g = GL[+el.dataset.gl];
    if (!g) return;
    popFor = el;
    pop.innerHTML = `<strong>${escapeHtml(g.term)}</strong><span>${escapeHtml(g.def)}</span>`;
    pop.hidden = false;
    const r = el.getBoundingClientRect();
    const w = Math.min(320, window.innerWidth - 24);
    pop.style.width = `${w}px`;
    const left = Math.max(12, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 12));
    const below = r.bottom + 8 + pop.offsetHeight < window.innerHeight;
    pop.style.left = `${left + window.scrollX}px`;
    pop.style.top = `${(below ? r.bottom + 8 : r.top - pop.offsetHeight - 8) + window.scrollY}px`;
  }
  function hidePop() {
    pop.hidden = true;
    popFor = null;
  }
  document.addEventListener("mouseover", (e) => {
    const t = e.target.closest && e.target.closest(".gl-term");
    if (t) showPop(t);
    else if (popFor && !(e.target.closest && e.target.closest(".gl-pop"))) hidePop();
  });
  document.addEventListener("focusin", (e) => {
    if (e.target.classList && e.target.classList.contains("gl-term")) showPop(e.target);
    else if (popFor) hidePop();
  });
  document.addEventListener("click", (e) => {
    const t = e.target.closest(".gl-term");
    if (t) {
      e.preventDefault();
      popFor === t && pop.hidden === false && e.pointerType !== "mouse" ? hidePop() : showPop(t);
    } else if (!e.target.closest(".gl-pop")) hidePop();
  });
  document.addEventListener("keydown", (e) => e.key === "Escape" && hidePop());
  window.addEventListener("scroll", () => popFor && hidePop(), { passive: true });

  /* ---------- Busca nos documentos feita no navegador (site estático) ----------
     Mesma lógica do Search.php: termos com sinônimos/radical, peso pela raridade (IDF),
     exige 60% dos termos em frases longas e devolve o melhor trecho de cada documento. */
  let searchIdx = null;
  async function loadSearchIndex() {
    if (searchIdx) return searchIdx;
    const res = await fetch("search.json");
    const data = await res.json();
    data.chunks = data.chunks.map(([d, text]) => ({ d, text, norm: normalize(text) }));
    searchIdx = data;
    return data;
  }

  const variantsOf = (t) => {
    const v = new Set([t, ...(SYNONYMS[t] || [])]);
    if (t.length > 4 && t.endsWith("s")) v.add(t.slice(0, -1));
    if (t.length >= 6) v.add(t.slice(0, Math.max(5, t.length - 3)));
    return [...v];
  };
  const countOf = (hay, needle) => {
    let n = 0, i = hay.indexOf(needle);
    while (i !== -1 && n < 4) { n++; i = hay.indexOf(needle, i + needle.length); }
    return n;
  };

  async function searchDocsLocal(q) {
    const idx = await loadSearchIndex();
    const tokens = tokenize(q).slice(0, 8);
    if (!tokens.length) return [];
    const variants = Object.fromEntries(tokens.map((t) => [t, variantsOf(t)]));
    const needed = tokens.length <= 2 ? tokens.length : Math.ceil(tokens.length * 0.6);

    // frequência por bloco e raridade de cada termo
    const df = Object.fromEntries(tokens.map((t) => [t, 0]));
    const rows = [];
    for (const c of idx.chunks) {
      const tf = {};
      let any = false;
      for (const t of tokens) {
        let best = 0;
        for (const v of variants[t]) {
          const n = countOf(c.norm, v);
          if (n) best = Math.max(best, n * (v === t ? 1 : 0.7));
        }
        tf[t] = best;
        if (best) { df[t]++; any = true; }
      }
      if (any) rows.push({ c, tf });
    }
    const total = idx.chunks.length || 1;
    const idf = Object.fromEntries(tokens.map((t) => [t, Math.log(1 + total / Math.max(1, df[t]))]));

    const byDoc = new Map();
    for (const { c, tf } of rows) {
      let hits = 0, score = 0, firstPos = -1, firstIdf = 0;
      const matched = [];
      for (const t of tokens) {
        if (!tf[t]) continue;
        hits++;
        score += (1 + Math.log(tf[t])) * idf[t];
        for (const v of variants[t]) {
          const p = c.norm.indexOf(v);
          if (p === -1) continue;
          matched.push(v);
          if (firstPos === -1 || idf[t] > firstIdf) { firstPos = p; firstIdf = idf[t]; }
        }
      }
      if (hits < needed) continue;
      score *= (hits / tokens.length) ** 2;
      const prev = byDoc.get(c.d);
      if (!prev || prev.score < score) byDoc.set(c.d, { score, c, firstPos, matched, matches: (prev?.matches || 0) + 1 });
      else prev.matches++;
    }

    return [...byDoc.entries()]
      .sort((a, b) => b[1].score - a[1].score)
      .slice(0, 8)
      .map(([d, r]) => {
        const doc = idx.docs[d];
        return { ...doc, doc_id: doc.id, matches: r.matches, snippet: snippetOf(r.c.text, r.firstPos, r.matched) };
      });
  }

  // Recorta ~260 caracteres ao redor do termo e marca os termos (texto e normalizado têm o mesmo tamanho)
  function snippetOf(text, pos, terms) {
    const start = Math.max(0, pos - 90);
    const cut = text.slice(start, start + 260);
    const cutNorm = normalize(cut);
    const ranges = [];
    for (const t of new Set(terms)) {
      if (t.length < 2) continue;
      let i = cutNorm.indexOf(t);
      while (i !== -1) { ranges.push([i, i + t.length]); i = cutNorm.indexOf(t, i + t.length); }
    }
    ranges.sort((a, b) => a[0] - b[0]);
    let html = "", cur = 0;
    for (const [s, e] of ranges) {
      if (s < cur) continue;
      html += escapeHtml(cut.slice(cur, s)) + "<mark>" + escapeHtml(cut.slice(s, e)) + "</mark>";
      cur = e;
    }
    html += escapeHtml(cut.slice(cur));
    return (start > 0 ? "… " : "") + html.trim() + (start + 260 < text.length ? " …" : "");
  }

  // Busca dentro do texto dos documentos do Drive
  let docSearchSeq = 0;
  let docSearchTimer;
  function searchDocuments(query, tokens) {
    const box = $("#doc-results");
    clearTimeout(docSearchTimer);
    const seq = ++docSearchSeq;
    if (!tokens.length) {
      box.hidden = true;
      box.innerHTML = "";
      return;
    }
    docSearchTimer = setTimeout(async () => {
      try {
        let results = [];
        if (D.__static) {
          results = await searchDocsLocal(query.trim());
        } else {
          const res = await fetch(`api.php?action=search&q=${encodeURIComponent(query.trim())}`);
          ({ results = [] } = await res.json());
        }
        if (seq !== docSearchSeq) return; // chegou uma busca mais nova
        box.hidden = !results.length;
        box.innerHTML = results.length
          ? `<h3>Encontrado nos documentos <span class="muted">(${results.length})</span></h3>
             <p class="muted">Trechos dos arquivos do Drive. Confira sempre o documento completo.</p>
             <div class="doc-hits">${results
               .map(
                 (r) => `
               <a class="doc-hit" href="${r.url}" target="_blank" rel="noopener" ${viewerAttrs({ ...r, id: r.doc_id })}>
                 <span class="doc-hit-title">${icon("file-text")} ${escapeHtml(r.title)}</span>
                 <span class="doc-hit-snippet">${r.snippet}</span>
                 <span class="doc-hit-meta">${escapeHtml(r.folder || "")}${r.matches > 1 ? ` · ${r.matches} trechos` : ""}</span>
               </a>`
               )
               .join("")}</div>`
          : "";
        if (results.length && $("#empty").hidden === false) {
          $("#empty").innerHTML = `<p><strong>Nenhuma pergunta pronta sobre isso ainda</strong>, mas encontramos trechos nos documentos logo abaixo.</p>`;
        }
      } catch (e) {
        console.error(e);
      }
    }, 250);
  }

  /* ---------------- O que tem perto (OpenStreetMap + Leaflet) ---------------- */
  const PL = { cat: null, map: null, layer: null, markers: [], loading: null };

  function placeTime(dist) {
    // distância em linha reta × 1,3 (ruas não são retas); 80 m/min a pé, ~21 km/h de carro na cidade
    const walk = Math.round((dist * 1.3) / 80);
    if (walk <= 25) return { mode: "walking", icon: "footprints", text: `~${Math.max(1, walk)} min a pé` };
    return { mode: "driving", icon: "car", text: `~${Math.max(3, Math.round((dist * 1.4) / 350))} min de carro` };
  }
  const fmtDist = (m) => (m < 1000 ? `${Math.round(m / 10) * 10} m` : `${(m / 1000).toFixed(1).replace(".", ",")} km`);

  function renderPlaces() {
    const P = D.places;
    if (!P || !P.categories || !P.categories.length) return;
    $("#arredores").hidden = false;
    PL.cat = PL.cat || P.categories[0].id;
    $("#place-tabs").innerHTML = P.categories
      .map(
        (c) => `<button type="button" class="filter ${c.id === PL.cat ? "active" : ""}" aria-pressed="${c.id === PL.cat}" data-place="${c.id}">
          ${icon(c.icon)} ${escapeHtml(c.label)} <span class="count">${c.items.length}</span></button>`
      )
      .join("");
    $("#place-select").innerHTML = P.categories
      .map((c) => `<option value="${c.id}" ${c.id === PL.cat ? "selected" : ""}>${escapeHtml(c.label)} (${c.items.length})</option>`)
      .join("");

    const cat = P.categories.find((c) => c.id === PL.cat);
    const origin = encodeURIComponent(P.origin.address);
    $("#place-list").innerHTML = cat.items
      .map((p, i) => {
        const t = placeTime(p.dist);
        const gmaps = `https://www.google.com/maps/dir/?api=1&origin=${origin}&destination=${p.lat},${p.lon}&travelmode=${t.mode}`;
        return `<li class="place" data-i="${i}">
          <span class="place-n" aria-hidden="true">${i + 1}</span>
          <div class="place-body">
            <strong class="place-name">${escapeHtml(p.name)}</strong>
            <span class="place-kind">${escapeHtml(p.kind)}${p.address ? ` · ${escapeHtml(p.address)}` : ""}</span>
            <span class="place-dist">${icon("map-pin")} ${fmtDist(p.dist)} <span aria-hidden="true">·</span> ${icon(t.icon)} ${t.text}</span>
          </div>
          <a class="btn btn-sm btn-ghost place-go" href="${gmaps}" target="_blank" rel="noopener" aria-label="Como chegar a ${escapeHtml(p.name)}">
            ${icon("navigation")} <span>Como chegar</span></a>
        </li>`;
      })
      .join("");
    $("#places-sub").textContent = `${cat.label}: os ${cat.items.length} mais próximos do condomínio, do mais perto ao mais longe.`;
    updateMapMarkers();
  }

  function bindPlaces() {
    if (!D.places) return;
    const pick = (id) => {
      PL.cat = id;
      renderPlaces();
    };
    $("#place-tabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-place]");
      if (b) pick(b.dataset.place);
    });
    $("#place-select").addEventListener("change", (e) => pick(e.target.value));
    // Clicar no lugar (fora do botão) centraliza no mapa
    $("#place-list").addEventListener("click", (e) => {
      if (e.target.closest("a")) return;
      const li = e.target.closest(".place");
      if (!li || !PL.map) return;
      const m = PL.markers[+li.dataset.i];
      if (m) {
        PL.map.setView(m.getLatLng(), 16, { animate: SMOOTH === "smooth" });
        m.openPopup();
      }
    });
    // O mapa (Leaflet) só é carregado quando a seção aparece na tela, para não pesar a página
    new IntersectionObserver(
      (entries, obs) => {
        if (entries.some((e) => e.isIntersecting)) {
          obs.disconnect();
          loadMap();
        }
      },
      { rootMargin: "300px" }
    ).observe($("#arredores"));
  }

  function loadMap() {
    if (PL.loading) return PL.loading;
    const css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.css";
    css.integrity = "sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=";
    css.crossOrigin = "";
    document.head.appendChild(css);
    PL.loading = new Promise((resolve, reject) => {
      const s = document.createElement("script");
      s.src = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";
      s.integrity = "sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=";
      s.crossOrigin = "";
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    })
      .then(() => {
        const o = D.places.origin;
        PL.map = L.map("place-map", { scrollWheelZoom: false, zoomControl: true }).setView([o.lat, o.lon], 14);
        L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
          maxZoom: 19,
          attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        }).addTo(PL.map);
        L.marker([o.lat, o.lon], {
          icon: L.divIcon({ className: "pin-home", html: `<span>${icon("house")}</span>`, iconSize: [40, 40], iconAnchor: [20, 40] }),
          zIndexOffset: 1000,
          title: "Guido Parque Nações Unidas",
        })
          .addTo(PL.map)
          .bindPopup("<strong>Guido Parque Nações Unidas</strong><br>Av. Guido Caloi, 1987 (localização aproximada)");
        PL.layer = L.layerGroup().addTo(PL.map);
        updateMapMarkers();
      })
      .catch(() => {
        $("#place-map").innerHTML = `<p class="muted place-map-off">Não foi possível carregar o mapa agora. A lista ao lado continua funcionando.</p>`;
      });
    return PL.loading;
  }

  function updateMapMarkers() {
    if (!PL.map || !window.L) return;
    PL.layer.clearLayers();
    const cat = D.places.categories.find((c) => c.id === PL.cat);
    PL.markers = cat.items.map((p, i) =>
      L.marker([p.lat, p.lon], {
        icon: L.divIcon({ className: "pin-place", html: `<span>${i + 1}</span>`, iconSize: [28, 28], iconAnchor: [14, 14] }),
        title: p.name,
      })
        .bindPopup(`<strong>${escapeHtml(p.name)}</strong><br>${escapeHtml(p.kind)}<br>${fmtDist(p.dist)} · ${placeTime(p.dist).text}`)
        .addTo(PL.layer)
    );
    const o = D.places.origin;
    const bounds = L.latLngBounds([[o.lat, o.lon], ...cat.items.map((p) => [p.lat, p.lon])]);
    PL.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
  }

  /* ---------------- Acompanhamento da obra (site da Cury) ---------------- */
  function renderObra() {
    const o = D.obra;
    const box = $("#obra");
    if (!o || !o.items || !o.items.length) return;
    const fmtDate = (s) => (s ? new Date(s.replace(" ", "T")).toLocaleDateString("pt-BR") : "");
    // variação em relação à leitura anterior (quando houve mudança)
    const hist = D.obraHistory || [];
    const prev = hist.length >= 2 ? hist[hist.length - 2] : null;
    const delta = (label, pct) => {
      if (!prev || prev.items[label] === undefined) return "";
      const d = pct - prev.items[label];
      return d > 0 ? `<span class="obra-delta" title="desde ${fmtDate(prev.date)}">+${d}</span>` : "";
    };
    const avg = Math.round(o.average);
    const done = o.items.filter((i) => i.pct >= 100).length;

    box.hidden = false;
    box.innerHTML = `
      <div class="obra-head">
        <div>
          <h2 id="obra-title">Acompanhamento da obra</h2>
          <p class="obra-sub">${done} de ${o.items.length} etapas concluídas · fonte: <a href="${escapeHtml(o.source)}" target="_blank" rel="noopener">site da Cury</a> · verificado em ${fmtDate(o.checked_at)}</p>
        </div>
        <div class="obra-avg" role="img" aria-label="Média das etapas: ${avg}%">
          <svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15.9" class="ring-bg"/><circle cx="18" cy="18" r="15.9" class="ring" stroke-dasharray="${avg} 100"/></svg>
          <span><strong>${avg}%</strong><small>média</small></span>
        </div>
      </div>
      <ul class="obra-list">
        ${o.items
          .map(
            (i) => `
          <li class="${i.pct >= 100 ? "is-done" : ""}">
            <span class="obra-label">${escapeHtml(i.label)}</span>
            <span class="obra-bar" role="progressbar" aria-label="${escapeHtml(i.label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${i.pct}">
              <span style="width:${i.pct}%"></span>
            </span>
            <span class="obra-pct">${i.pct}%${delta(i.label, i.pct)}</span>
          </li>`
          )
          .join("")}
      </ul>
      <p class="obra-note">A média é calculada pela Central a partir das etapas e não é um número oficial da Cury. A medição da Caixa é diferente e serve para o financiamento.</p>`;
  }

  /* ---------------- Novidades no Drive ---------------- */
  function renderNews() {
    const items = D.news || [];
    const box = $("#news");
    if (!items.length) {
      box.hidden = true;
      return;
    }
    const fmt = (s) => {
      const d = new Date(s.replace(" ", "T"));
      const days = Math.floor((Date.now() - d) / 864e5);
      return days <= 0 ? "hoje" : days === 1 ? "ontem" : `há ${days} dias`;
    };
    box.hidden = false;
    box.innerHTML = `
      <div class="news-head"><span class="news-dot"></span><h2>Novidades no Drive</h2></div>
      <ul class="news-list">${items
        .slice(0, 8)
        .map(
          (n) => `
        <li><a href="${n.url}" target="_blank" rel="noopener" ${n.kind === "doc" ? viewerAttrs(n) : ""}>
          <span class="news-icon">${icon(n.kind === "media" ? "image" : "file-text")}</span>
          <span class="news-title">${escapeHtml(n.kind === "media" ? `${n.count} fotos/vídeos em “${n.title}”` : n.title)}</span>
          <span class="news-when">${fmt(n.date)}</span>
        </a></li>`
        )
        .join("")}</ul>`;
  }

  // Resultados extras: fornecedores, lazer e glossário que batem com a busca
  function renderAlso(tokens) {
    const box = $("#also");
    if (!tokens.length) {
      box.hidden = true;
      return;
    }
    const match = (text) => tokens.every((t) => normalize(text).includes(t) || (SYNONYMS[t] || []).some((s) => normalize(text).includes(s)));

    const g = []; // o glossário agora aparece no topo dos resultados (renderGlossaryCallout)
    const am = AMENITIES.filter((x) => match(x.name + " " + x.items));
    const v = VENDORS.filter((x) => match(x.service + " " + x.name + " " + (x.note || "")));

    const parts = [];
    if (g.length) parts.push(`<div><h4>Glossário</h4>${g.map((x) => `<p><strong>${x.term}:</strong> ${x.def}</p>`).join("")}</div>`);
    if (am.length) parts.push(`<div><h4>Áreas de lazer</h4>${am.map((x) => `<p>${icon(x.icon)} <strong>${x.name}:</strong> ${x.items}</p>`).join("")}</div>`);
    if (v.length) parts.push(`<div><h4>Fornecedores indicados</h4>${v.map((x) => `<p><strong>${x.service}:</strong> ${x.name}${x.phone ? ` · ${x.phone}` : ""}</p>`).join("")}</div>`);

    box.hidden = !parts.length;
    box.innerHTML = parts.length ? `<h3>Também encontrado</h3><div class="also-grid">${parts.join("")}</div>` : "";
  }

  function toggleCard(card, force) {
    const btn = $(".faq-q", card);
    const ans = $(".faq-a", card);
    const open = force ?? btn.getAttribute("aria-expanded") !== "true";
    btn.setAttribute("aria-expanded", String(open));
    ans.hidden = !open;
    card.classList.toggle("open", open);
  }

  function setQuery(q) {
    state.query = q;
    $$(".search-input").forEach((i) => (i.value = q));
    state.cat = "todas";
    renderFaq();
  }

  /* ---------------- Toast ---------------- */
  let toastTimer;
  function toast(msg) {
    const t = $("#toast");
    t.textContent = msg;
    t.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove("show"), 2200);
  }

  /* ---------------- Eventos do FAQ ---------------- */
  function bindFaq() {
    let debounce;
    const inputs = $$(".search-input"); // campo grande (topo) e compacto (barra fixa)
    inputs.forEach((input) => {
      input.addEventListener("input", (e) => {
        inputs.forEach((i) => i !== e.target && (i.value = e.target.value));
        clearTimeout(debounce);
        debounce = setTimeout(() => {
          state.query = e.target.value;
          state.cat = "todas";
          renderFaq();
          // digitando na barra fixa: leva aos resultados
          if (e.target.id === "search-mini" && state.query.trim()) {
            const top = $("#duvidas").getBoundingClientRect().top;
            if (top > window.innerHeight * 0.6 || top < -$("#duvidas").offsetHeight + 200) {
              $("#duvidas").scrollIntoView({ behavior: SMOOTH, block: "start" });
            }
          }
        }, 120);
      });
      input.addEventListener("keydown", (e) => {
        if (e.key === "Enter") $("#duvidas").scrollIntoView({ behavior: SMOOTH, block: "start" });
        if (e.key === "Escape") setQuery("");
      });
    });

    // Mostra a busca compacta na barra fixa quando o campo grande sai da tela
    const header = $(".topbar");
    new IntersectionObserver(
      ([entry]) => {
        const compact = !entry.isIntersecting;
        header.classList.toggle("is-compact", compact);
        $("#search-mini").tabIndex = compact ? 0 : -1;
      },
      { rootMargin: "-64px 0px 0px 0px" }
    ).observe($(".search-wrap"));

    document.addEventListener("keydown", (e) => {
      if (e.key === "/" && !/input|textarea/i.test(document.activeElement.tagName)) {
        e.preventDefault();
        (header.classList.contains("is-compact") ? $("#search-mini") : $("#search")).focus();
      }
    });

    $("#filters").addEventListener("click", (e) => {
      const b = e.target.closest(".filter");
      if (!b) return;
      state.cat = b.dataset.cat;
      renderFaq();
    });
    $("#cat-select").addEventListener("change", (e) => {
      state.cat = e.target.value;
      renderFaq();
    });

    $("#faq-grid").addEventListener("click", async (e) => {
      const card = e.target.closest(".faq-card");
      if (!card) return;
      const item = FAQ.find((f) => f.id === card.dataset.id);
      const action = e.target.closest("[data-action]")?.dataset.action;
      const url = `${location.origin}${location.pathname}#faq-${item.id}`;

      if (action === "copy") {
        try {
          await navigator.clipboard.writeText(url);
          toast("Link copiado!");
        } catch {
          prompt("Copie o link:", url);
        }
        return;
      }
      if (action === "whats") {
        const text = `*${item.q}*\n\n${stripHtml(item.a).replace(/\s+\n/g, "\n").replace(/\n{2,}/g, "\n").trim()}\n\n${url}`;
        window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, "_blank", "noopener");
        return;
      }
      if (e.target.closest(".faq-q")) {
        toggleCard(card);
        if (card.classList.contains("open")) history.replaceState(null, "", `#faq-${item.id}`);
      }
    });
  }

  function openFromHash() {
    const m = location.hash.match(/^#faq-(.+)$/);
    if (!m) return;
    const card = document.getElementById(`faq-${m[1]}`);
    if (!card) return;
    toggleCard(card, true);
    card.classList.add("flash");
    setTimeout(() => card.scrollIntoView({ behavior: SMOOTH, block: "center" }), 50);
  }

  /* ---------------- Linha do tempo ---------------- */
  function renderTimeline() {
    $("#timeline").innerHTML = TIMELINE.map(
      (t) => `
      <li class="tl-item tl-${t.state}">
        <span class="tl-dot" aria-hidden="true"></span>
        <div class="tl-body">
          <time>${t.date}</time>
          <h3>${t.title}</h3>
          <p>${t.text}</p>
        </div>
      </li>`
    ).join("");
  }

  /* ---------------- Lazer ---------------- */
  function renderAmenities() {
    $("#amenities").innerHTML = AMENITIES.map(
      (a) => `
      <div class="amenity">
        <span class="amenity-icon">${icon(a.icon)}</span>
        <div>
          <h3>${a.name}</h3>
          <p>${a.items}</p>
        </div>
      </div>`
    ).join("");
  }

  /* ---------------- Checklist ---------------- */
  const CHECK_KEY = "gpnu-checklist-v1";
  function renderChecklist() {
    const saved = new Set(JSON.parse(localStorage.getItem(CHECK_KEY) || "[]"));
    let idx = 0;
    $("#checklist-grid").innerHTML = CHECKLIST.map(
      (g) => `
      <div class="check-group">
        <h3>${g.group}</h3>
        ${g.items
          .map((it) => {
            const id = `c${idx++}`;
            return `<label class="check"><input type="checkbox" data-id="${id}" ${saved.has(id) ? "checked" : ""}/><span>${it}</span></label>`;
          })
          .join("")}
      </div>`
    ).join("");
    updateProgress();
  }

  function updateProgress() {
    const boxes = $$("#checklist-grid input");
    const done = boxes.filter((b) => b.checked);
    localStorage.setItem(CHECK_KEY, JSON.stringify(done.map((b) => b.dataset.id)));
    const pct = boxes.length ? Math.round((done.length / boxes.length) * 100) : 0;
    $("#check-progress").textContent = `${done.length}/${boxes.length} itens`;
    $("#check-bar").style.width = `${pct}%`;
  }

  function bindChecklist() {
    $("#checklist-grid").addEventListener("change", updateProgress);
    $("#check-reset").addEventListener("click", () => {
      if (!confirm("Desmarcar todos os itens do checklist?")) return;
      $$("#checklist-grid input").forEach((b) => (b.checked = false));
      updateProgress();
    });
    $("#check-print").addEventListener("click", () => {
      document.body.classList.add("print-checklist");
      window.print();
      setTimeout(() => document.body.classList.remove("print-checklist"), 500);
    });
  }

  /* ---------------- Fornecedores ---------------- */
  function renderVendors(filter = "") {
    const tokens = tokenize(filter);
    const list = VENDORS.filter((v) => {
      const hay = normalize(`${v.service} ${v.name} ${v.note || ""}`);
      return tokens.every((t) => hay.includes(t) || (t.endsWith("s") && hay.includes(t.slice(0, -1))));
    });
    $("#vendors").innerHTML = list.length
      ? list
          .map((v) => {
            const tel = v.phone.replace(/\D/g, "");
            return `
        <div class="vendor">
          <span class="vendor-service">${v.service}</span>
          <h3>${v.name}</h3>
          ${v.note ? `<p>${v.note}</p>` : ""}
          <div class="vendor-actions">
            ${tel ? `<a class="btn btn-sm btn-whats" href="https://wa.me/55${tel}" target="_blank" rel="noopener">WhatsApp ${v.phone}</a>` : `<span class="muted">Contato não informado</span>`}
            ${v.link ? `<a class="btn btn-sm btn-ghost" href="${v.link}" target="_blank" rel="noopener">Perfil</a>` : ""}
          </div>
        </div>`;
          })
          .join("")
      : `<p class="muted">Nenhum fornecedor encontrado para esse serviço.</p>`;
  }

  /* ---------------- Galeria ---------------- */
  const PAGE_SIZE = 18;
  const gal = { album: 0, filter: "all", shown: PAGE_SIZE, list: [], index: 0 };
  const thumb = (id, w) => `https://drive.google.com/thumbnail?id=${encodeURIComponent(id)}&sz=w${w}`;

  function currentItems() {
    const album = GALLERY[gal.album];
    if (!album) return [];
    return gal.filter === "all" ? album.items : album.items.filter((i) => i.type === gal.filter);
  }

  function renderGallery() {
    if (!GALLERY.length) {
      $("#galeria").hidden = true;
      return;
    }
    // Álbuns (só mostra as abas se houver mais de um)
    $("#album-tabs").innerHTML =
      GALLERY.length > 1
        ? GALLERY.map(
            (a, i) => `<button type="button" role="tab" aria-selected="${i === gal.album}" class="album-tab ${i === gal.album ? "active" : ""}" data-album="${i}">
              ${escapeHtml(a.title)} <span class="count">${a.items.length}</span></button>`
          ).join("")
        : `<strong class="album-single">${escapeHtml(GALLERY[0].title)}</strong>`;

    const album = GALLERY[gal.album];
    const nImg = album.items.filter((i) => i.type === "image").length;
    const nVid = album.items.length - nImg;
    const filters = [["all", `Tudo (${album.items.length})`], ["image", `${icon("camera")} Fotos (${nImg})`], ["video", `${icon("film")} Vídeos (${nVid})`]].filter(
      ([k]) => k === "all" || (k === "image" ? nImg : nVid)
    );
    $("#media-filter").innerHTML =
      nImg && nVid
        ? filters.map(([k, l]) => `<button type="button" class="mf-btn ${gal.filter === k ? "active" : ""}" aria-pressed="${gal.filter === k}" data-filter="${k}">${l}</button>`).join("")
        : "";

    gal.list = currentItems();
    $("#gallery").innerHTML = gal.list
      .slice(0, gal.shown)
      .map(
        (m, i) => `
      <button type="button" class="photo ${m.type === "video" ? "is-video" : ""}" data-index="${i}" aria-label="${m.type === "video" ? "Assistir vídeo" : "Ampliar foto"} ${i + 1}">
        <img loading="lazy" src="${thumb(m.id, 400)}" alt="" onerror="this.closest('.photo').classList.add('no-thumb')" />
        ${m.type === "video" ? `<span class="play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span>` : ""}
      </button>`
      )
      .join("");

    const rest = Math.max(0, gal.list.length - gal.shown);
    const more = $("#gallery-more");
    more.hidden = rest === 0;
    more.textContent = rest === 0 ? "" : `Mostrar mais ${Math.min(rest, PAGE_SIZE)} (${rest} restantes)`;
  }

  function bindGallery() {
    $("#album-tabs").addEventListener("click", (e) => {
      const b = e.target.closest("[data-album]");
      if (!b) return;
      Object.assign(gal, { album: +b.dataset.album, filter: "all", shown: PAGE_SIZE });
      renderGallery();
    });
    $("#media-filter").addEventListener("click", (e) => {
      const b = e.target.closest("[data-filter]");
      if (!b) return;
      Object.assign(gal, { filter: b.dataset.filter, shown: PAGE_SIZE });
      renderGallery();
    });
    $("#gallery-more").addEventListener("click", () => {
      gal.shown += PAGE_SIZE;
      renderGallery();
    });
    $("#gallery").addEventListener("click", (e) => {
      const p = e.target.closest(".photo");
      if (p) openMedia(+p.dataset.index);
    });

    /* ---- Visualizador de fotos e vídeos ---- */
    const dlg = $("#lightbox");
    const img = $("#lb-img");
    const vid = $("#lb-video");

    function openMedia(i) {
      const n = gal.list.length;
      gal.index = (i + n) % n;
      const m = gal.list[gal.index];
      dlg.classList.add("loading");
      $("#lb-counter").textContent = `${gal.index + 1} / ${n} · ${m.type === "video" ? "vídeo" : "foto"}`;
      $("#lb-drive").href = `https://drive.google.com/file/d/${m.id}/view`;
      if (m.type === "video") {
        img.hidden = true;
        img.removeAttribute("src");
        vid.hidden = false;
        vid.src = `https://drive.google.com/file/d/${m.id}/preview`;
      } else {
        vid.hidden = true;
        vid.src = "about:blank";
        img.hidden = false;
        img.alt = `Foto ${gal.index + 1} de ${n}`;
        img.src = thumb(m.id, 1920);
      }
      // pré-carrega a próxima foto
      const next = gal.list[(gal.index + 1) % n];
      if (next && next.type === "image") new Image().src = thumb(next.id, 1920);
      $("#lb-prev").hidden = $("#lb-next").hidden = n < 2;
      if (!dlg.open) {
        dlg.showModal();
        lockPageScroll(true);
      }
    }

    const done = () => dlg.classList.remove("loading");
    img.addEventListener("load", done);
    img.addEventListener("error", done);
    vid.addEventListener("load", () => {
      done();
      vid.focus(); // o player do Google só responde ao teclado/rolagem depois de ter foco
    });
    $("#lb-prev").addEventListener("click", () => openMedia(gal.index - 1));
    $("#lb-next").addEventListener("click", () => openMedia(gal.index + 1));
    $("#lb-close").addEventListener("click", () => dlg.close());
    dlg.addEventListener("close", () => {
      lockPageScroll(false);
      vid.src = "about:blank"; // para o vídeo ao fechar
    });
    dlg.addEventListener("click", (e) => {
      if (e.target === dlg || e.target.id === "lb-stage") dlg.close();
    });
    dlg.addEventListener("keydown", (e) => {
      if (e.key === "ArrowLeft") openMedia(gal.index - 1);
      if (e.key === "ArrowRight") openMedia(gal.index + 1);
    });

    // Deslizar no celular
    let x0 = null;
    $("#lb-stage").addEventListener("touchstart", (e) => (x0 = e.touches[0].clientX), { passive: true });
    $("#lb-stage").addEventListener(
      "touchend",
      (e) => {
        if (x0 === null) return;
        const dx = e.changedTouches[0].clientX - x0;
        if (Math.abs(dx) > 50) openMedia(gal.index + (dx < 0 ? 1 : -1));
        x0 = null;
      },
      { passive: true }
    );
  }

  /* ---------------- Glossário e documentos ---------------- */
  function renderGlossary() {
    $("#glossary").innerHTML = GLOSSARY.map((g) => `<div class="gl-item"><dt>${g.term}</dt><dd>${g.def}</dd></div>`).join("");
  }

  function renderDocs() {
    $("#docs").innerHTML = Object.entries(DOCS).map(([id, d]) => ({ ...d, id }))
      .map(
        (d) => `
      <a class="doc" href="${d.url}" target="_blank" rel="noopener" ${viewerAttrs(d)}>
        <span class="doc-type type-${normalize(d.type)}">${d.type}</span>
        <h3>${d.title}</h3>
        <p>${d.desc}</p>
        <span class="doc-date">${d.date}</span>
      </a>`
      )
      .join("");
  }

  /* ---------------- Visualizador de documentos ---------------- */
  function viewerAttrs(d) {
    if (!d.preview) return "";
    return `data-preview="${escapeHtml(d.preview)}" data-download="${escapeHtml(d.download || "")}" data-title="${escapeHtml(d.title)}" data-doc-id="${escapeHtml(d.id || "")}"`;
  }

  const viewer = $("#viewer");
  const frame = $("#viewer-frame");
  let viewerReturnHash = "";

  function openViewer({ preview, download, url, title, docId }) {
    $("#viewer-title").textContent = title;
    $("#viewer-open").href = url;
    const dl = $("#viewer-download");
    dl.hidden = !download;
    if (download) dl.href = download;
    viewer.classList.add("loading");
    frame.src = preview;
    if (!viewer.open) {
      viewer.showModal();
      lockPageScroll(true);
    }
    if (docId) {
      viewerReturnHash = location.hash.startsWith("#doc-") ? "" : location.hash;
      history.replaceState(null, "", `#doc-${docId}`);
    }
  }

  function closeViewer() {
    if (viewer.open) viewer.close();
  }

  frame.addEventListener("load", () => {
    viewer.classList.remove("loading");
    // Dá foco ao documento: o visualizador do Google só rola com a roda do mouse/teclado depois de focado
    if (viewer.open && frame.src !== "about:blank") frame.focus();
  });
  viewer.addEventListener("close", () => {
    lockPageScroll(false);
    frame.src = "about:blank";
    if (location.hash.startsWith("#doc-")) history.replaceState(null, "", viewerReturnHash || location.pathname + location.search);
  });
  $("#viewer-close").addEventListener("click", closeViewer);
  viewer.addEventListener("click", (e) => e.target === viewer && closeViewer());

  // Qualquer link com data-preview abre no visualizador (Ctrl/Cmd+clique continua abrindo no Drive)
  document.addEventListener("click", (e) => {
    const a = e.target.closest("a[data-preview]");
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    openViewer({ preview: a.dataset.preview, download: a.dataset.download, url: a.href, title: a.dataset.title, docId: a.dataset.docId });
  });

  function openDocFromHash() {
    const m = location.hash.match(/^#doc-(.+)$/);
    if (!m || !DOCS[m[1]]) return;
    const d = DOCS[m[1]];
    if (d.preview) openViewer({ ...d, docId: m[1] });
  }

  /* ---------------- Voltar ao topo ---------------- */
  const toTop = $("#to-top");
  const updateToTop = () => toTop.classList.toggle("show", window.scrollY > 600);
  window.addEventListener("scroll", updateToTop, { passive: true });
  updateToTop();
  toTop.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: SMOOTH });
    $("#search").focus({ preventScroll: true });
  });

  /* ---------------- Menu no celular e seção ativa ---------------- */
  const menuBtn = $("#menu-btn");
  const siteNav = $("#site-nav");
  function setMenu(open) {
    menuBtn.setAttribute("aria-expanded", String(open));
    document.body.classList.toggle("menu-open", open);
    menuBtn.querySelector("use").setAttribute("href", `img/icons.svg#i-${open ? "x" : "menu"}`);
    menuBtn.querySelector("span").textContent = open ? "Fechar" : "Menu";
  }
  menuBtn.addEventListener("click", () => setMenu(menuBtn.getAttribute("aria-expanded") !== "true"));
  siteNav.addEventListener("click", (e) => e.target.closest("a") && setMenu(false));
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && document.body.classList.contains("menu-open")) {
      setMenu(false);
      menuBtn.focus();
    }
  });
  document.addEventListener("click", (e) => {
    if (document.body.classList.contains("menu-open") && !e.target.closest("#site-nav, #menu-btn")) setMenu(false);
  });

  // Destaca no menu a seção que está na tela
  const navLinks = $$("#site-nav a");
  const sectionObserver = new IntersectionObserver(
    (entries) => {
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        navLinks.forEach((l) => {
          const on = l.getAttribute("href") === `#${en.target.id}`;
          l.classList.toggle("active", on);
          if (on) l.setAttribute("aria-current", "location");
          else l.removeAttribute("aria-current");
        });
      });
    },
    { rootMargin: "-45% 0px -50% 0px" }
  );
  navLinks.forEach((l) => {
    const sec = document.querySelector(l.getAttribute("href"));
    if (sec) sectionObserver.observe(sec);
  });

  /* ---------------- Init ---------------- */
  renderStatus();
  renderObra();
  renderPlaces();
  bindPlaces();
  if (D.vendorSheet) $("#vendor-sheet-link").href = `https://docs.google.com/spreadsheets/d/${D.vendorSheet}/edit`;
  renderChips();
  const initialQ = new URLSearchParams(location.search).get("q");
  if (initialQ) {
    state.query = initialQ;
    $$(".search-input").forEach((i) => (i.value = initialQ));
  }
  renderFaq();
  bindFaq();
  renderTimeline();
  renderAmenities();
  renderChecklist();
  bindChecklist();
  renderVendors();
  $("#vendor-search").addEventListener("input", (e) => renderVendors(e.target.value));
  renderGallery();
  bindGallery();
  renderGlossary();
  renderDocs();
  openFromHash();
  openDocFromHash();
  window.addEventListener("hashchange", () => {
    openFromHash();
    openDocFromHash();
  });
})();
