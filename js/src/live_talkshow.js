import React from 'react'
import ReactDOM from 'react-dom/client'
import LiveTalkshow from "./components/LiveTalkshow";

jQuery(document).ready(function () {
  // Get the container for your app
  const container = document.getElementById('station-live-talkshow-root');

  // Check if the container exists to avoid null errors
  if (container) {
    // Create a root
    const root = ReactDOM.createRoot(container);

    // Render the LiveTalkshow component
    root.render(
      <React.StrictMode>
        <LiveTalkshow stationNames={drupalSettings.reactApp.station_names} siteUrl={drupalSettings.reactApp.site_url} />
      </React.StrictMode>
    );
  }
  else {
    console.error('Failed to find the root element');
  }
});
