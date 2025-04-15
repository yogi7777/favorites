document.addEventListener("DOMContentLoaded", function() {
    const itcrmIframe = document.getElementById("itcrm-iframe");
    const reloadBtn = document.getElementById("reload-btn");
    
    // Set the direct URL to your website
    function loadItcrmFavorites() {
      itcrmIframe.src = "https://your-url.ch";
    }
    
    // Load the website when the sidebar is opened
    loadItcrmFavorites();
  });