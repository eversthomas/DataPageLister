<?php namespace ProcessWire;

/**
 * DataPageListerRender
 *
 * Rendert die komplette Übersicht:
 *  - Inline Styles und JS (debounced Live-Filter)
 *  - Header / Hilfe
 *  - Filterbar (ohne <form>, Navigation via JS)
 *  - "Neu anlegen"-Button
 *  - Tabelle mit Aktionen
 *  - Pager, der Filter-Querys beibehält
 *
 * Erwartete aktive Filter-Keys in $active:
 *   - q   : Suchbegriff (string)
 *   - by  : Feldname für Suche (string)
 *   - sort: Feldname für Sortierung (string)
 *   - dir : Richtung 'asc'|'desc'
 *   - pg  : Seitenzahl (int), optional
 */
class DataPageListerRender {

  /**
   * Haupteinstieg: baut die gesamte Übersicht
   */
  public static function overview(
    Page $parent,
    array $fieldNames,
    PageArray $items,
    int $total,
    int $pageNum,
    int $limit,
    array $active,
    array $childTemplates,
    array $allowedFields,
    string $adminUrl,
    bool $showHelp,
    bool $showViewButton
  ) : string {

    // Basis: Admin-Edit-URL der Elternseite (hier soll gefiltert/paginiert werden)
    $editBase = rtrim($adminUrl, '/') . "/page/edit/?id=" . (int) $parent->id;

    $out  = self::styles();
    $out .= self::renderHeader($parent, $childTemplates, $total);
    if ($showHelp) {
      $out .= self::renderHelp($childTemplates, $fieldNames);
    }
    $out .= self::renderFilterBar($active, $allowedFields, $editBase);
    $out .= self::renderAddButton($parent, $childTemplates, $adminUrl);
    $out .= self::renderTable($fieldNames, $items, $showViewButton);
    $out .= self::renderPager($pageNum, $limit, $total, $editBase, $active);

    return $out;
  }

  /**
   * Inline CSS + JS (debounced Live-Filter)
   */
  public static function styles() : string {
    return <<<HTML
<style>
.dpo-wrap { background:#f8f9fa; padding:12px; border-radius:6px; margin-bottom:12px; }
.dpo-meta { margin:0 0 8px; font-size:.95rem; }
.dpo-help { background:#fff3cd; border:1px solid #ffeeba; padding:10px; border-radius:6px; margin:8px 0 12px; }
.dpo-filters { display:flex; gap:8px; flex-wrap:wrap; padding:8px; background:#fff; border:1px solid #e5e5e5; border-radius:6px; margin-bottom:12px; align-items:center; }
.dpo-filters input[type="text"] { padding:6px; min-width:220px; }
.dpo-filters label { opacity:.8; }
.dpo-table { width:100%; border-collapse:collapse; }
.dpo-table th, .dpo-table td { border-bottom:1px solid #e5e5e5; padding:8px; vertical-align:top; }
.dpo-table thead th { background:#fafafa; }
.dpo-actions a { margin-right:6px; }
.dpo-pager { display:flex; gap:6px; margin-top:12px; flex-wrap:wrap; }
.dpo-pager a, .dpo-pager span { padding:6px 9px; border:1px solid #ddd; border-radius:4px; text-decoration:none; }
.dpo-pager .active { background:#e9ecef; }
/* Zusätzliche Submit-Buttons im ProcessPageEdit-Footer für diese Ansicht ausblenden */
ul.pw-button-dropdown {
    display: none;
}
}
</style>
<script>
(function(){
  function debounce(fn, wait){ let t; return function(){ clearTimeout(t); const a=arguments, c=this; t=setTimeout(function(){ fn.apply(c,a); }, wait); }; }
  function toQuery(obj){
    const parts=[];
    for(const k in obj){
      if(Object.prototype.hasOwnProperty.call(obj,k)){
        const v=obj[k];
        if(v!==undefined && v!==null && v!==''){
          parts.push(encodeURIComponent(k)+'='+encodeURIComponent(v));
        }
      }
    }
    return parts.join('&');
  }
  function parseQuery(search){
    const out={};
    if(!search) return out;
    search.replace(/^\\?/,'').split('&').forEach(function(kv){
      if(!kv) return;
      const i = kv.indexOf('=');
      if(i===-1){ out[decodeURIComponent(kv)]=''; return; }
      const k = decodeURIComponent(kv.slice(0,i));
      const v = decodeURIComponent(kv.slice(i+1));
      out[k]=v;
    });
    return out;
  }

  document.addEventListener('DOMContentLoaded', function(){
    var bar = document.querySelector('.dpo-filters');
    if(!bar) return;

    var baseUrl = bar.getAttribute('data-base-url') || window.location.pathname;
    var keep    = JSON.parse(bar.getAttribute('data-keep') || '{}');

    var inputQ  = bar.querySelector('input[name="q"]');
    var selBy   = bar.querySelector('select[name="by"]');
    var selSort = bar.querySelector('select[name="sort"]');
    var selDir  = bar.querySelector('select[name="dir"]');
    var btnApply= bar.querySelector('button[data-apply]');
    var btnReset= bar.querySelector('a[data-reset]');

    function navigate(resetPg){
      var q = parseQuery(window.location.search);
      // Set/Update Filter
      if(inputQ)  q.q    = inputQ.value || '';
      if(selBy)   q.by   = selBy.value || '';
      if(selSort) q.sort = selSort.value || '';
      if(selDir)  q.dir  = selDir.value || '';

      // Leere entfernen
      Object.keys(q).forEach(function(k){ if(q[k]==='' || q[k]==null) delete q[k]; });

      // Paginierung ggf. zurücksetzen
      if(resetPg) q.pg = 1;

      // Query bauen
      var qs = toQuery(q);
      var joinChar = (baseUrl.indexOf('?') !== -1) ? '&' : '?';
      window.location = baseUrl + (qs ? (joinChar + qs) : '');
    }

    // Klick auf Anwenden
    if(btnApply) btnApply.addEventListener('click', function(){ navigate(true); });

    // Live-Filter beim Tippen (Enter wird auch unterstützt)
    if(inputQ){
      inputQ.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); navigate(true); }});
      inputQ.addEventListener('input', debounce(function(){ navigate(true); }, 350));
    }

    // Dropdowns ändern -> sofort anwenden
    [selBy, selSort, selDir].forEach(function(sel){
      if(sel) sel.addEventListener('change', function(){ navigate(true); });
    });

    // Reset
    if(btnReset){
      btnReset.addEventListener('click', function(e){
        e.preventDefault();
        // Nur zur Base-URL (inkl. ?id=...)
        window.location = baseUrl;
      });
    }
  });
})();
</script>
HTML;
  }

  /**
   * Header mit kurzer Meta-Info
   */
  public static function renderHeader(Page $parent, array $childTemplates, int $total) : string {
    $tplNames = implode(', ', array_map(function($tpl){ return $tpl->name; }, $childTemplates));
    $parentTitle = htmlspecialchars((string) $parent->title, ENT_QUOTES, 'UTF-8');
    return "<div class='dpo-wrap dpo-meta'><strong>{$parentTitle}</strong> – {$total} Einträge | Kind-Templates: {$tplNames}</div>";
  }

  /**
   * Hilfe-Hinweis
   */
  public static function renderHelp(array $childTemplates, array $fieldNames) : string {
    $fieldsList = $fieldNames ? implode(', ', array_map('htmlspecialchars', $fieldNames)) : '–';
    $tpls = $childTemplates
      ? implode(', ', array_map(function($tpl){ return "<code>" . htmlspecialchars($tpl->name, ENT_QUOTES, 'UTF-8') . "</code>"; }, $childTemplates))
      : '—';
    return "<div class='dpo-help'>
      <strong>Hinweis:</strong> Diese Übersicht zeigt den Titel sowie die definierten Felder der Kinder.<br>
      Templates: {$tpls}<br>
      Felder: {$fieldsList}
    </div>";
  }

  /**
   * Filterbar (JS-gesteuert, kein <form>)
   * $baseUrl muss die Admin-Edit-URL der Elternseite sein (inkl. ?id=)
   */
  public static function renderFilterBar(array $active, array $allowedFields, string $baseUrl) : string {
    $san = wire('sanitizer');

    $q    = $san->entities($active['q']    ?? (wire('input')->get('q')    ?? ''));
    $by   = $san->entities($active['by']   ?? (wire('input')->get('by')   ?? 'title'));
    $sort = $san->entities($active['sort'] ?? (wire('input')->get('sort') ?? 'title'));
    $dir  = $san->entities($active['dir']  ?? (wire('input')->get('dir')  ?? 'asc'));

    // Optionen-Renderer
    $renderOpts = function($current) use ($allowedFields) {
      $out = '';
      foreach ($allowedFields as $f) {
        $fname = htmlspecialchars((string) $f, ENT_QUOTES, 'UTF-8');
        $sel = ($f == $current) ? 'selected' : '';
        $out .= "<option value=\"{$fname}\" {$sel}>{$fname}</option>";
      }
      return $out;
    };

    $byOptions   = $renderOpts($by);
    $sortOptions = $renderOpts($sort);

    $keep = [
      'q'    => $active['q']   ?? '',
      'by'   => $by,
      'sort' => $sort,
      'dir'  => $dir,
    ];
    $keepJson = htmlspecialchars(json_encode($keep), ENT_QUOTES, 'UTF-8');
    $baseEsc  = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');

    return "<div class='dpo-filters' data-base-url='{$baseEsc}' data-keep='{$keepJson}'>
      <input type='text' name='q' value='{$q}' placeholder='Suche...'>
      <label>Feld</label>
      <select name='by'>{$byOptions}</select>
      <label>Sortieren nach</label>
      <select name='sort'>{$sortOptions}</select>
      <select name='dir'>
        <option value='asc'  ".($dir==='asc'?'selected':'').">asc</option>
        <option value='desc' ".($dir==='desc'?'selected':'').">desc</option>
      </select>
      <button class='ui-button ui-state-default' type='button' data-apply>Anwenden</button>
      <a class='ui-button ui-state-default' href='{$baseEsc}' data-reset>Zurücksetzen</a>
    </div>";
  }

  /**
   * Button "Neu anlegen" (nimmt erstes Kind-Template als Default)
   */
  public static function renderAddButton(Page $parent, array $childTemplates, string $adminUrl) : string {
    if (!count($childTemplates)) return '';
    $tpl = $childTemplates[0];
    $addUrl = rtrim($adminUrl, '/') . "/page/add/?parent_id=" . (int) $parent->id . "&template_id=" . (int) $tpl->id;
    $addUrl = htmlspecialchars($addUrl, ENT_QUOTES, 'UTF-8');
    return "<div style='margin:8px 0 12px;'>
      <a href='{$addUrl}' class='ui-button ui-state-default pw-panel'><i class='fa fa-plus'></i> Neu anlegen</a>
    </div>";
  }

  /**
   * Tabelle mit Daten und Aktionen
   */
  public static function renderTable(array $fieldNames, PageArray $items, bool $showViewButton) : string {
    // Header
    $ths = "<th>Title</th>";
    foreach ($fieldNames as $f) {
      $ths .= "<th>" . htmlspecialchars((string) $f, ENT_QUOTES, 'UTF-8') . "</th>";
    }
    $ths .= "<th style='width:180px;'>Aktionen</th>";

    $adminUrl = wire('config')->urls->admin;

    $rows = '';
    foreach ($items as $p) {
      // Titel mit Edit-Link
      $editUrl = rtrim($adminUrl, '/') . "/page/edit/?id=" . (int) $p->id;
      $titleCell = "<td><a href='" . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars((string)$p->title, ENT_QUOTES, 'UTF-8') . "</a></td>";

      // Datenzellen
      $dataCells = '';
      foreach ($fieldNames as $f) {
        $val = $p->get($f);

        if ($val instanceof \ProcessWire\PageArray) {
          $val = implode(', ', $val->explode('title'));
        } elseif ($val instanceof \ProcessWire\Page) {
          $val = $val->title;
        } elseif (is_array($val)) {
          $val = implode(', ', $val);
        }

        $dataCells .= "<td>" . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . "</td>";
      }

      // Aktionen
      $actions = [];
      $actions[] = "<a href='" . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . "' class='ui-button ui-state-default'>Bearbeiten</a>";
      if ($showViewButton) {
        $actions[] = "<a target='_blank' href='" . htmlspecialchars($p->url, ENT_QUOTES, 'UTF-8') . "' class='ui-button ui-state-default'>Ansehen</a>";
      }

      $rows .= "<tr>{$titleCell}{$dataCells}<td class='dpo-actions'>" . implode(' ', $actions) . "</td></tr>";
    }

    return "<table class='dpo-table'>
      <thead><tr>{$ths}</tr></thead>
      <tbody>{$rows}</tbody>
    </table>";
  }

  /**
   * Pager, der die aktiven Filter beibehält.
   * $baseUrl ist die Admin-Edit-URL der Elternseite inkl. ?id=...
   */
  public static function renderPager(int $pageNum, int $limit, int $total, string $baseUrl, array $active) : string {
    if ($total <= $limit) return '';
    $pages = (int) ceil($total / $limit);

    // Nur erlaubte Filter in Query aufnehmen
    $query = [];
    if (!empty($active['q']))    $query['q'] = $active['q'];
    if (!empty($active['by']))   $query['by'] = $active['by'];
    if (!empty($active['sort'])) $query['sort'] = $active['sort'];
    if (!empty($active['dir']))  $query['dir'] = $active['dir'];

    $makeUrl = function($pg) use ($baseUrl, $query) {
      $q = $query;
      $q['pg'] = (int) $pg;
      $qs = http_build_query($q);
      $join = (strpos($baseUrl, '?') !== false) ? '&' : '?';
      return $baseUrl . ($qs ? ($join . $qs) : '');
    };

    $out = "<div class='dpo-pager'>";
    for ($i = 1; $i <= $pages; $i++) {
      if ($i === $pageNum) {
        $out .= "<span class='active'>{$i}</span>";
      } else {
        $out .= "<a href='" . htmlspecialchars($makeUrl($i), ENT_QUOTES, 'UTF-8') . "'>{$i}</a>";
      }
    }
    $out .= "</div>";

    return $out;
  }
}
