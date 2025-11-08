<?php namespace ProcessWire;

/**
 * DataPageLister (v1.2.2)
 *
 * Generische Admin-Übersicht für Daten-Containerseiten:
 * - Ersetzt die Edit-Maske der Elternseite durch eine Tabelle ihrer Kinder
 * - Dynamische Spalten (Titel + erste N Felder aus dem Kind-Template)
 * - Filterleiste (Feldwahl, Suche, Sortierung, Richtung), Pagination
 * - „Neu anlegen"-Button, Aktionen (Bearbeiten, optional Ansehen)
 * - PageTree: Kinder unter Daten-Containern ausblenden, „Bearbeiten" → „Tabelle"
 *
 * ÄNDERUNG v1.2.2: Robustes Config-System mit flachen Feldern
 */

require_once __DIR__ . '/DataPageListerTree.php';
require_once __DIR__ . '/DataPageListerFilter.php';
require_once __DIR__ . '/DataPageListerRender.php';

class DataPageLister extends WireData implements Module, ConfigurableModule {

  public static function getModuleInfo() {
    return [
      'title'       => 'Data Page Lister',
      'version'     => 122, // v1.2.2
      'summary'     => 'Tabellarische Übersicht für Daten-Containerseiten im Admin.',
      'author'      => 'Tom & ChatGPT',
      'autoload'    => true,
      'singular'    => true,
      'icon'        => 'table',
      'requires'    => [ 'ProcessWire>=3.0.200' ],
      'priority'    => 110,
    ];
  }

  public function __construct() {
    parent::__construct();
    // Standardwerte
    $this->set('numConfigs', 1);                      // Anzahl Konfigurationen
    $this->set('pageSize', 50);                       // Pagination
    $this->set('showHelpText', true);                 // Hinweisbox anzeigen
    $this->set('hideChildrenInTree', true);           // Kinder im PageTree ausblenden
    $this->set('renameEditToTable', true);            // „Bearbeiten" → „Tabelle" im Tree
    $this->set('showViewButton', false);              // „Ansehen"-Button in Aktionen
  }

  public function init() {
    // Admin-Edit-Form umbauen
    $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'hookBuildForm');

    // CSS einbinden wenn im Admin
    $this->addHookAfter('ProcessController::execute', $this, 'hookLoadAssets');

    // PageTree: Kinder ausblenden (BEFORE + final)
    if($this->hideChildrenInTree) {
      $this->addHookBefore('Page::listable', function(HookEvent $event) {
        DataPageListerTree::hookPageListable($this, $event);
      });
    }

    // PageTree: „Bearbeiten" → „Tabelle"
    if($this->renameEditToTable) {
      $this->addHookAfter('ProcessPageListRender::getPageActions', function(HookEvent $event) {
        DataPageListerTree::hookPageListActionsRename($this, $event);
      });
    }
  }

  /**
   * Lädt CSS im Admin-Bereich
   */
  public function hookLoadAssets(HookEvent $event) {
    // Nur im Admin
    if($this->page && $this->page->template && $this->page->template->name === 'admin') {
      $cssUrl = $this->config->urls->siteModules . $this->className . '/' . $this->className . '.css';
      $this->config->styles->add($cssUrl);
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

    // Prüfen ob diese Seite ein aktivierter Container ist
    if(!$this->isDataContainer($page)) return;

    // Kind-Templates ermitteln
    $childTemplates = $this->getChildTemplates($page);
    if(!count($childTemplates)) return;

    // Sichtbare Spalten bestimmen (neue Logik mit Template-Config)
    $fieldNames = $this->selectDisplayFieldsFromConfig($page, $childTemplates);

    // Selector inkl. Filter/Sort/Dir
    [$selector, $active, $allowed] = DataPageListerFilter::buildSelector(
      $page,
      $fieldNames,
      $this->input,
      $this->sanitizer
    );

    // Pagination
    $pageNum = max(1, (int) $this->sanitizer->int($this->input->get('pg')));
    $limit   = (int)$this->pageSize;
    $start   = ($pageNum - 1) * $limit;

    // Items laden
    $items = $this->pages->find("$selector, start=$start, limit=$limit");
    $total = $this->pages->count($selector);

    // Bestehende Felder einklappen (aber sichtbar lassen), Box einfügen
    /** @var InputfieldForm $form */
    $form = $event->return;
    foreach($form->getAll() as $f) {
      if($f->collapsed == Inputfield::collapsedNever) continue;
      $f->collapsed = Inputfield::collapsedYes;
    }

    /** @var InputfieldMarkup $box */
    $box = $this->modules->get('InputfieldMarkup');
    $box->name      = 'data_page_lister';
    $box->label     = $this->_('Daten-Übersicht');
    $box->collapsed = Inputfield::collapsedNever;
    $box->value     = DataPageListerRender::overview(
      $page,
      $fieldNames,
      $items,
      $total,
      $pageNum,
      $limit,
      $active,
      $childTemplates,
      $allowed,
      $this->config->urls->admin,
      (bool)$this->showHelpText,
      (bool)$this->showViewButton
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

  /**
   * Sichtbare Felder aus Template-Config bestimmen
   */
  protected function selectDisplayFieldsFromConfig(Page $parent, array $childTemplates) : array {
    if(!count($childTemplates)) return [];

    $parentTemplate = $parent->template->name;
    $config = $this->getTemplateConfig($parentTemplate);

    // Wenn Template-Config existiert und Modus "manual" ist
    if($config && isset($config['mode']) && $config['mode'] === 'manual') {
      $manualFields = $this->parseFieldList($config['fields'] ?? '');
      
      if(count($manualFields)) {
        // Prüfe welche Felder tatsächlich im Kind-Template existieren
        $availableFields = $this->listAllowedFieldNames($childTemplates[0]);
        $validFields = array_intersect($manualFields, $availableFields);
        
        if(count($validFields)) {
          return array_values($validFields);
        }
      }
    }

    // Fallback: Automatische Erkennung (alte Logik)
    $numFields = isset($config['numFields']) ? (int)$config['numFields'] : 5;
    
    if(isset($config['fieldSelectionMode']) && $config['fieldSelectionMode'] === 'common' && count($childTemplates) > 1) {
      return $this->selectCommonFields($childTemplates, $numFields);
    }

    return array_slice($this->listAllowedFieldNames($childTemplates[0]), 0, $numFields);
  }

  /**
   * Schnittmenge aller Templates (für common-Modus)
   */
  protected function selectCommonFields(array $childTemplates, int $n) : array {
    $firstList = $this->listAllowedFieldNames($childTemplates[0]);
    $common = $firstList;
    foreach(array_slice($childTemplates, 1) as $tpl) {
      $set = $this->listAllowedFieldNames($tpl);
      $common = array_values(array_intersect($common, $set));
    }
    return array_slice($common, 0, $n);
  }

  /**
   * Holt die Config für ein bestimmtes Template
   */
  protected function getTemplateConfig(string $templateName) : ?array {
    $numConfigs = (int)$this->numConfigs;
    
    for($i = 0; $i < $numConfigs; $i++) {
      $tpl = $this->get("config_{$i}_template");
      if($tpl === $templateName) {
        return [
          'template' => $tpl,
          'mode' => $this->get("config_{$i}_mode") ?: 'auto',
          'numFields' => (int)$this->get("config_{$i}_numFields") ?: 5,
          'fieldSelectionMode' => $this->get("config_{$i}_fieldSelectionMode") ?: 'firstN',
          'fields' => $this->get("config_{$i}_fields") ?: ''
        ];
      }
    }
    
    return null;
  }

  /**
   * Parst komma-separierte Feldliste in Array
   */
  protected function parseFieldList(string $fields) : array {
    if(empty($fields)) return [];
    $parts = explode(',', $fields);
    return array_values(array_filter(array_map('trim', $parts)));
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

  /* ===================== Helper: Template-Prüfung ===================== */

  /**
   * Ist die Seite ein Daten-Container?
   * Prüft ob Template in Configs definiert ist
   */
  public function isDataContainer(Page $p) : bool {
    if(!$p->template) return false;
    
    $templateName = $p->template->name;
    $numConfigs = (int)$this->numConfigs;
    
    for($i = 0; $i < $numConfigs; $i++) {
      $tpl = $this->get("config_{$i}_template");
      if($tpl === $templateName) {
        return true;
      }
    }
    
    return false;
  }

  /* ===================== Modul-Konfiguration ===================== */

  public static function getModuleConfigInputfields(array $data) : InputfieldWrapper {
    $m = wire('modules'); 
    $w = new InputfieldWrapper();

    // Anzahl Konfigurationen
    $numConfigs = isset($data['numConfigs']) ? (int)$data['numConfigs'] : 1;
    
    // Wenn numConfigs 0 ist, setze mindestens 1
    if($numConfigs < 1) $numConfigs = 1;

    // Template-Konfigurationen
    for($i = 0; $i < $numConfigs; $i++) {
      $fieldset = $m->get('InputfieldFieldset');
      $fieldset->label = 'Konfiguration ' . ($i + 1);
      $fieldset->collapsed = Inputfield::collapsedNo;
      $fieldset->icon = 'cog';

      // Template-Name
      $f = $m->get('InputfieldText');
      $f->attr('name', "config_{$i}_template");
      $f->label = 'Template-Name';
      $f->description = 'Name des Container-Templates (z.B. "blog", "projekte")';
      $f->required = true;
      $f->columnWidth = 50;
      $f->value = $data["config_{$i}_template"] ?? '';
      $fieldset->add($f);

      // Modus
      $f = $m->get('InputfieldRadios');
      $f->attr('name', "config_{$i}_mode");
      $f->label = 'Feldauswahl-Modus';
      $f->addOption('auto', 'Automatisch (erste N Felder)');
      $f->addOption('manual', 'Manuell (Felder auflisten)');
      $f->value = $data["config_{$i}_mode"] ?? 'auto';
      $f->columnWidth = 50;
      $f->optionColumns = 1;
      $fieldset->add($f);

      // Automatik-Optionen
      $autoFieldset = $m->get('InputfieldFieldset');
      $autoFieldset->label = 'Automatik-Einstellungen';
      $autoFieldset->showIf = "config_{$i}_mode=auto";
      $autoFieldset->collapsed = Inputfield::collapsedNo;

      $f = $m->get('InputfieldInteger');
      $f->attr('name', "config_{$i}_numFields");
      $f->label = 'Anzahl Felder';
      $f->value = isset($data["config_{$i}_numFields"]) ? (int)$data["config_{$i}_numFields"] : 5;
      $f->columnWidth = 50;
      $autoFieldset->add($f);

      $f = $m->get('InputfieldSelect');
      $f->attr('name', "config_{$i}_fieldSelectionMode");
      $f->label = 'Auswahl-Modus';
      $f->addOption('firstN', 'Erste N Felder');
      $f->addOption('common', 'Schnittmenge aller Kind-Templates');
      $f->value = $data["config_{$i}_fieldSelectionMode"] ?? 'firstN';
      $f->columnWidth = 50;
      $autoFieldset->add($f);

      $fieldset->add($autoFieldset);

      // Manuelle Feldliste
      $f = $m->get('InputfieldTextarea');
      $f->attr('name', "config_{$i}_fields");
      $f->label = 'Felder (komma-separiert)';
      $f->description = 'Liste der anzuzeigenden Feldnamen, z.B.: date, summary, author, tags';
      $f->notes = 'Felder die nicht im Kind-Template existieren werden automatisch übersprungen.';
      $f->showIf = "config_{$i}_mode=manual";
      $f->rows = 3;
      $f->value = $data["config_{$i}_fields"] ?? '';
      $fieldset->add($f);

      $w->add($fieldset);
    }

    // Anzahl Konfigurationen ändern
    $f = $m->get('InputfieldInteger');
    $f->attr('name', 'numConfigs');
    $f->label = 'Anzahl Template-Konfigurationen';
    $f->description = 'Erhöhe diese Zahl, um weitere Templates hinzuzufügen. Verringere sie, um Konfigurationen zu entfernen.';
    $f->notes = 'Nach dem Ändern: Speichern und Seite neu laden.';
    $f->value = $numConfigs;
    $f->min = 1;
    $f->columnWidth = 50;
    $w->add($f);

    // Weitere Einstellungen
    $settingsFieldset = $m->get('InputfieldFieldset');
    $settingsFieldset->label = 'Weitere Einstellungen';
    $settingsFieldset->collapsed = Inputfield::collapsedYes;

    $f = $m->get('InputfieldInteger');
    $f->attr('name','pageSize');
    $f->label = 'Seitengröße (Pagination)';
    $f->value = (int)($data['pageSize'] ?? 50);
    $settingsFieldset->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','showHelpText');
    $f->label = 'Hinweistext anzeigen';
    $f->checked = (bool)($data['showHelpText'] ?? true);
    $settingsFieldset->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','showViewButton');
    $f->label = '„Ansehen"-Button in Aktionen anzeigen';
    $f->checked = (bool)($data['showViewButton'] ?? false);
    $settingsFieldset->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','hideChildrenInTree');
    $f->label = 'Kinder im Seitenbaum ausblenden';
    $f->checked = (bool)($data['hideChildrenInTree'] ?? true);
    $settingsFieldset->add($f);

    $f = $m->get('InputfieldCheckbox');
    $f->attr('name','renameEditToTable');
    $f->label = 'Im Seitenbaum „Bearbeiten" → „Tabelle" umbenennen';
    $f->checked = (bool)($data['renameEditToTable'] ?? true);
    $settingsFieldset->add($f);

    $w->add($settingsFieldset);

    return $w;
  }

}