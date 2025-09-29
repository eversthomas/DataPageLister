<?php namespace ProcessWire;

/**
 * DataPageListerRender
 *
 * Rendert die HTML-Ausgabe für DataPageLister:
 * - Styles
 * - Header
 * - Help-Box
 * - Filterbar
 * - Add-Button
 * - Tabelle
 * - Pager
 */

class DataPageListerRender {

  /** Gesamtausgabe */
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
    $out  = self::styles();
    $out .= self::renderHeader($parent, $childTemplates, $total);
    if($showHelp) $out .= self::renderHelp($childTemplates, $fieldNames);
    $out .= self::renderFilterBar($active, $allowedFields, $parent->url);
    $out .= self::renderAddButton($parent, $childTemplates, $adminUrl);
    $out .= self::renderTable($fieldNames, $items, $showViewButton);
    $out .= self::renderPager($pageNum, $limit, $total, $parent->url, $active);
    return $out;
  }

  /** Inline CSS */
  public static function styles() : string {
    return <<<HTML
<style>
.dpo-wrap { background:#f8f9fa; padding:12px; border-radius:6px; margin-bottom:12px; }
.dpo-meta { margin:0 0 8px; font-size:.95em; }
.dpo-help { background:#fff3cd; border:1px solid #ffeeba; padding:10px; border-radius:6px; margin:8px 0 12px; }
.dpo-filters { display:flex; gap:8px; flex-wrap:wrap; padding:8px; background:#fff; border:1px solid #e5e5e5; border-radius:6px; margin-bottom:12px; }
.dpo-filters input[type="text"] { padding:6px; min-width:220px; }
.dpo-table { width:100%; }
.dpo-actions a { margin-right:6px; }
.dpo-pager { display:flex; gap:6px; margin-top:12px; }
.dpo-pager a, .dpo-pager span { padding:6px 9px; border:1px solid #ddd; border-radius:4px; text-decoration:none; }
.dpo-pager .active { background:#e9ecef; }
</style>
HTML;
  }

  /** Header */
  public static function renderHeader(Page $parent, array $childTemplates, int $total) : string {
    $tplNames = implode(', ', array_map(fn($tpl) => $tpl->name, $childTemplates));
    return "<div class='dpo-wrap dpo-meta'><strong>{$parent->title}</strong> – {$total} Einträge | Kind-Templates: {$tplNames}</div>";
  }

  /** Help-Box */
  public static function renderHelp(array $childTemplates, array $fieldNames) : string {
    $fieldsList = $fieldNames ? implode(', ', $fieldNames) : '–';
    $tpls = $childTemplates ? implode(', ', array_map(fn($tpl) => "<code>{$tpl->name}</code>", $childTemplates)) : '—';
    return "<div class='dpo-help'>
      <strong>Hinweis:</strong> Diese Übersicht zeigt den Titel sowie die ersten Felder der Kinder.<br>
      Templates: {$tpls}<br>
      Felder: {$fieldsList}
    </div>";
  }

  /** Filterbar */
  public static function renderFilterBar(array $active, array $allowedFields, string $url) : string {
    $q = wire('sanitizer')->entities(wire('input')->get('q') ?? '');
    $by   = wire('sanitizer')->entities(wire('input')->get('by') ?? 'title');
    $sort = wire('sanitizer')->entities(wire('input')->get('sort') ?? 'asc');

    $options = '';
    foreach($allowedFields as $f) {
      $sel = $f === $by ? 'selected' : '';
      $options .= "<option value='{$f}' {$sel}>{$f}</option>";
    }

    return "<form method='get' class='dpo-filters'>
      <input type='text' name='q' value='{$q}' placeholder='Suche...'>
      <select name='by'>{$options}</select>
      <select name='sort'>
        <option value='asc' ".($sort==='asc'?'selected':'').">asc</option>
        <option value='desc' ".($sort==='desc'?'selected':'').">desc</option>
      </select>
      <button class='ui-button ui-state-default' type='submit'>Anwenden</button>
      <a class='ui-button ui-state-default' href='{$url}'>Zurücksetzen</a>
    </form>";
  }

  /** Add-Button */
  public static function renderAddButton(Page $parent, array $childTemplates, string $adminUrl) : string {
    if(!count($childTemplates)) return '';
    $tpl = $childTemplates[0];
    $addUrl = $adminUrl . "page/add/?parent_id={$parent->id}&template_id={$tpl->id}";
    return "<div style='margin:8px 0 12px;'>
      <a href='{$addUrl}' class='ui-button ui-state-default pw-panel'><i class='fa fa-plus'></i> Neu anlegen</a>
    </div>";
  }

  /** Tabelle */
  public static function renderTable(array $fieldNames, PageArray $items, bool $showViewButton) : string {
    $table = wire('modules')->get('MarkupAdminDataTable');
    $table->setSortable(true);
    $table->setEncodeEntities(false);

    $headers = array_merge(['Titel'], $fieldNames, ['Status','Aktionen']);
    $table->headerRow($headers);

    foreach($items as $p) {
      $row = [];
      $row[] = "<strong>{$p->title}</strong>";

      foreach($fieldNames as $fname) {
        $val = $p->get($fname);
        if(is_object($val)) $val = $val instanceof Page ? $val->title : $val->id;
        $row[] = wire('sanitizer')->entities((string)$val);
      }

      $row[] = $p->is(Page::statusUnpublished)
        ? "<span style='color:orange;'>⏳ Entwurf</span>"
        : "<span style='color:green;'>✅ Aktiv</span>";

      $actions = "<a href='{$p->editUrl}' class='ui-button ui-state-default pw-panel'><i class='fa fa-edit'></i> Bearbeiten</a>";
      if($showViewButton) {
        $actions .= " <a href='{$p->url}' target='_blank' class='ui-button ui-state-default'><i class='fa fa-eye'></i> Ansehen</a>";
      }
      $row[] = $actions;

      $table->row($row);
    }

    return $table->render();
  }

  /** Pager */
  public static function renderPager(int $pageNum, int $limit, int $total, string $url, array $active) : string {
    if($total <= $limit) return '';
    $pages = (int) ceil($total / $limit);

    $query = $active;
    $makeUrl = function($pg) use ($url, $query) {
      $q = $query; $q['pg'] = $pg;
      return $url . '?' . http_build_query($q);
    };

    $out = "<div class='dpo-pager'>";
    for($i=1; $i<=$pages; $i++) {
      $out .= ($i === $pageNum) ? "<span class='active'>{$i}</span>" : "<a href='{$makeUrl($i)}'>{$i}</a>";
    }
    $out .= "</div>";
    return $out;
  }
}
