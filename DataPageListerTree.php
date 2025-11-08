<?php namespace ProcessWire;

/**
 * DataPageListerTree
 * - Versteckt Kinder im PageTree, sofern deren Parent ein Daten-Container ist
 * - Benennt im PageTree die Aktion "Bearbeiten" -> "Tabelle" für Container-Seiten um
 */
class DataPageListerTree extends Wire {

  /**
   * Kinder im Seitenbaum ausblenden
   * Muss als BEFORE-Hook laufen und $event->replace=true setzen.
   */
  public static function hookPageListable(DataPageLister $mod, HookEvent $event) : void {
    /** @var Page $page */
    $page = $event->object;
    $parent = $page->parent;
    if(!$parent || !$parent->id) return;

    // nur im ProcessPageList-Kontext eingreifen (vermeidet Seiteneffekte)
    $process = wire('process');
    if(!$process instanceof \ProcessWire\ProcessPageList) return;

    // Prüfen ob Parent ein Container ist (template-basiert)
    if($mod->isDataContainer($parent)) {
      $event->return  = false;     // Kind nicht listbar
      $event->replace = true;      // finaler Return
    }
  }

  /**
   * PageTree-Aktion "Bearbeiten" -> "Tabelle" bei Container-Seiten
   */
  public static function hookPageListActionsRename(DataPageLister $mod, HookEvent $event) : void {
    /** @var Page $page */
    $page = $event->arguments(0);
    $actions = $event->return;
    if(!$page || !$page->id) return;

    // Prüfen ob dies ein Container ist (template-basiert)
    if(!$mod->isDataContainer($page)) return;

    foreach($actions as &$a) {
      if(($a['name'] ?? '') === 'edit') {
        $a['label'] = wire()->_('Tabelle');
        $a['icon']  = 'table';
      }
    }
    $event->return = $actions;
  }
}