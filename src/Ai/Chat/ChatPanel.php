<?php

namespace ApiGoat\Ai\Chat;

/**
 * The on-demand "Ask AI" widget: a launcher button and a right-side panel
 * (hidden until clicked) holding the message list, a textarea + Send,
 * "New chat", per-answer source chips and a "local model · <model>" footer.
 *
 * Self-contained: html() returns the markup, a scoped <style> that reads the
 * theme's --color* custom properties (so every theme, dark ones included,
 * styles it), and an idempotent inline <script>. Because a fragment loaded
 * over XHR does not execute inline scripts, js() returns the same script
 * body for the caller to append to its onReadyJs — running both is safe,
 * every bind is guarded on a data attribute.
 *
 * Client contract: POST {endpoint} with JSON {message} or {reset:true};
 * the endpoint answers JSON {answer, sources:[{id,label,href?}], usage,
 * model} or {error} with a non-2xx status. The CSRF header rides on the
 * app's wrapped window.fetch; the widget also sets it itself from
 * gcCore.csrfToken() when that helper exists, so it works either way.
 * No inline event handlers, no jQuery, no native alert (errors go through
 * the app's alertb() when present, else an inline line in the panel).
 */
final class ChatPanel
{
    /**
     * @param array<string,mixed> $opts
     *   endpoint    (required) absolute URL of the chat action
     *   label       launcher text (default "Ask AI")
     *   title       panel title (default = label)
     *   model       model name for the footer ('' hides it)
     *   placeholder textarea placeholder
     *   intro       first line shown in an empty conversation
     *   turns       prior turns [{q, a, sources}] to pre-render
     *   id          DOM id of the root (default gcAiChat)
     *   labels      overrides for the button/status strings
     */
    public static function html(array $opts): string
    {
        $endpoint = \trim((string) ($opts['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new \InvalidArgumentException('ChatPanel::html: endpoint is required');
        }
        $id     = \preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', (string) ($opts['id'] ?? '')) ? (string) $opts['id'] : 'gcAiChat';
        $label  = (string) ($opts['label'] ?? 'Ask AI');
        $title  = (string) ($opts['title'] ?? $label);
        $model  = \trim((string) ($opts['model'] ?? ''));
        $labels = \array_merge([
            'send'     => 'Send',
            'sending'  => 'Thinking…',
            'newChat'  => 'New chat',
            'close'    => 'Close',
            'sources'  => 'Sources',
            'failed'   => 'The assistant could not answer. Please try again.',
            'errTitle' => 'AI',
            'localModel' => 'local model',
        ], \is_array($opts['labels'] ?? null) ? $opts['labels'] : []);
        $placeholder = (string) ($opts['placeholder'] ?? 'Ask a question… (Enter to send, Shift+Enter for a new line)');
        $intro = (string) ($opts['intro'] ?? 'Ask anything about your data. Answers cite the records they come from.');

        $cfg = \json_encode([
            'endpoint' => $endpoint,
            'labels'   => $labels,
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);

        $e = static fn ($s): string => \htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $turnsHtml = '';
        foreach (\is_array($opts['turns'] ?? null) ? $opts['turns'] : [] as $t) {
            if (!\is_array($t)) {
                continue;
            }
            $turnsHtml .= '<div class="gc-aichat-msg is-user">' . $e($t['q'] ?? '') . '</div>';
            $turnsHtml .= '<div class="gc-aichat-msg is-bot">' . $e($t['a'] ?? '') . self::sourcesHtml(\is_array($t['sources'] ?? null) ? $t['sources'] : [], $labels['sources']) . '</div>';
        }

        $foot = $model !== ''
            ? '<div class="gc-aichat-foot">' . $e($labels['localModel']) . ' · <code>' . $e($model) . '</code></div>'
            : '';

        return '<div class="gc-aichat" id="' . $e($id) . '" data-gc-ai-chat="1" data-gc-ai-cfg="' . $e($cfg) . '">'
            . '<button type="button" class="gc-aichat-launch" data-gc-ai-open="1" aria-haspopup="dialog" aria-expanded="false">'
            . '<span class="gc-aichat-spark" aria-hidden="true">✦</span> ' . $e($label) . '</button>'
            . '<div class="gc-aichat-backdrop" data-gc-ai-close="1" hidden></div>'
            . '<div class="gc-aichat-drawer" role="dialog" aria-label="' . $e($title) . '" hidden>'
            . '<div class="gc-aichat-head"><span class="gc-aichat-title">' . $e($title) . '</span>'
            . '<span class="gc-aichat-actions">'
            . '<button type="button" class="gc-aichat-btn is-ghost" data-gc-ai-new="1">' . $e($labels['newChat']) . '</button>'
            . '<button type="button" class="gc-aichat-btn is-ghost gc-aichat-x" data-gc-ai-close="1" aria-label="' . $e($labels['close']) . '">×</button>'
            . '</span></div>'
            . '<div class="gc-aichat-msgs" data-gc-ai-msgs="1">'
            . '<div class="gc-aichat-intro" data-gc-ai-intro="1">' . $e($intro) . '</div>' . $turnsHtml
            . '</div>'
            . '<form class="gc-aichat-form" data-gc-ai-form="1">'
            . '<textarea class="gc-aichat-input" rows="2" placeholder="' . $e($placeholder) . '" maxlength="2000" data-gc-ai-input="1"></textarea>'
            . '<button type="submit" class="gc-aichat-btn is-primary" data-gc-ai-send="1">' . $e($labels['send']) . '</button>'
            . '</form>'
            . $foot
            . '</div></div>'
            . self::style()
            . '<script>' . self::js() . '</script>';
    }

    /**
     * @param array<int,array{id?:string,label:string,href?:string}> $sources
     */
    public static function sourcesHtml(array $sources, string $heading = 'Sources'): string
    {
        if ($sources === []) {
            return '';
        }
        $e = static fn ($s): string => \htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $h = '<div class="gc-aichat-srcs"><span class="gc-aichat-srcs-h">' . $e($heading) . ':</span>';
        foreach ($sources as $s) {
            if (!\is_array($s) || !isset($s['label'])) {
                continue;
            }
            $href = isset($s['href']) && \is_string($s['href']) && $s['href'] !== '' ? $s['href'] : null;
            $h .= $href !== null
                ? '<a class="gc-aichat-src" href="' . $e($href) . '">' . $e($s['label']) . '</a>'
                : '<span class="gc-aichat-src">' . $e($s['label']) . '</span>';
        }

        return $h . '</div>';
    }

    /** The widget's script body — idempotent; safe in an inline <script> AND in onReadyJs. */
    public static function js(): string
    {
        return <<<'JS'
(function(){
  if (!window.GcAiChatWidget) {
    window.GcAiChatWidget = {
      bindAll: function (scope) {
        var roots = (scope || document).querySelectorAll('[data-gc-ai-chat]');
        for (var i = 0; i < roots.length; i++) { window.GcAiChatWidget.bind(roots[i]); }
      },
      bind: function (root) {
        if (!root || root.getAttribute('data-gc-ai-bound') === '1') { return; }
        root.setAttribute('data-gc-ai-bound', '1');
        var cfg; try { cfg = JSON.parse(root.getAttribute('data-gc-ai-cfg') || '{}'); } catch (e) { cfg = {}; }
        var L = cfg.labels || {};
        var q = function (sel) { return root.querySelector(sel); };
        var launch = q('[data-gc-ai-open]'), drawer = q('.gc-aichat-drawer'), backdrop = q('.gc-aichat-backdrop');
        var msgs = q('[data-gc-ai-msgs]'), form = q('[data-gc-ai-form]'), input = q('[data-gc-ai-input]'), send = q('[data-gc-ai-send]');
        var intro = q('[data-gc-ai-intro]');
        var busy = false;
        function open() { drawer.hidden = false; backdrop.hidden = false; launch.setAttribute('aria-expanded', 'true'); root.classList.add('is-open'); setTimeout(function () { input.focus(); scroll(); }, 30); }
        function close() { drawer.hidden = true; backdrop.hidden = true; launch.setAttribute('aria-expanded', 'false'); root.classList.remove('is-open'); }
        function scroll() { msgs.scrollTop = msgs.scrollHeight; }
        function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text != null) { n.textContent = text; } return n; }
        function fail(msg) {
          if (typeof window.alertb === 'function') { window.alertb(L.errTitle || 'AI', msg); }
          else { var n = el('div', 'gc-aichat-msg is-error', msg); msgs.appendChild(n); scroll(); }
        }
        function sources(list) {
          if (!list || !list.length) { return null; }
          var wrap = el('div', 'gc-aichat-srcs'); wrap.appendChild(el('span', 'gc-aichat-srcs-h', (L.sources || 'Sources') + ':'));
          for (var i = 0; i < list.length; i++) {
            var s = list[i] || {}; var chip;
            if (s.href) { chip = el('a', 'gc-aichat-src', s.label || s.id || ''); chip.href = s.href; }
            else { chip = el('span', 'gc-aichat-src', s.label || s.id || ''); }
            wrap.appendChild(chip);
          }
          return wrap;
        }
        function headers() {
          var h = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
          try { if (window.gcCore && typeof window.gcCore.csrfToken === 'function') { var t = window.gcCore.csrfToken(); if (t) { h['X-Csrf-Token'] = t; } } } catch (e) {}
          return h;
        }
        function post(body) {
          return fetch(cfg.endpoint, { method: 'POST', credentials: 'same-origin', headers: headers(), body: JSON.stringify(body) })
            .then(function (r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (e) {} return { ok: r.ok, status: r.status, json: j }; }); });
        }
        function setBusy(b) {
          busy = b; send.disabled = b; input.disabled = b; send.textContent = b ? (L.sending || 'Thinking…') : (L.send || 'Send');
          var sp = q('[data-gc-ai-spin]');
          if (b && !sp) { sp = el('div', 'gc-aichat-msg is-bot is-spin'); sp.setAttribute('data-gc-ai-spin', '1'); sp.innerHTML = '<span></span><span></span><span></span>'; msgs.appendChild(sp); scroll(); }
          if (!b && sp) { sp.parentNode.removeChild(sp); }
        }
        function ask() {
          var text = (input.value || '').trim();
          if (!text || busy) { return; }
          if (intro) { intro.hidden = true; }
          msgs.appendChild(el('div', 'gc-aichat-msg is-user', text)); input.value = ''; scroll();
          setBusy(true);
          post({ message: text }).then(function (res) {
            setBusy(false);
            var j = res.json || {};
            if (!res.ok || j.error || typeof j.answer !== 'string') { fail(j.error || j.message || (L.failed || 'The assistant could not answer.')); return; }
            var bot = el('div', 'gc-aichat-msg is-bot', j.answer);
            var src = sources(j.sources); if (src) { bot.appendChild(src); }
            msgs.appendChild(bot); scroll();
          }).catch(function () { setBusy(false); fail(L.failed || 'The assistant could not answer.'); });
        }
        launch.addEventListener('click', function (ev) { ev.preventDefault(); if (drawer.hidden) { open(); } else { close(); } });
        var closers = root.querySelectorAll('[data-gc-ai-close]');
        for (var c = 0; c < closers.length; c++) { closers[c].addEventListener('click', function (ev) { ev.preventDefault(); close(); }); }
        q('[data-gc-ai-new]').addEventListener('click', function (ev) {
          ev.preventDefault(); if (busy) { return; }
          post({ reset: true }).catch(function () {});
          var nodes = msgs.querySelectorAll('.gc-aichat-msg'); for (var i = 0; i < nodes.length; i++) { nodes[i].parentNode.removeChild(nodes[i]); }
          if (intro) { intro.hidden = false; }
          input.focus();
        });
        form.addEventListener('submit', function (ev) { ev.preventDefault(); ask(); });
        input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); ask(); } });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !drawer.hidden) { close(); } });
      }
    };
  }
  window.GcAiChatWidget.bindAll(document);
})();
JS;
    }

    private static function style(): string
    {
        return <<<'CSS'
<style>
.gc-aichat{display:inline-block;position:relative;font-size:14px}
.gc-aichat [hidden]{display:none!important}
.gc-aichat-launch{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border:1px solid var(--colorBorderMid,#d9dee5);border-radius:18px;background:var(--colorBgCard,#fff);color:var(--colorText,#222);font-weight:600;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.gc-aichat-launch:hover{border-color:var(--colorPrimary,#00d1b2);color:var(--colorPrimary,#00d1b2)}
.gc-aichat-spark{color:var(--colorPrimary,#00d1b2)}
.gc-aichat-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.18);z-index:9000}
.gc-aichat-drawer{position:fixed;top:0;right:0;bottom:0;width:min(440px,100vw);display:flex;flex-direction:column;background:var(--colorBgBody,#fff);color:var(--colorText,#222);border-left:1px solid var(--colorBorder,#e3e8ee);box-shadow:-8px 0 24px rgba(0,0,0,.12);z-index:9001;text-align:left}
.gc-aichat-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid var(--colorBorder,#e3e8ee);background:var(--colorBgCard,#fff)}
.gc-aichat-title{font-weight:700;font-size:15px}
.gc-aichat-actions{display:flex;gap:6px;align-items:center}
.gc-aichat-btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid var(--colorBorderMid,#d9dee5);background:var(--colorBgCard,#fff);color:var(--colorText,#222);font-weight:600;cursor:pointer}
.gc-aichat-btn.is-primary{background:var(--colorPrimary,#00d1b2);border-color:var(--colorPrimary,#00d1b2);color:#fff}
.gc-aichat-btn.is-ghost{background:transparent}
.gc-aichat-btn:disabled{opacity:.55;cursor:default}
.gc-aichat-x{font-size:20px;line-height:1;padding:0 10px}
.gc-aichat-msgs{flex:1 1 auto;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px}
.gc-aichat-intro{color:var(--colorTextMuted,#6b7280);font-size:13px;padding:6px 2px}
.gc-aichat-msg{max-width:92%;padding:9px 12px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word;line-height:1.4}
.gc-aichat-msg.is-user{align-self:flex-end;background:var(--colorPrimary,#00d1b2);color:#fff;border-bottom-right-radius:4px}
.gc-aichat-msg.is-bot{align-self:flex-start;background:var(--colorBgCard,#f3f4f6);border:1px solid var(--colorBorder,#e3e8ee);border-bottom-left-radius:4px}
.gc-aichat-msg.is-error{align-self:flex-start;background:var(--colorBgError,#fdecea);color:var(--colorDanger,#a1352a)}
.gc-aichat-msg.is-spin span{display:inline-block;width:6px;height:6px;margin:0 2px;border-radius:50%;background:var(--colorTextMuted,#9aa3ad);animation:gcAiBlink 1s infinite ease-in-out}
.gc-aichat-msg.is-spin span:nth-child(2){animation-delay:.15s}.gc-aichat-msg.is-spin span:nth-child(3){animation-delay:.3s}
@keyframes gcAiBlink{0%,80%,100%{opacity:.25}40%{opacity:1}}
.gc-aichat-srcs{margin-top:8px;padding-top:6px;border-top:1px dashed var(--colorBorder,#e3e8ee);font-size:12px;white-space:normal}
.gc-aichat-srcs-h{color:var(--colorTextMuted,#6b7280);margin-right:4px}
.gc-aichat-src{display:inline-block;margin:2px 4px 0 0;padding:1px 8px;border-radius:999px;background:var(--colorMid,#eef1f5);color:var(--colorText,#222);text-decoration:none;font-weight:600}
a.gc-aichat-src:hover{background:var(--colorPrimary,#00d1b2);color:#fff}
.gc-aichat-form{display:flex;gap:8px;padding:10px 14px;border-top:1px solid var(--colorBorder,#e3e8ee);background:var(--colorBgCard,#fff)}
.gc-aichat-input{flex:1 1 auto;resize:none;padding:8px 10px;border:1px solid var(--colorBorderMid,#d9dee5);border-radius:8px;background:var(--colorBgBody,#fff);color:var(--colorText,#222);font:inherit}
.gc-aichat-foot{padding:6px 14px 10px;font-size:11px;color:var(--colorTextMuted,#6b7280);background:var(--colorBgCard,#fff)}
.gc-aichat-foot code{font-size:11px}
</style>
CSS;
    }
}
