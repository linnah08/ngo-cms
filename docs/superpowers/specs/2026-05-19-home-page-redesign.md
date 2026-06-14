# Home Page Redesign

**Date:** 2026-05-19
**Status:** Approved

---

## Overview

Three targeted changes to the home page: fix the hero image overflow bug, reorder two sections for better flow, and add a photo to the mission section. No changes to the color palette, typography, or any other sections.

---

## 1. Hero image — layout fix

### Problem

The global CSS reset (`img { height: auto }`) overrides the `height="420"` HTML attribute on the hero image. If `hero.webp` is portrait-oriented, it renders at full height, making the hero section very tall. Result: the CTA buttons are pushed below the fold and the image is clipped by the viewport.

### Solution

Constrain the image inside a fixed-aspect-ratio box. The hero layout no longer depends on the actual image file dimensions.

**CSS changes to `assets/css/main.css`:**

```css
/* Replace the existing .hero__image img rule */
.hero__image {
  aspect-ratio: 3 / 4;
  overflow: hidden;
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 60px rgba(0,0,0,0.12);
}

.hero__image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
```

Remove `border-radius` and `box-shadow` from `.hero__image img` (they move to `.hero__image`).

No changes to `index.php` hero HTML. No changes to the `::before` decorative element.

---

## 2. Section order — impact numbers move up

### Change

In `index.php`, move the Impact Numbers section to immediately after the hero, before the Campaign block.

**New order:**
1. Hero
2. Impact Numbers (`<!-- IMPACT NUMBERS -->`)
3. Campaign (conditional)
4. Who We Work With
5. Mission
6. Latest News
7. Partners
8. CTA Banner

### Rationale

The impact numbers (120+, 8 центъра, etc.) are strong social proof. They should appear before the campaign ask, not after it.

---

## 3. Mission section — optional photo

### Change

When a mission image is configured, the mission section renders as a 2-column layout (photo left, text right). When no image is set, it falls back to the existing centered text layout unchanged.

**New layout (when image is set):**
- Left column: square photo (`aspect-ratio: 1/1`, `object-fit: cover`, `border-radius: 12px`)
- Right column: existing `$home['mission_text']` text (no changes to content), plus the existing two buttons

### PHP change to `index.php` — mission section

```php
<!-- MISSION -->
<section class="section section--grey">
  <?php if (!empty($home['mission_image'])): ?>
  <div class="container">
    <div class="mission-split">
      <div class="mission-split__image">
        <img src="<?= h($home['mission_image']) ?>"
             alt="Мисията на Фондация Различни умове"
             loading="lazy">
      </div>
      <div class="mission-split__text">
        <span class="section-label"><?= t('home.mission.title') ?></span>
        <p class="lead" style="margin-top:1.5rem;">
          <?= h($home['mission_text'] ?? '') ?>
        </p>
        <div class="btn-group" style="margin-top:2rem;">
          <a href="/za-nas/" class="btn btn--primary">Разберете повече за нас</a>
          <a href="/magazin/" class="btn btn--outline">Подкрепете ни</a>
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="container container--narrow" style="text-align:center;">
    <span class="section-label"><?= t('home.mission.title') ?></span>
    <h2><?= t('home.mission.title') ?></h2>
    <p class="lead" style="margin-top:1.5rem;">
      <?= h($home['mission_text'] ?? '') ?>
    </p>
    <div class="btn-group" style="justify-content:center;margin-top:2rem;">
      <a href="/za-nas/" class="btn btn--primary">Разберете повече за нас</a>
      <a href="/magazin/" class="btn btn--outline">Подкрепете ни</a>
    </div>
  </div>
  <?php endif; ?>
</section>
```

### CSS additions to `assets/css/main.css`

```css
.mission-split {
  display: grid;
  grid-template-columns: 1fr 1.4fr;
  gap: var(--space-2xl);
  align-items: center;
}

.mission-split__image {
  aspect-ratio: 1 / 1;
  overflow: hidden;
  border-radius: var(--radius-lg);
}

.mission-split__image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

@media (max-width: 700px) {
  .mission-split {
    grid-template-columns: 1fr;
  }
  .mission-split__image {
    aspect-ratio: 4 / 3;
  }
}
```

### Admin change — new mission image field

Add an image upload field to the Home page admin editor (`admin/pages.php`, home section). Field key: `mission_image`. Behaves identically to the existing campaign image upload — image picker with preview and clear button.

The field appears directly below the existing "Текст на мисията" textarea.

---

## Out of scope

- Centers grid, news cards, partners section, CTA banner — no changes
- Color palette, typography — no changes
- English home page (`en/index.php`) — same changes apply (separate task if needed)
- No new admin pages; the mission image field is added inline to the existing home admin editor
