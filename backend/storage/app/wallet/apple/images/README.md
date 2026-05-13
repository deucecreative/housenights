# Apple Wallet Pass Images

These are placeholder PNGs used by the Apple Wallet `.pkpass` generator
(`AppleWalletPassService`). They render a solid dark square with a white
circle so the pass renders cleanly during development.

## Replace with real branded art

Apple requires the following images (1x and 2x — Retina):

- `icon.png` (29×29) and `icon@2x.png` (58×58) — required, shown in notifications
- `logo.png` (~160×50) and `logo@2x.png` (~320×100) — top-left of the pass

Optional but recommended:

- `strip.png` / `strip@2x.png` — wide strip image behind primary fields
- `background.png` / `background@2x.png` — full pass background
- `thumbnail.png` / `thumbnail@2x.png` — square thumbnail

Drop branded PNGs in this directory with the same filenames and they
will be picked up automatically.
