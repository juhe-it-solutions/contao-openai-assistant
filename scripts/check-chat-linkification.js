'use strict';
// Regression harness for public/js/ai-chat.js fmt(): re-runs the 41 documented
// linkification cases (XXX_DOCS_INTERN/20260610_url-linkification-test-suite.md)
// plus XSS/escaping and newline-in-URL cases added for the escape-then-transform
// change, plus the "shorten plain URLs" cases (module option shorten_urls,
// default ON - the documented 41 cases run with shortening OFF, i.e. the
// opt-out rendering). Run from anywhere: node scripts/check-chat-linkification.js

const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(
  path.resolve(__dirname, '../public/js/ai-chat.js'),
  'utf8'
);

const start = src.indexOf('const escapeHtml');
const end = src.indexOf('const ts =');
if (start < 0 || end < 0 || end <= start) {
  console.error('FATAL: could not locate escapeHtml/fmt block in ai-chat.js');
  process.exit(2);
}
// The block reads `wrapper` (data-shorten-urls) and `i18n` (link labels) from
// the initAiChat closure - inject stubs as function parameters.
const block = src.slice(start, end);
const makeFmt = (wrapperStub, i18nStub) =>
  new Function('wrapper', 'i18n', block + '; return fmt;')(wrapperStub, i18nStub);
const I18N = { link_label_download: 'Download', link_label_page: 'Seite aufrufen' };
// Shortening OFF (module opt-out): plain URLs keep the full URL as link text.
// The documented 41-case suite asserts exactly this rendering.
const fmt = makeFmt({ dataset: { shortenUrls: '0' } }, I18N);
// Shortening ON (the default): plain URLs render as short labels.
const fmtShort = makeFmt({ dataset: { shortenUrls: '1' } }, I18N);
// No data attribute at all (older cached template) - must behave like ON.
const fmtDefault = makeFmt({ dataset: {} }, I18N);

// Decode the entities our pipeline can emit in element CONTENT, to compare the
// rendered (DOM) text with the expected raw URL.
const decode = s => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

// Extract inner text of the first anchor.
const anchorText = html => {
  const m = html.match(/<a [^>]*>([\s\S]*?)<\/a>/);
  return m ? m[1] : null;
};

let pass = 0, fail = 0;
const failures = [];
function check(name, cond, detail) {
  if (cond) { pass++; }
  else { fail++; failures.push({ name, detail }); }
}

// ---------------------------------------------------------------- bare URLs (10)
const bareUrls = [
  'https://sub.abc.tld/de/image-kommunikation/praesentationen?file=files/assets/download-center/presentation-templates/ppt-master/26-29-054_PowerPoint-Master-II_16-9_EN.pptx&cid=11402',
  'https://example.com',
  'http://example.org/contact',
  'https://www.example.com/index.html',
  'https://downloads.example.com/files/manual.html',
  'https://assets.cdn.example.co.uk/library/item.html?download=1',
  'http://127.0.0.1:8080/status',
  'https://192.168.178.25:8443/admin/login',
  'https://example.com/search?q=openai%20assistant&filter=type%3Apdf&sort=created_at-desc&page=2',
  'https://example.com/path/report?file=a%2Fb%2Fc.html&token=abc123-_.~&redirect=https%3A%2F%2Fsub.example.com%2Fdone%3Fx%3D1%26y%3D2#section-2',
];
bareUrls.forEach((url, i) => {
  const out = fmt(url);
  check(`bare-${i + 1} href`, out.includes(`href="${url}"`), out);
  check(`bare-${i + 1} text`, decode(anchorText(out) || '') === url, out);
});

// ------------------------------------------------------- markdown mirrors (10)
bareUrls.forEach((url, i) => {
  const out = fmt(`[Herunterladen](${url})`);
  // title keeps &amp; entities - the browser decodes them for display; only
  // the href post-processing restores the literal & (href must be exact).
  const expected = `<a href="${url}" target="_blank" rel="noopener" title="${url.replace(/&/g, '&amp;')}">Herunterladen</a>`;
  check(`md-mirror-${i + 1}`, out === expected, out);
});

// ------------------------------------------- additional customer-style md (10)
const mdCases = [
  ['[Herunterladen](https://example.com/downloads/brochure.pdf?version=2026-06&cid=11402)', 'Herunterladen', 'https://example.com/downloads/brochure.pdf?version=2026-06&cid=11402'],
  ['[PowerPoint-Master laden](https://media.example.com/assets/presentation.html?file=slides%2Fmaster-16-9_EN.pptx&dl=1)', 'PowerPoint-Master laden', 'https://media.example.com/assets/presentation.html?file=slides%2Fmaster-16-9_EN.pptx&dl=1'],
  ['[Zur deutschen Seite](https://de.shop.example.com/katalog/produkt.html?sku=ABC-123&lang=de#details)', 'Zur deutschen Seite', 'https://de.shop.example.com/katalog/produkt.html?sku=ABC-123&lang=de#details'],
  ['[API Status](http://10.0.0.5:9000/health?check=db&timeout=30)', 'API Status', 'http://10.0.0.5:9000/health?check=db&timeout=30'],
  ['[Datei öffnen](www.example.org/download-center/file.html?cid=11402&file=report.pdf)', 'Datei öffnen', 'https://www.example.org/download-center/file.html?cid=11402&file=report.pdf'],
  ['[Weiterlesen](https://example.com/wiki/Function_(mathematics))', 'Weiterlesen', 'https://example.com/wiki/Function_(mathematics)'],
  ['[Spezifikation](https://docs.example.com/spec.html?section=links "Spezifikation öffnen")', 'Spezifikation', 'https://docs.example.com/spec.html?section=links'],
  ['[Datei öffnen](<https://example.com/download?file=a%2Fb%2Fc.pdf&cid=11402>)', 'Datei öffnen', 'https://example.com/download?file=a%2Fb%2Fc.pdf&cid=11402'],
  ['[Kampagne öffnen](https://example.com/campaign?utm_source=newsletter&utm_medium=email&utm_campaign=summer-2026&ref=abc.def)', 'Kampagne öffnen', 'https://example.com/campaign?utm_source=newsletter&utm_medium=email&utm_campaign=summer-2026&ref=abc.def'],
  ['Bitte [Login öffnen](https://login.example.com/callback?redirect_uri=https%3A%2F%2Fapp.example.com%2Fde%2Fstart%3Fa%3D1%26b%3D2&state=abc123#complete).', 'Login öffnen', 'https://login.example.com/callback?redirect_uri=https%3A%2F%2Fapp.example.com%2Fde%2Fstart%3Fa%3D1%26b%3D2&state=abc123#complete'],
];
mdCases.forEach(([input, text, href], i) => {
  const out = fmt(input);
  check(`md-extra-${i + 1} href`, out.includes(`href="${href}"`), out);
  check(`md-extra-${i + 1} text`, out.includes(`>${text}</a>`), out);
  check(`md-extra-${i + 1} no-wrapper`, !out.includes(']('), out);
});
// case 10: trailing period stays outside the anchor
check('md-extra-10 punctuation', fmt(mdCases[9][0]).endsWith('</a>.'), fmt(mdCases[9][0]));

// ------------------------------------------------------ final hardening (11)
{
  let out = fmt('https://example.com.br/downloads/manual.html?cid=11402');
  check('hard-1 .br domain', out.includes('href="https://example.com.br/downloads/manual.html?cid=11402"'), out);

  out = fmt('https://example.com/path/br');
  check('hard-2 path br', out.includes('href="https://example.com/path/br"'), out);

  out = fmt('https://example.com/path/a..b/file.html?x=1..2');
  check('hard-3 double dots', out.includes('href="https://example.com/path/a..b/file.html?x=1..2"'), out);

  out = fmt('<https://example.com/angle?x=1&y=2>');
  check('hard-4 angle https href', out.includes('href="https://example.com/angle?x=1&y=2"'), out);
  check('hard-4 angle https no stray brackets', !out.includes('&lt;') && !out.includes('&gt;'), out);

  out = fmt('<www.example.com/angle?x=1>');
  check('hard-5 angle www href', out.includes('href="https://www.example.com/angle?x=1"'), out);
  check('hard-5 angle www no stray brackets', !out.includes('&lt;') && !out.includes('&gt;'), out);

  out = fmt('[Brasil](https://example.com.br/downloads/manual.html?cid=11402)');
  check('hard-6 md .br', out.includes('href="https://example.com.br/downloads/manual.html?cid=11402"') && out.includes('>Brasil</a>'), out);

  out = fmt('[BR Path](https://example.com/path/br)');
  check('hard-7 md path br', out.includes('href="https://example.com/path/br"') && out.includes('>BR Path</a>'), out);

  out = fmt('[Double Dot](https://example.com/path/a..b/file.html?x=1..2)');
  check('hard-8 md double dots', out.includes('href="https://example.com/path/a..b/file.html?x=1..2"') && out.includes('>Double Dot</a>'), out);

  out = fmt('[E-Mail schreiben](mailto:office@example.com)');
  check('hard-9 md mailto', out.includes('href="mailto:office@example.com"') && out.includes('>E-Mail schreiben</a>'), out);

  out = fmt('[Jetzt anrufen](tel:+43123456789)');
  check('hard-10 md tel', out.includes('href="tel:+43123456789"') && out.includes('>Jetzt anrufen</a>'), out);

  out = fmt('mailto:office@example.com');
  check('hard-11a bare mailto', out.includes('href="mailto:office@example.com"'), out);
  out = fmt('tel:+43123456789');
  check('hard-11b bare tel', out.includes('href="tel:+43123456789"'), out);
}

// -------------------------------------------------- XSS / escaping cases (new)
{
  let out = fmt('<img src=x onerror=alert(1)>');
  check('xss-1 img', !/<img/i.test(out), out);

  out = fmt('<script>alert(1)</script>');
  check('xss-2 script', !/<script/i.test(out), out);

  out = fmt('<svg onload=alert(1)>');
  check('xss-3 svg', !/<svg/i.test(out), out);

  out = fmt('Click <b onmouseover=alert(1)>here</b>');
  check('xss-4 inline tag', !/<b\b/i.test(out) && out.includes('&lt;b'), out);

  out = fmt('[x](javascript:alert(1))');
  check('xss-5 javascript scheme not linked', !out.includes('<a '), out);

  out = fmt('a < b > c');
  check('esc-1 lone brackets', out.includes('a &lt; b &gt; c'), out);

  out = fmt('Tom & Jerry');
  check('esc-2 ampersand', out === 'Tom &amp; Jerry', out);

  out = fmt('"quoted" and it\'s fine');
  check('esc-3 quotes literal', out === '"quoted" and it\'s fine', out);

  out = fmt('**bold** and *em* and `code`');
  check('fmt-1 markdown styles', out === '<strong>bold</strong> and <em>em</em> and <code>code</code>', out);

  out = fmt('line1\nline2');
  check('fmt-2 newline to br', out === 'line1<br>line2', out);

  out = fmt('Answer【4:0†source】done');
  check('fmt-3 citation stripped', out === 'Answerdone', out);

  // Literal ">" directly after a URL is stripped from the visible output (pre-change behavior).
  out = fmt('https://example.com>');
  check('fmt-4 trailing bracket after url', out.includes('href="https://example.com"') && !out.includes('&gt;'), out);

  // "<" must terminate a URL like it did before escaping.
  out = fmt('see https://example.com/page<next');
  check('fmt-5 bracket terminates url', out.includes('href="https://example.com/page"') && out.includes('&lt;next'), out);

  // URL split by a newline at a breakpoint char: the <br> is repaired out of the href.
  out = fmt('https://example.com/pfad?\nfoo=bar&baz=1');
  check('fmt-6 br repair in url', out.includes('href="https://example.com/pfad?foo=bar&baz=1"'), out);

  // Newline after "&": the & arrives entity-escaped ("&amp;<br>"), which the
  // breakpoint alternative must accept too (regressed with escape-then-transform).
  out = fmt('https://example.com/p?foo=1&\nbar=2');
  check('fmt-6b br repair after &', out.includes('href="https://example.com/p?foo=1&bar=2"'), out);
  out = fmt('www.example.com/p?foo=1&\nbar=2');
  check('fmt-6c br repair after & (www)', out.includes('href="https://www.example.com/p?foo=1&bar=2"'), out);
  out = fmtShort('https://example.com/files/a.pdf?foo=1&\nbar=2');
  check('fmt-6d br repair after & (shortened)', out.includes('href="https://example.com/files/a.pdf?foo=1&bar=2"') && anchorText(out) === 'Download', out);

  // Newlines around mailto/tel/www lines must NOT leak <br> into hrefs ("...br..." corruption).
  out = fmt('Kontakt:\nmailto:office@example.com\ntel:+43123456789\nwww.example.com/kontakt?ref=chat');
  check('fmt-7 multiline mailto', out.includes('href="mailto:office@example.com"'), out);
  check('fmt-7 multiline tel', out.includes('href="tel:+43123456789"'), out);
  check('fmt-7 multiline www', out.includes('href="https://www.example.com/kontakt?ref=chat"'), out);
  check('fmt-7 no br corruption', !/href="[^"]*br[^"]*combr/.test(out) && !out.includes('combr'), out);

  // Newline directly before a markdown closing paren: URL must not absorb the <br>.
  out = fmt('[text](https://example.com/pfad\n)');
  check('fmt-8 md newline paren', out.includes('href="https://example.com/pfad"') && !out.includes('pfadbr'), out);

  out = fmt('mailto:a@b.com\nnächste Zeile');
  check('fmt-9 mailto newline', out.includes('href="mailto:a@b.com"') && !out.includes('combr'), out);

  // Percent encoding and umlauts survive untouched in href and text.
  out = fmt('https://example.com/download?file=100%25-rabatt.pdf&stadt=k%C3%B6ln');
  check('fmt-10 percent encoding', out.includes('href="https://example.com/download?file=100%25-rabatt.pdf&stadt=k%C3%B6ln"'), out);
}

// ------------------------- shortened plain URLs (shorten_urls ON, default) ---
{
  // Download extension in the path -> localized "Download" label; full URL
  // stays in href and title, aria-label carries the target hostname.
  let out = fmtShort('https://example.com/files/manual.pdf');
  check('short-1 pdf label', anchorText(out) === 'Download', out);
  check('short-1 href', out.includes('href="https://example.com/files/manual.pdf"'), out);
  check('short-1 title', out.includes('title="https://example.com/files/manual.pdf"'), out);
  check('short-1 aria', out.includes('aria-label="Download, example.com"'), out);

  // No download extension -> localized page label.
  out = fmtShort('https://example.com/kontakt');
  check('short-2 page label', anchorText(out) === 'Seite aufrufen', out);
  check('short-2 aria', out.includes('aria-label="Seite aufrufen, example.com"'), out);

  // Extension detection uses the PATH only - ?file=x.pdf in the query does not
  // make the link a download.
  out = fmtShort('https://example.com/download-center?file=report.pdf');
  check('short-3 query ext ignored', anchorText(out) === 'Seite aufrufen', out);
  check('short-3 href intact', out.includes('href="https://example.com/download-center?file=report.pdf"'), out);

  // Customer case: pptx path with query params.
  out = fmtShort('https://sub.abc.tld/pfad/26-29-054_PowerPoint-Master-II_16-9_EN.pptx?cid=11402');
  check('short-4 pptx label', anchorText(out) === 'Download', out);
  check('short-4 href intact', out.includes('href="https://sub.abc.tld/pfad/26-29-054_PowerPoint-Master-II_16-9_EN.pptx?cid=11402"'), out);

  // www URLs get the https:// prefix and are shortened too.
  out = fmtShort('www.example.org/broschuere.pdf');
  check('short-5 www', out.includes('href="https://www.example.org/broschuere.pdf"') && anchorText(out) === 'Download', out);

  // Angle-bracket URLs.
  out = fmtShort('<https://example.com/angle?x=1&y=2>');
  check('short-6 angle', out.includes('href="https://example.com/angle?x=1&y=2"') && anchorText(out) === 'Seite aufrufen', out);

  // Markdown links always keep their model-provided text, even when ON.
  out = fmtShort('[Herunterladen](https://example.com/files/manual.pdf)');
  check('short-7 md text wins', out.includes('>Herunterladen</a>') && !out.includes('>Download</a>'), out);

  // Sentence punctuation stays outside the anchor.
  out = fmtShort('Hier: https://example.com/files/manual.pdf.');
  check('short-8 trailing dot', anchorText(out) === 'Download' && out.endsWith('</a>.'), out);
  out = fmtShort('Siehe https://example.com/marke.');
  check('short-9 page trailing dot', anchorText(out) === 'Seite aufrufen' && out.endsWith('</a>.'), out);

  // A list of downloads with DIFFERENT targets must NOT be deduplicated even
  // though all three visible labels read "Download" (dedup matches the whole
  // anchor including href).
  out = fmtShort('1. https://example.com/a.pdf\n2. https://example.com/b.pdf\n3. https://example.com/c.pdf');
  check('short-10 list keeps all', (out.match(/<a /g) || []).length === 3, out);

  // Missing data attribute (older cached template) defaults to ON.
  out = fmtDefault('https://example.com/files/manual.pdf');
  check('short-11 default on', anchorText(out) === 'Download', out);

  // Empty i18n map falls back to built-in German labels.
  const fmtNoI18n = makeFmt({ dataset: { shortenUrls: '1' } }, {});
  out = fmtNoI18n('https://example.com/x.pdf');
  check('short-12 i18n fallback download', anchorText(out) === 'Download', out);
  out = fmtNoI18n('https://example.com/seite');
  check('short-12 i18n fallback page', anchorText(out) === 'Seite aufrufen', out);

  // href integrity for all documented bare URLs in ON mode: full URL in href,
  // visible text is always one of the two labels.
  bareUrls.forEach((url, i) => {
    const o = fmtShort(url);
    check(`short-bare-${i + 1} href`, o.includes(`href="${url}"`), o);
    const t = anchorText(o);
    check(`short-bare-${i + 1} label`, t === 'Download' || t === 'Seite aufrufen', o);
  });
}

// ------------------- long combined-special-characters URL (documented case 42)
// One URL exercising every URL feature the formatter supports at once:
// multi-level subdomains, two-part TLD, port, balanced parentheses in path/
// query/fragment, ~ and , in path segments, percent-encoded slashes/umlauts/
// spaces/%/+/comma, sub-delims (; ! $ * @ =), a fully encoded nested URL,
// an empty parameter, and a .pptx path (-> Download label when shortening).
{
  const LONG_URL = 'https://ai-chat.download-center.kunden-portal.beispiel-firma.example.co.at:8443/de-AT/medien(2026)/praesentationen/ppt~master_v2.1,final/26-29-054_PowerPoint-Master-II_16-9_DE.pptx?file=files%2Fassets%2Fsprache%2FStyle-Guide_Language-(EN).pdf&cid=11402&version=2026-07%2Bfinal&utm_source=newsletter&utm_medium=email&utm_campaign=sommer_2026;special&preis=1.299%2C90&rabatt=15%25&stadt=k%C3%B6ln&stra%C3%9Fe=hauptstra%C3%9Fe&q=openai%20assistant%20contao&list=a,b,c&flags=x!y$z*w&redirect_uri=https%3A%2F%2Fapp.example.com%2Fde%2Fstart%3Fa%3D1%26b%3D2%23done&token=AbC123-_.~&session=xyz@host&sort=created_at-desc&page=42&empty=&debug=true#abschnitt-2.1_(technische-details)-ende';

  // Bare, shortening OFF: full URL in href AND visible text.
  let out = fmt(LONG_URL);
  check('long-1 off href', out.includes(`href="${LONG_URL}"`), out);
  check('long-1 off text', decode(anchorText(out) || '') === LONG_URL, out);

  // Bare, shortening ON: full URL in href and title, Download label (.pptx path).
  out = fmtShort('Hier: ' + LONG_URL + '.');
  check('long-2 on href', out.includes(`href="${LONG_URL}"`), out);
  check('long-2 on label', anchorText(out) === 'Download', out);
  check('long-2 on title', out.includes('title="' + LONG_URL.replace(/&/g, '&amp;') + '"'), out);
  check('long-2 on trailing dot', out.endsWith('</a>.'), out);

  // Markdown: model text always wins.
  out = fmtShort('[PowerPoint-Master (DE)](' + LONG_URL + ')');
  check('long-3 md href', out.includes(`href="${LONG_URL}"`), out);
  check('long-3 md text', out.includes('>PowerPoint-Master (DE)</a>'), out);

  // Newline-wrapped at & (single and double wrap): href repaired to the exact URL.
  out = fmtShort(LONG_URL.replace('&cid=11402', '&\ncid=11402'));
  check('long-4 wrap at &', out.includes(`href="${LONG_URL}"`), out);
  out = fmt(LONG_URL.replace('&stadt=', '&\nstadt=').replace('&page=', '&\npage='));
  check('long-5 double wrap at &', out.includes(`href="${LONG_URL}"`), out);

  // Ampersand + newline in plain text must never start or extend a link.
  out = fmtShort('Fischer &\nSöhne GmbH');
  check('long-6 plain text & newline', !out.includes('<a ') && out === 'Fischer &amp;<br>Söhne GmbH', out);

  // ---- Markdown link whose TEXT is itself the URL ("[<url>](<url>)") - the
  // form models echo when the vector-store document stores links that way
  // (observed 2026-07-15). With shortening ON the generic label applies; real
  // descriptive text (long-3 above) is never replaced.
  out = fmtShort(`Die Firmen-Webseite ist erreichbar unter:\n[${LONG_URL}](${LONG_URL}).`);
  check('url-as-text-1 on label', anchorText(out) === 'Download', out);
  check('url-as-text-1 on href', out.includes(`href="${LONG_URL}"`), out);
  check('url-as-text-1 on title', out.includes('title="' + LONG_URL.replace(/&/g, '&amp;') + '"'), out);
  check('url-as-text-1 no wrapper', !out.includes('](') && !out.includes('[<a'), out);
  check('url-as-text-1 trailing dot', out.endsWith('</a>.'), out);

  // Shortening OFF keeps the model text (the full URL) as before.
  out = fmt(`[${LONG_URL}](${LONG_URL})`);
  check('url-as-text-2 off text', decode(anchorText(out) || '') === LONG_URL, out);
  check('url-as-text-2 off href', out.includes(`href="${LONG_URL}"`), out);

  // Page-style target gets the page label (customer helpline case).
  const HELP_URL = 'https://www.dasisteintest.at/miteinersehrlangenurl?parameter1=33%parameter2=34';
  out = fmtShort(`Die Helpline ist erreichbar unter:\n[${HELP_URL}](${HELP_URL}).`);
  check('url-as-text-3 page label', anchorText(out) === 'Seite aufrufen', out);
  check('url-as-text-3 href', out.includes(`href="${HELP_URL}"`), out);

  // ---- Emphasis/code delimiters inside URLs: a single "*" in each of two
  // URLs in one message ("?flags=x!y$z*w") must not pair up into <em> and
  // shred the URLs before linkification (the "[Download<em>..." bug).
  out = fmtShort(`Erste: ${LONG_URL}\nZweite: ${LONG_URL}`);
  check('em-in-url-1 no em', !out.includes('<em>'), out);
  check('em-in-url-1 two anchors', (out.match(/<a /g) || []).length === 2, out);
  check('em-in-url-1 hrefs exact', out.split(`href="${LONG_URL}"`).length - 1 === 2, out);

  // Mid-word asterisks in plain text stay literal too.
  out = fmt('Rechnung: 5*3 und 7*2 Stück');
  check('em-in-url-2 mid-word literal', !out.includes('<em>') && out.includes('5*3 und 7*2'), out);

  // ---- Unresolved Contao basic entities ("[&]" instead of "&") inside a bare
  // URL: Contao >= 5.0 no longer converts them at render time, so pages (and
  // vector stores synced before the index-time decoding fix) can contain them
  // literally. The whole URL must still be linkified as ONE token with the
  // [&] sequences passed through to the href, and shortening must apply.
  const LONG_URL_BE = LONG_URL.replace(/&/g, '[&]');
  out = fmtShort(`Erreichbar unter: ${LONG_URL_BE}.`);
  check('basic-entity-1 on label', anchorText(out) === 'Download', out);
  check('basic-entity-1 on href', out.includes(`href="${LONG_URL_BE}"`), out);
  check('basic-entity-1 trailing dot', out.endsWith('</a>.'), out);
  out = fmt(LONG_URL_BE);
  check('basic-entity-2 off href', out.includes(`href="${LONG_URL_BE}"`), out);

  // ---- Malformed Markdown echoes (observed on the Contao 5.7 test install,
  // "[Download." with a stray "]" in the href): models re-emit vector-store
  // links in broken shapes. Every variant must collapse to ONE clean anchor
  // with the exact URL in href - no wrapper brackets, no bracket in the label,
  // no "]"/")"/"." leaking into the href.
  const malformed = [
    `[Download](${LONG_URL}]).`,                          // stray ] inside dest
    `[[Download](${LONG_URL})].`,                         // whole link double-wrapped
    `[[Download](${LONG_URL}]).`,                         // double-wrap + stray ] (screenshot case)
    `[${LONG_URL}](${LONG_URL}]).`,                       // url-as-text + stray ]
    `[${LONG_URL}].`,                                     // bracket-wrapped bare URL
    `[${LONG_URL}](${LONG_URL.replace('&q=', '&\nq=')}).`, // url-as-text, newline in dest (screenshot case)
    `[Download](${LONG_URL.replace('&q=', '&\nq=')}).`,   // label + newline in dest
    `[Download][${LONG_URL}].`,                           // reference-style
    `[Download](${LONG_URL}.).`,                          // trailing dot inside dest
  ];
  malformed.forEach((input, i) => {
    const o = fmtShort(`Erreichbar unter:\n${input}`);
    check(`md-malformed-${i + 1} label`, anchorText(o) === 'Download', o);
    check(`md-malformed-${i + 1} href exact`, o.includes(`href="${LONG_URL}"`), o);
    check(`md-malformed-${i + 1} one anchor`, (o.match(/<a /g) || []).length === 1, o);
    check(`md-malformed-${i + 1} no wrapper`, !/[\][]/.test(o.replace(/&\w+;|\[&\]/g, '')), o);
  });
  // The exact live model output captured 2026-07-15 23:24 on the Contao 5.7
  // install (raw /ai-chat/send JSON): ASCII "[" opened, CJK U+3011 "】" closed.
  // The lone CJK bracket must not leak into the href, and the ASCII "[" must
  // not remain as visible text.
  {
    const o = fmtShort(`Die Firmenwebseite ist erreichbar unter: [${LONG_URL}】.`);
    check('cjk-bracket-1 label', anchorText(o) === 'Download', o);
    check('cjk-bracket-1 href exact', o.includes(`href="${LONG_URL}"`), o);
    check('cjk-bracket-1 no brackets', !/[\][【】]/.test(o), o);
    check('cjk-bracket-1 trailing dot', o.endsWith('</a>.'), o);
  }
  // Complete 【...】 citation markers are still stripped entirely.
  check('cjk-bracket-2 citation strip', fmt('Siehe Quelle【4:0†source】.') === 'Siehe Quelle.', fmt('Siehe Quelle【4:0†source】.'));

  // Descriptive labels survive the cleanup (failed Markdown, newline in dest).
  {
    const o = fmtShort(`[Zum Formular](${LONG_URL.replace('&q=', '&\nq=')})`);
    check('md-malformed-label kept', anchorText(o) === 'Zum Formular', o);
    check('md-malformed-label href', o.includes(`href="${LONG_URL}"`), o);
  }

  // Regular emphasis/strong/code at word boundaries keep working.
  out = fmt('Das ist **wichtig** und *kursiv* mit `code` dazu.');
  check('em-boundary-1 strong', out.includes('<strong>wichtig</strong>'), out);
  check('em-boundary-1 em', out.includes('<em>kursiv</em>'), out);
  check('em-boundary-1 code', out.includes('<code>code</code>'), out);
  out = fmt('*Am Zeilenanfang* geht es auch (**auch in Klammern**).');
  check('em-boundary-2 start', out.includes('<em>Am Zeilenanfang</em>'), out);
  check('em-boundary-2 paren', out.includes('(<strong>auch in Klammern</strong>)'), out);
}

// ------------------------------------------- 2026-07-16 analysis fixes (P1-P5)
{
  // P1: full CJK bracket wrap around a URL must unwrap to a link, not vanish.
  {
    const o = fmtShort('【https://example.com/a.pdf】');
    check('cjk-wrap-1 label', anchorText(o) === 'Download', o);
    check('cjk-wrap-1 href exact', o.includes('href="https://example.com/a.pdf"'), o);
    check('cjk-wrap-1 no brackets', !/[\][【】]/.test(o), o);
  }
  {
    const o = fmtShort('Die Seite: 【https://example.com】.');
    check('cjk-wrap-2 label', anchorText(o) === 'Seite aufrufen', o);
    check('cjk-wrap-2 href exact', o.includes('href="https://example.com"'), o);
    check('cjk-wrap-2 trailing dot', o.endsWith('</a>.'), o);
  }
  // Guard: real file-search citations are still stripped entirely.
  check('cjk-wrap-3 citation strip', fmtShort('Siehe【4:0†source】.') === 'Siehe.', fmtShort('Siehe【4:0†source】.'));
  // Text glued directly after the closing bracket must not enter the href.
  {
    const o = fmtShort('Siehe【https://example.com】weiter');
    check('cjk-wrap-4 href exact', o.includes('href="https://example.com"'), o);
    check('cjk-wrap-4 text kept', o.endsWith('weiter') && o.includes('</a>'), o);
  }

  // P3: repeated identical Markdown links / bare URLs must all survive.
  {
    const o = fmt('[A](https://example.com/a) then [A](https://example.com/a)');
    check('dedup-1 two anchors', (o.match(/<a /g) || []).length === 2, o);
    check('dedup-1 both hrefs', o.split('href="https://example.com/a"').length - 1 === 2, o);
  }
  {
    const o = fmtShort('https://example.com/a.pdf and also https://example.com/a.pdf');
    check('dedup-2 two anchors', (o.match(/<a /g) || []).length === 2, o);
  }

  // P2: phone autolink needs a "+" prefix or a phone keyword in front of it.
  const phoneNegatives = [
    ['phone-neg-invoice', 'Rechnung 202407151234'],
    ['phone-neg-isbn', 'ISBN 978-3-16-148410-0'],
    ['phone-neg-sku', 'SKU 123-456-7890'],
    ['phone-neg-order', 'Order #1234567 done'],
    ['phone-neg-datetime', 'Am 2024-07-15 12:34:56 erstellt'],
    ['phone-neg-bare', 'Die Zahl 030 12345678 allein'],
  ];
  phoneNegatives.forEach(([name, input]) => {
    const o = fmt(input);
    check(name, !o.includes('href="tel:'), o);
  });
  const phonePositives = [
    ['phone-pos-plus', '+49 123 4567890', 'tel:+491234567890'],
    ['phone-pos-keyword-de', 'Rufen Sie an: 030 12345678', 'tel:03012345678'],
    // "Tel: ..." is caught by the earlier explicit-tel pass, which keeps the
    // original scheme case ("Tel:") - the tel scheme is case-insensitive.
    ['phone-pos-tel-label', 'Tel.: 030 12345678', 'tel:03012345678'],
    ['phone-pos-telefon', 'Telefon 0664 1234567', 'tel:06641234567'],
    ['phone-pos-explicit', 'tel:+43123456789', 'tel:+43123456789'],
  ];
  phonePositives.forEach(([name, input, href]) => {
    const o = fmt(input);
    check(name, o.includes(`href="${href}"`), o);
  });

  // P4: userinfo (user:pass@) must never reach title or aria-label; href is
  // kept verbatim (product decision 2026-07-16).
  {
    const o = fmtShort('https://user:secret@example.com/file.pdf');
    check('cred-1 href intact', o.includes('href="https://user:secret@example.com/file.pdf"'), o);
    check('cred-1 title clean', /title="[^"]*"/.test(o) && !/title="[^"]*secret/.test(o), o);
    check('cred-1 aria clean', /aria-label="[^"]*"/.test(o) && !/aria-label="[^"]*secret/.test(o), o);
    check('cred-1 aria host', o.includes('aria-label="Download, example.com"'), o);
  }

  // Descriptive Markdown links carry the full URL as a hover tooltip (title),
  // in both shortening modes; tel:/mailto: destinations get no title.
  {
    const url = 'https://www.example.at/hilfe?parameter1=33&parameter2=34';
    const titleUrl = url.replace(/&/g, '&amp;');
    const oOn = fmtShort(`[Helpline](${url})`);
    const oOff = fmt(`[Helpline](${url})`);
    check('md-title-1 on', oOn.includes(`title="${titleUrl}"`) && oOn.includes('>Helpline</a>'), oOn);
    check('md-title-1 off', oOff.includes(`title="${titleUrl}"`) && oOff.includes('>Helpline</a>'), oOff);
  }
  {
    const o = fmt('[Jetzt anrufen](tel:+43123456789)');
    check('md-title-2 no tel title', !o.includes('title='), o);
  }
  {
    const o = fmt('[Mail senden](mailto:office@example.com)');
    check('md-title-3 no mailto title', !o.includes('title='), o);
  }
  // Credentials stay out of the Markdown tooltip too.
  {
    const o = fmt('[Portal](https://user:secret@example.com/x)');
    check('md-title-4 cred clean', o.includes('title="https://example.com/x"'), o);
  }

  // P5: non-ASCII wrappers (corner brackets, fullwidth parens, ellipsis) must
  // not leak into the href; they may remain as text outside the anchor.
  {
    const o = fmtShort('「https://example.com/a.pdf」');
    check('wrap-cjk-1 href exact', o.includes('href="https://example.com/a.pdf"'), o);
  }
  {
    const o = fmtShort('（https://example.com/a.pdf）');
    check('wrap-fw-1 href exact', o.includes('href="https://example.com/a.pdf"'), o);
  }
  {
    const o = fmtShort('https://example.com/page…');
    check('ellipsis-1 href exact', o.includes('href="https://example.com/page"'), o);
  }
  // Balanced ASCII wiki parens keep working after the peel extension.
  {
    const o = fmt('https://en.wikipedia.org/wiki/Function_(mathematics)');
    check('wrap-guard-wiki', o.includes('href="https://en.wikipedia.org/wiki/Function_(mathematics)"'), o);
  }
}

console.log(`PASS: ${pass}  FAIL: ${fail}`);
for (const f of failures) {
  console.log(`\n--- FAIL ${f.name}\n${f.detail}`);
}
process.exit(fail === 0 ? 0 : 1);
