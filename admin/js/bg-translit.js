/* Bulgarian Cyrillic → Latin letters, by the official rules (Закон за
   транслитерацията): Детелина → Detelina, Щерев → Shterev, Мария → Maria.
   For people's names, which are written in Latin letters, never translated —
   DeepL turned „Детелина“ into "Clover". Runs in the page; no service needed.
   Used by the „✦ На латиница“ button (admin-footer.php); tests run it in node. */
(function (root) {
  var MAP = {
    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ж': 'zh', 'з': 'z',
    'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p',
    'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch',
    'ш': 'sh', 'щ': 'sht', 'ъ': 'a', 'ь': 'y', 'ю': 'yu', 'я': 'ya'
  };

  function isUpper(ch) { return ch !== ch.toLowerCase(); }

  function bgTransliterate(text) {
    // „-ия“ at the end of a word is written „-ia“: Мария → Maria, София → Sofia.
    text = String(text).replace(/([иИ])([яЯ])(?![а-яА-Я])/g, function (m, i, ya) {
      return (isUpper(i) ? 'I' : 'i') + (isUpper(ya) ? 'A' : 'a');
    });
    var out = '';
    for (var k = 0; k < text.length; k++) {
      var ch = text[k], lat = MAP[ch.toLowerCase()];
      if (lat === undefined) { out += ch; continue; }
      if (isUpper(ch)) {
        // A capital stays one capital (Ж → Zh); in a word written in capitals, all of it (ЖЕЛЕВ → ZHELEV).
        var next = text[k + 1] || '', prev = text[k - 1] || '';
        var allCaps = (next && MAP[next.toLowerCase()] !== undefined && isUpper(next)) ||
                      (!next.match(/[а-яА-Я]/) && prev && MAP[prev.toLowerCase()] !== undefined && isUpper(prev));
        lat = allCaps ? lat.toUpperCase() : lat.charAt(0).toUpperCase() + lat.slice(1);
      }
      out += lat;
    }
    return out;
  }

  root.bgTransliterate = bgTransliterate;
  if (typeof module !== 'undefined' && module.exports) module.exports = bgTransliterate;
})(typeof window !== 'undefined' ? window : this);
