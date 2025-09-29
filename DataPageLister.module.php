<?php namespace ProcessWire;

/**
 * DataPageLister (v1.0.2)
 *
 * Generische Admin-Übersicht für Daten-Containerseiten:
 * - Ersetzt die Edit-Maske der Elternseite durch eine Tabelle ihrer Kinder
 * - Dynamische Spalten (Titel + erste N Felder aus dem Kind-Template)
 * - Filterleiste (Feldwahl, Suche, Sortierung, Richtung), Pagination
 * - „Neu anlegen“-Button, Aktionen (Bearbeiten, optional Ansehen)
 * - PageTree: Kinder unter Daten-Containern ausblenden, „Bearbeiten“ → „Tabelle“
 *
 * Dateien:
 * - DataPageLister.module.php   → Modul-Shell, Hooks, Orchestrierung
 * - DataPageListerTree.php      → PageTree-Hooks (Kinder ausblenden, Aktion umbenennen)
 * - DataPageListerFilter.php    → Selector-/Filter-Logik
 * - DataPageListerRender.php    → Render-UI (Styles, Header, Filter, Tabelle, Pager)
 */

require_once __DIR__ . '/DataPageListerTree.php';
require_once __DIR__ . '/DataPageListerFilter.php';
require_once __DIR__ . '/DataPageListerRender.php';

class DataPageLister extends WireData implements Module, ConfigurableModule {

  public static function getModuleInfo() {
    return [
      'title'       => 'Data Page Lister',
      'version'     => 102, // v1.0.2
      'summary'     => 'Tabellarische Übersicht für Daten-Containerseiten im Admin.',
      'author'      => 'Tom & ChatGPT',
      'autoload'    => true,
      'singular'    => true,
      'icon'        => 'table',
      'requires'    => [ 'ProcessWire>=3.0.200' ],
      'priority'    => 110, // wichtig für Page::listable Hook
    ];
  }

  public function __construct() {
    parent::__construct();
    // Standardwerte
    $this->set('parentPathPrefix', '/data');          // Pfad-Präfix, unter dem Container liegen
    $this->set('containerTemplate', 'data_container'); // Template-Name von Container-Seiten
    $this->set('numChildFields', 5);                  // Anzahl Felder zusätzlich zu Titel
    $this->set('pageSize', 50);                       // Pagination
    $this->set('showHelpText', true);                 // Hinweisbox anzeigen
    $this->set('fieldSelectionMode', 'firstN');       // 'firstN' | 'common'
    $this->set('hideChildrenInTree', true);           // Kinder im PageTree ausblenden
    $this->set('renameEditToTable', true);            // „Bearbeiten“ → „Tabelle“ im Tree
    $this->set('showViewButton', false);              // „Ansehen“-Button in Aktionen
  }

  public function init() {
    // Admin-Edit-Form umbauen
    $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'hookBuildForm');

    // PageTree: Kinder ausblenden (BEFORE + final)
    if($this->hideChildrenInTree) {
      $this->addHookBefore('Page::listable', function(HookEvent $event) {
        DataPageListerTree::hookPageListable($this, $event);
      });
    }

    // PageTree: „Bearbeiten“ → „Tabelle“
    if($this->renameEditToTable) {
      $this->addHookAfter('ProcessPageListRender::getPageActions', function(HookEvent $event) {
        DataPageListerTree::hookPageListActionsRename($this, $event);
      });
    }
  }

  /**
   * Ersetzt die Edit-Ansicht eines Container-Parents durch unsere Übersicht
   */
  public function hookBuildForm(HookEvent $event) {
    /** @var ProcessPageEdit $process */
    $process = $event->object;
    $page    = $process->getPage();
    if(!$page || !$page->id) return;

    // Nur unter Präfix & bei Container-Seiten
    if(!$this->isUnderPrefix($page)) return;
    if(!$this->isDataContainer($page)) return;

    // Kind-Templates ermitteln
    $childTemplates = $this->getChildTemplates($page);
    if(!count($childTemplates)) return;

    // Sichtbare Spalten bestimmen
    $fieldNames = $this->selectDisplayFields($childTemplates, (int)$this->numChildFields, (string)$this->fieldSelectionMode);

    // Selector inkl. Filter/Sort/Dir
    [$selector, $active, $allowed] = DataPageListerFilter::buildSelector(
      $page,
      $fieldNames,
      $this->input,      // WireInput
      $this->sanitizer   // Sanitizer
    );

    // Pagination
    $pageNum = max(1, (int) $this->sanitizer->int($this->input->get('pg')));
    $limit   = (int)$this->pageSize;
    $start   = ($pageNum - 1) * $limit;

    // Items laden
    $items = $this->pages->find("$selector, start=$start, limit=$limit");
    $total = $this->pages->count($selector);

    // Bestehende Felder verstecken, Box einfügen
    /** @var InputfieldForm $form */
    $form = $event->return;
    foreach($form->getAll() as $f) $f->collapsed = Inputfield::collapsedHidden;

    /** @var InputfieldMarkup $box */
    $box = $this->modules->get('InputfieldMarkup');
    $box->name      = 'data_page_lister';
    $box->label     = $this->_('Daten-Übersicht');
    $box->collapsed = Inputfield::collapsedNever;
    $box->value     = DataPageListerRender::overview(
      $page,                         // Page (nicht $this)
      $fieldNames,                   // sichtbare Spalten
      $items,                        // Treffer
      $total,                        // Gesamt
      $pageNum,                      // aktuelle Seite
      $limit,                        // Limit
      $active,                       // aktive Filter (by/q)
      $childTemplates,               // erkannte Kind-Templates
      $allowed,                      // erlaubte Felder für Suche/Sort
      $this->config->urls->admin,    // Admin-Basis-URL
      (bool)$this->showHelpText,     // Hinweis aus Config
      (bool)$this->showViewButton    // „Ansehen“-Button aus Config
    );

    $form->prepend($box);
    $event->return = $form;
  }

  /* ===================== Helper: Template-/Feldermittlung ===================== */

  /** Kind-Templates anhand vorhandener Kinder oder Template-Definitionen ermitteln */
  protected function getChildTemplates(Page $parent) : array {
    $tpls = [];
    foreach($parent->children('limit=2') as $c) {
      $tpls[$c->template->name] = $c->template;
    }
    // Fallback: erlaubte Kind-Templates des Parents
    if(!count($tpls) && $parent->template && count($parent->template->childTemplates)) {
      foreach($parent->template->childTemplates as $t) {
        $tpls[$t->name] = $t;
      }
    }
    return array_values($tpls);
  }

  /** Sichtbare Felder bestimmen (erste N oder Schnittmenge) */
  protected function selectDisplayFields(array $childTemplates, int $n, string $mode='firstN') : array {
    $n = max(0, $n);
    if(!count($childTemplates) || $n === 0) return [];

    if($mode === 'common' && count($childTemplates) > 1) {
      $firstList = $this->listAllowedFieldNames($childTemplates[0]);
      $common = $firstList;
      foreach(array_slice($childTemplates, 1) as $tpl) {
        $set = $this->listAllowedFieldNames($tpl);
        $common = array_values(array_intersect($common, $set));
      }
      return array_slice($common, 0, $n);
    }

    return array_slice($this->listAllowedFieldNames($childTemplates[0]), 0, $n);
  }

  /** Erlaubte Feldnamen (Systemfelder raus, Typ-Whitelist) */
  protected function listAllowedFieldNames(Template $tpl) : array {
    $out = [];
    foreach($tpl->fieldgroup as $f) {
      if($this->isSystemOrTitle($f)) continue;
      if(!$this->isAllowedField($f)) continue;
      $out[] = $f->name;
    }
    return $out;
  }

  protected function isSystemOrTitle(Field $f) : bool {
    return in_array($f->name, ['title','name','sort','created','modified','status'], true);
  }

  protected function isAllowedField(Field $f) : bool {
    $allowedTypes = [
      'FieldtypeText','FieldtypeTextarea','FieldtypePageTitle',
      'FieldtypeInteger','FieldtypeFloat','FieldtypeCheckbox',
      'FieldtypeDatetime','FieldtypeEmail','FieldtypeURL',
      'FieldtypeOptions','FieldtypePage'
    ];
    return in_array($f->type->className(), $allowedTypes, true);
  }

  /* ===================== Helper: Pfad-/Template-Prüfung ===================== */

  /** Liegt die Seite unterhalb des konfigurierten Präfixes (/data …)? */
  public function isUnderPrefix(Page $p) : bool {
    $prefix = '/' . trim(strtolower((string)$this->parentPathPrefix), '/');
    $path   = strtolower($p->path);
    return ($path === $prefix . '/' || strpos($path, $prefix . '/') === 0);
  }

  /** Ist die Seite ein Daten-Container (per Template-Name)? */
  public function isDataContainer(Page $p) : bool {
    $tpl = $p->template ? $p->template->name : '';
    return $tpl === (string)$this->containerTemplate; // Standard: data_container
  }

  /* ===================== Modul-Konfiguration ===================== */

  public static function getModuleConfigInputfields(array $data) : InputfieldWrapper {
    $m = wire('modules'); $w = new InputfieldWrapper();

    $f = $m->get('InputfieldText');
    $f->attr('name','parentPathPrefix');
    $f->label = 'Pfad-Präfix für Datenzweig';
    $f->description = 'Alle Seiten unterhalb dieses Pfads gelten als Daten-Container-Kandidaten (z. B. /data).';
    $f->value = $data['parentPathPrefix'] ?? '/data';
    $w->add($f);

    $f = $m->get('InputfieldText');
    $f->attr('name','containerTemplate');
    $f->label = 'Template-Name der Container-Seiten';
    $f->description = 'Standard: data_container';
    $f->value = $data['containerTemplate'] ?? 'data_container';
    $w->add($f);

    $f = $m->get('InputfieldInteger');
    $f->attr('name','numChildFields');
    $f->label = 'Anzahl Felder zusätzlich zu Titel';
    $f->value = (int)($data['numChildFields'] ?? 5);
    $w->add($f);

    $f = $m->get('InputfieldSelect');
    $f->attr('name','fieldSelectionMode');
    $f->label = 'Feldwahl-Modus';
    $f->addOptions([
      'firstN' => 'Erste N Felder',
      'common' => 'Schnittmenge aller Kind-Templates',
    ]);
    $f->value = $data['fieldSelectionMode'] ?? 'firstN';
    $w->add($f);

    $f = $m->get('InputfieldInteger');
    $f->attr('name','pageSize');
    $f->label = 'Seitengröße (Pagination)';
    $f->value = (int)($data['pageSize'] ?? 50);
    $w->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','showHelpText');
    $f->label = 'Hinweistext anzeigen';
    $f->checked = (bool)($data['showHelpText'] ?? true);
    $w->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','showViewButton');
    $f->label = '„Ansehen“-Button in Aktionen anzeigen';
    $f->checked = (bool)($data['showViewButton'] ?? false);
    $w->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','hideChildrenInTree');
    $f->label = 'Kinder im Seitenbaum ausblenden (unter Präfix + Container)';
    $f->checked = (bool)($data['hideChildrenInTree'] ?? true);
    $w->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','renameEditToTable');
    $f->label = 'Im Seitenbaum „Bearbeiten“ → „Tabelle“ umbenennen (für Container-Seiten)';
    $f->checked = (bool)($data['renameEditToTable'] ?? true);
    $w->add($f);

    return $w;
  }

}
