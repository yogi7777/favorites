(function() {
    try {
      const url = new URLSearchParams(window.location.search).get("url");
      if (url && url === "https://your-url.ch") {
        document.getElementById("iframe").src = url;
      }
    } catch (error) {
      console.error("Error loading iframe:", error);
    }
  })();