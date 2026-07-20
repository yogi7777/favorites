(() => {
  // Beim Klick auf das Icon die Sidebar öffnen
  browser.action.onClicked.addListener(() => {
    browser.sidebarAction.open();
  });
})();