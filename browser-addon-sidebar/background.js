(() => {
  // When the extension icon is clicked, open the side panel
  chrome.action.onClicked.addListener(() => {
    openSidePanel();
  });

  const openSidePanel = () => {
    chrome.windows.getCurrent({ populate: true }, (window) => {
      chrome.sidePanel.open({ windowId: window.id });
    });
  };
})();