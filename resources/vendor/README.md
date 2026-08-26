# Vendored front-end assets

Third-party assets vendored as-is (ADR#10: runtime first, bundler later — this package has no JS
build; the files are served verbatim by whoever mounts the live routes).

- **`alpine.min.js`** — Alpine.js **3.14.3**, the official `cdn.min.js` build. License **MIT**
  (© Caleb Porzio and contributors). Source:
  `https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js`. It is the version `@milpa/design`
  expects. The minified build carries no license banner; the attribution lives here.

  Update by hand (bump the version here and in `Milpa\Live\Support\ClientRuntime`):
  ```
  curl -fsSL https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js -o resources/vendor/alpine.min.js
  ```
