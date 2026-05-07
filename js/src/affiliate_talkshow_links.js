import React from 'react'
import ReactDOM from 'react-dom/client'
import AffiliateTalkshowLinks from "./components/AffiliateTalkshowLinks";

jQuery(document).ready(function () {
  // Get the container for your app
  const container = document.getElementById('affiliate-talkshow-links-root');

  // Check if the container exists to avoid null errors
  if (container) {
    // Create a root
    const root = ReactDOM.createRoot(container);

    // Render the AffiliateTalkshowLinks component
    root.render(
      <React.StrictMode>
        <AffiliateTalkshowLinks
          talkshowId={drupalSettings.reactApp.talkshow_nid}
          siteUrl={drupalSettings.reactApp.site_url}
        />
      </React.StrictMode>
    );
  }
  else {
    console.error('Failed to find the root element');
  }
});
