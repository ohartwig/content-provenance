/**
 * Fills the Content Credentials panel with what the file itself says, by asking
 * /_provenance/api/asset.
 *
 * Strictly an enhancement. The panel already renders the metadata we stored at
 * upload time and is fully usable with this script absent, blocked or broken —
 * so nothing here is allowed to remove or replace existing content, only add to
 * it. If the request fails, the panel stays as it was and a short line says the
 * live check did not answer. It never shows an empty panel.
 *
 * Why the fetch happens on open rather than on load: a page can carry many
 * marked images, and each answer costs a server-side c2patool run on a cache
 * miss. Requesting them all up front would turn one page view into a dozen
 * process spawns for panels nobody opened.
 *
 * No framework, no build step, no polyfill. The element is a <details>, and the
 * behaviour hangs off its own toggle event.
 */
(function () {
  'use strict';

  var ENDPOINT = '/_provenance/api/asset';
  var loaded = new WeakSet();

  /**
   * Wording lives in data attributes so the template stays the single place
   * that knows about translations — this file must not hardcode English.
   */
  function label(root, name, fallback) {
    return root.getAttribute('data-cp-' + name) || fallback;
  }

  function row(dl, term, value) {
    var dt = document.createElement('dt');
    dt.textContent = term;
    var dd = document.createElement('dd');
    // textContent, never innerHTML: every value here comes from a file's
    // metadata, which is attacker-controlled the moment anyone can upload.
    dd.textContent = value;
    dl.appendChild(dt);
    dl.appendChild(dd);
  }

  function render(details, data) {
    var panel = details.querySelector('.cb-content-credentials__panel');
    if (!panel) {
      return;
    }

    var live = document.createElement('div');
    live.className = 'cb-content-credentials__live';

    var heading = document.createElement('p');
    heading.className = 'cb-content-credentials__live-heading';
    heading.textContent = label(details, 'live-heading', 'Checked in the file');
    live.appendChild(heading);

    var dl = document.createElement('dl');
    dl.className = 'cb-content-credentials__facts';

    if (data.hasManifest) {
      row(dl, label(details, 'live-manifest', 'Manifest'), label(details, 'live-present', 'present'));
    } else if (data.c2paStatus === 'stripped') {
      row(dl, label(details, 'live-manifest', 'Manifest'), label(details, 'live-stripped', 'removed from the file'));
    } else {
      row(dl, label(details, 'live-manifest', 'Manifest'), label(details, 'live-absent', 'none'));
    }

    if (data.signingChainIssuer) {
      row(dl, label(details, 'live-issuer', 'Issuer'), data.signingChainIssuer);
    }
    if (data.aiSystem) {
      row(dl, label(details, 'live-system', 'AI system'), data.aiSystem);
    }

    live.appendChild(dl);

    /*
     * The endpoint reports trustAnchor: 'self-hosted'. Saying so here is the
     * point rather than a footnote: a reader who checks provenance is entitled
     * to know that a public validator will call this issuer unknown, and
     * leaving it out would let the panel imply more than the signature carries.
     */
    if (data.trustAnchor === 'self-hosted') {
      var anchor = document.createElement('p');
      anchor.className = 'cb-content-credentials__live-anchor';
      anchor.textContent = label(
        details,
        'live-anchor',
        'Signed with this site’s own certificate authority, which public validators do not know.'
      );
      live.appendChild(anchor);
    }

    panel.appendChild(live);
  }

  function fail(details) {
    var panel = details.querySelector('.cb-content-credentials__panel');
    if (!panel) {
      return;
    }
    var note = document.createElement('p');
    note.className = 'cb-content-credentials__live-error';
    // Says the check did not run — it does NOT say the file is unsigned. Those
    // are different statements and conflating them would be a lie by omission.
    note.textContent = label(details, 'live-error', 'The live check did not answer. The details above are from our records.');
    panel.appendChild(note);
  }

  function load(details) {
    if (loaded.has(details)) {
      return;
    }
    loaded.add(details);

    var fileUid = details.getAttribute('data-cp-file');
    if (!fileUid) {
      return;
    }

    fetch(ENDPOINT + '?file=' + encodeURIComponent(fileUid), {
      credentials: 'omit',
      headers: { Accept: 'application/json' },
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        render(details, data);
      })
      .catch(function () {
        fail(details);
      });
  }

  function bind(details) {
    details.addEventListener('toggle', function () {
      if (details.open) {
        load(details);
      }
    });
  }

  function init() {
    document
      .querySelectorAll('details.cb-content-credentials[data-cp-file]')
      .forEach(bind);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
