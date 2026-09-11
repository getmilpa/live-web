/**
 * code-block — teaches the copy button the server already printed what to do.
 *
 * IT ADDS NO MARKUP. The button, its icon and its status slot are in the HTML the renderer emitted, so
 * a page whose script never loads still shows a block with a button that simply does nothing visible —
 * not chrome with a hole in it. This house has measured the other way round more than once: a Save that
 * was never wired, and an enrol button whose whole ceremony lived in a heredoc nobody parsed
 * (greenhouse decisions/0261, 0272, 0299).
 *
 * ONE DELEGATED LISTENER, not one per block. A page can carry a dozen of these; twelve listeners is
 * twelve things to remove, and blocks that arrive later — a re-render, a swapped region — would get
 * none. The document keeps one.
 */
(() => {
  if (window.__milpaCodeBlock) { return; }
  window.__milpaCodeBlock = true;

  /* The payload is the COMMANDS, put there by the renderer — never `textContent` off the block, which
     would carry the prompt glyph with it. A `$` pasted into a shell is the one error this affordance
     exists to prevent. */
  const payloadOf = (button) => button.getAttribute('data-milpa-copy') || '';

  /* Says what happened, on the button, for two seconds. Then it goes back to being an icon: a status
     that stays is a status nobody reads the second time. */
  const say = (button, text) => {
    const said = button.querySelector('.copy-said');
    if (said) { said.textContent = text; }
    button.setAttribute('data-copied', '');
    window.setTimeout(() => {
      if (said) { said.textContent = ''; }
      button.removeAttribute('data-copied');
    }, 2000);
  };

  /* `navigator.clipboard` needs a secure context, which `http://localhost` is and a plain-http host on
     a LAN is not. The fallback is the old selection dance, because «copy did nothing and said nothing»
     is worse than an unfashionable API. */
  const write = async (text) => {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
    const scratch = document.createElement('textarea');
    scratch.value = text;
    scratch.setAttribute('readonly', '');
    scratch.style.position = 'fixed';
    scratch.style.opacity = '0';
    document.body.appendChild(scratch);
    scratch.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (failure) { ok = false; }
    scratch.remove();
    return ok;
  };

  document.addEventListener('click', async (event) => {
    // MATCHED BY THE PAYLOAD ATTRIBUTE, never by `.copy`. This listener is on `document`, and `.copy`
    // is a class any page or any other component may already use — the scoper keeps two strangers'
    // CSS apart and does nothing for a delegated selector. `data-milpa-copy` is this component's own
    // and is the very thing being read, so the match and the work cannot disagree.
    const button = event.target.closest('[data-milpa-copy]');
    if (!button) { return; }
    const payload = payloadOf(button);
    if (payload === '') { return; }
    say(button, (await write(payload)) ? 'copied' : 'press ⌘C');
  });
})();
