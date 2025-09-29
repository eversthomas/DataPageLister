<?php namespace ProcessWire;

/**
 * DataPageListerTree
 * - Versteckt Kinder im PageTree, sofern deren Parent ein Daten-Container unterhalb des Präfixes ist
 * - Bennent im PageTree die Aktion "Bearbeiten" -> "Tabelle" für Container-Seiten um
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

    if($mod->isDataContainer($parent) && $mod->isUnderPrefix($parent)) {
      $event->return  = false;     // Kind nicht listbar
      $event->replace = true;      // finaler Return
    }
  }

  /**
   * PageTree-Aktion "Bearbeiten" -> "Tabelle" bei Container-Eltern unterhalb des Präfixes
   */
  public static function hookPageListActionsRename(DataPageLister $mod, HookEvent $event) : void {
    /** @var Page $page */
    $page = $event->arguments(0);
    $actions = $event->return;
    if(!$page || !$page->id) return;

    if(!$mod->isUnderPrefix($page) || !$mod->isDataContainer($page)) return;

    foreach($actions as &$a) {
      if(($a['name'] ?? '') === 'edit') {
        $a['label'] = wire()->_('Tabelle');
        $a['icon']  = 'table';
      }
    }
    $event->return = $actions;
  }
}
