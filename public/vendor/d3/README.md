# D3.js (vendored)

This directory holds a pinned copy of [D3.js](https://d3js.org/) used by the
genealogy SVG tree (`public/js/matrix-genealogy-d3.js`).

## Files

- `d3.v7.min.js` — D3 v7.9.0, the full bundle (`d3-selection`, `d3-hierarchy`,
  `d3-zoom`, `d3-transition`, `d3-shape`, etc.). Minified, ~273 KB. Sourced
  from <https://cdn.jsdelivr.net/npm/d3@7.9.0/dist/d3.min.js>.

## Why vendored

The rest of the plugin loads every JS dependency from inside the plugin
directory — no external CDNs at runtime. Vendoring D3 keeps that pattern
intact so the genealogy view works on locked-down installs (offline /
private VPS / sites with strict CSPs blocking third-party origins) and
removes any third-party-availability risk from page rendering.

## Updating

1. Download the desired version from <https://github.com/d3/d3/releases>
   (or jsdelivr/unpkg with the version pinned).
2. Replace `d3.v7.min.js` in place.
3. Bump `MATRIX_MLM_VERSION` in `matrix-mlm.php` so cached browsers pick up
   the new file.
4. Smoke-test the genealogy view in both classic and D3 modes.

## License

D3 is distributed under the [ISC license](https://github.com/d3/d3/blob/main/LICENSE)
(© Mike Bostock), which is compatible with the GPL-2.0+ license under
which this plugin ships.
