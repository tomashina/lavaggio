# Splide 4.1.4

Distribution CSS and JavaScript from `@splidejs/splide@4.1.4`, under the included MIT license, with the local JavaScript patch below.

- https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css
- https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js
- https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/LICENSE

The CSS is included in the main Vite stylesheet so carousel geometry is available before first paint. The shared Blade partial loads the local JavaScript once, without a separate CDN dependency.

## Local touch patch

The Drag component does not cancel a horizontal touch movement until it exceeds
the configured touch drag threshold. Upstream 4.1.4 cancels even movements below
that threshold, which can suppress the browser's link click after a small finger
movement on iOS. Mouse behavior, vertical scrolling and deliberate swipes retain
their existing behavior. The shared storefront defaults set the touch threshold
to 24 pixels.

The single guarded expression change is:

```diff
-s=U(D(t))>(R(t)?r:o),F(n))
+s=U(D(t))>(R(t)?r:o),(s||!R(t))&&F(n))
```

`tests/JavaScript/splide-touch-drag.test.mjs` exercises the actual vendored Drag
component in a Node VM. Recheck this patch and those tests before replacing the
vendor file; do not apply the expression replacement unless it occurs exactly
once. The bundled license and copyright header are preserved.
