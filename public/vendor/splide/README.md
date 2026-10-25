# Splide 4.1.4

Distribution CSS and JavaScript from `@splidejs/splide@4.1.4`, under the included MIT license, with the local JavaScript patch below.

- https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css
- https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js
- https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/LICENSE

The CSS is included in the main Vite stylesheet so carousel geometry is available before first paint. The shared Blade partial loads the local JavaScript once, without a separate CDN dependency.

## Local touch patches

The Drag component does not cancel a horizontal touch movement until it exceeds
the configured touch drag threshold. Upstream 4.1.4 cancels even movements below
that threshold, which can suppress the browser's link click after a small finger
movement on iOS. Mouse behavior, vertical scrolling and deliberate swipes retain
their existing behavior. The shared storefront defaults set the touch threshold
to 24 pixels.

The original guarded expression change is:

```diff
-s=U(D(t))>(R(t)?r:o),F(n))
+s=U(D(t))>(R(t)?r:o),(s||!R(t))&&F(n))
```

Touches also wait for that threshold before interrupting an existing transition.
Upstream seeds its drag flag from the MOVING/SCROLLING state and cancels motion
on touchstart. Consequently, even a tap can advance a card and cancel the native
link click. The additional guarded changes are:

- Seed the initial drag flag from MOVING/SCROLLING only for mouse gestures:
  `s=m.is([G,rn])` becomes `s=!t&&m.is([G,rn])`.
- The busy-transition guard cancels only mouse gestures:
  `w.isBusy()?F(n,!0)` becomes `!t&&w.isBusy()?F(n,!0)`. Touches can determine
  whether they are taps or swipes before interrupting a busy transition.
- Defer `y.cancel(),b.cancel()` on touchstart with `t||(y.cancel(),b.cancel())`.
- Enter the DRAGGING state immediately on mousemove, but on touchmove only after
  the drag flag is set: `(!R(n)||s)&&(m.is(6)||(m.set(6),h("drag")))`.
- When a horizontal touch first exceeds the threshold, cancel the running
  transition, capture its current position, enter DRAGGING and suppress the
  eventual click:
  `s&&R(t)&&(y.cancel(),b.cancel(),f=E(),m.is(6)||(m.set(6),h("drag")),l=!0)`.

Thus taps and vertical scrolling leave existing motion alone, while deliberate
swipes still work directly over product image and title links. Mouse behavior is
unchanged. Watchline continuous product rows use the same 420ms transition and
easing across their initializers; other storefronts retain their own timing.

`tests/JavaScript/splide-touch-drag.test.mjs` exercises the actual vendored Drag
component in a Node VM, including idle, moving and scrolling states, taps,
threshold jitter, deliberate image/clone swipes, vertical scrolling and mouse
input. Recheck these patches and tests before replacing the vendor file; apply
each expression replacement only when it occurs exactly once. The bundled
license and copyright header are preserved.
