import React, {useEffect, useState} from "react";
import {fetchWithCSRFToken} from "../utils/fetch";
import parse from 'html-react-parser';

const progressBar = 'modules/custom/custom_example/js/dist/images/progress-bar-circle.gif';

/**
 * Helper function to validate data retrieved from JSON:API.
 */
function isValidData(data) {
  if (data === null) {
    return false;
  }
  if (data.data === undefined || data.data === null || data.data.length === 0 ) {
    return false;
  }
  return true;
}

/**
 * Helper function to convert nested object data to nested array data.
 */
function convertObjectToArray(data) {
  let newData = []
  for (const [key, value] of Object.entries(data)) {
    let newValues = []
    for (const [innerKey, innerValue] of Object.entries(value)) {
      newValues[innerKey] = innerValue
    }
    newData[key] = newValues
  }

  return newData;
}

/**
 * Display a list of Drupal article nodes.
 *
 * Retrieves articles from Drupal's JSON:API and then displays them along with
 * admin features to create, update, and delete articles.
 */
const AffiliateTalkshowLinks = (props) => {
  const [content, updateContent] = useState([]);
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const csrfUrl = props.siteUrl + `/session/token`;
    const fetchUrl = props.siteUrl + `/api/v1/bbsradio/affiliate-talkshow-links`;

    let op_data = {};
    op_data.talkshow_nid = props.talkshowId;

    const fetchOptions = {
      method: 'POST',
      credentials: 'same-origin',
      headers: new Headers({
        'Accept': 'application/vnd.api+json',
        'Content-Type': 'application/vnd.api+json',
        'Cache': 'no-cache'
      }),
      body: JSON.stringify(op_data),
    };


    async function fetchData() {
      await fetchWithCSRFToken(csrfUrl, fetchUrl, fetchOptions)
        .then((response) => response.json())
        .then((data) => {
          if (isValidData(data)) {
            var arrData = convertObjectToArray(data.data);
            var newData = [];

            arrData.forEach(function (data_item, key) {
              var live_data = [];
              var l_data;
              if (data_item.live_data == null) {
                l_data = [];
              }
              else {
                l_data = convertObjectToArray(data_item.live_data);
              }

              l_data.forEach(function (l_data_item, l_key) {
                if (l_data_item !== null) {
                  live_data[l_key] = l_data_item;
                }
              });
              data_item.live_data = live_data;
              newData[key] = data_item
            });

            setData(newData);
            setLoading(false);
          }
          else {
            setError('');
            setLoading(false);
          }
        })
        .catch(
          function(error) {
            setError(error.message);
            setLoading(false);
          }
        )
    }

    // Fetch after every 5 minutes = 300000 ms
    fetchData();
    const interval = setInterval(fetchData, 300000)

    return () => {
      clearInterval(interval)
    }
  }, []);

  return (
    <div>
      {loading ? (
        <p className="block--progress-bar"><img src={window.location.origin + '/' + progressBar} alt="Loading...."/>
        </p>
      ) : error ? (
        <p>Error: {error}</p>
      ) : (
        <div className="section-affiliate-talkshow-links">
          <div className="region-affiliate-talkshow-links">
            {data &&
              data.map((item) => (
                <div className="block-affiliate-talkshow-links-wrapper" key={item.talkshow_nid}>
                  <div className="block-affiliate-talkshow-links-inner">
                    {item.is_live && item.live_data ? (
                      <div className="block-affiliate-live-info">
                      {item.is_live && item.live_data && item.live_data.map((live_item) => (
                            <div className="block-affiliate-live-station-info">
                              <div className="block-affiliate-live-on-air">ON AIR</div>
                              <div className="block-affiliate-live-station">
                                <span className="block-live-station-name">{parse(live_item.station_name)}</span>
                                <a className="block-live-video-image"
                                   href={parse(live_item.station_video_player)}>{parse(live_item.video_image)}</a>
                                <a className="block-live-audio-image"
                                   href={parse(live_item.station_audio_player)}>{parse(live_item.audio_image)}</a>
                              </div>
                          </div>
                      ))}
                      </div>
                    ) : ""}

                    <div className="block-affiliate-talkshow-inner">
                      <div className="block-affiliate-talkshow-header">
                        <h3 className="block-affiliate-title">Live Stream Info</h3>
                        <div className="block-affiliate-download-link-wrapper">
                          <a target="_blank" className="block-affiliate-download-link" href={parse(item.talkshow_file_name)}
                             title="Affiliate Download">Affiliate Download</a>
                        </div>
                      </div>
                      <div className="block-affiliate-talkshow-content">
                        <div className="block-affiliate-talkshow-image-wrapper">
                          {(item.banner_image) ? (
                            <div className="block--field-include-host-picture">
                              {parse(item.banner_image)}
                            </div>) : ""
                          }
                        </div>
                        <div className="block-affiliate-talkshow-links-wrapper">
                          <div className="block-affiliate-header-info">
                            {(item.sub_headline) ? (
                              <h3 className="block-affiliate-header">{parse(item.headline)}</h3>) : ""
                            }
                            {(item.sub_headline) ? (
                              <h5 className="block-affiliate-subheader">{parse(item.sub_headline)}</h5>) : ""
                            }
                          </div>
                          {(item.archive_delivered_file_up_url) ? (
                            <div className="block-affiliate-archive-audio-wrapper">
                              <div className="block-affiliate-archive-audio-image-wrapper">
                                <a className="block-audio-link"
                                   href={parse(item.archive_delivered_file_up_url)}
                                   title="Listen to the latest audio broadcast">
                                  <img
                                    src={parse(item.station_audio_image)}
                                    alt="Station Info" width="50" height="50" title="Station Info"/>
                                </a>
                              </div>
                              <div className="block-affiliate-archive-audio-content-wrapper">
                                <a className="block-audio-link"
                                   href={parse(item.archive_delivered_file_up_url)}
                                   title="Listen to the latest audio broadcast">
                                  <div className="block-audio-broadcast">AUDIO Broadcast</div>
                                  <div className="block-broadcast-date">
                                    {parse(item.broadcast_datetime)}
                                    <i className="fas fa-link"></i>
                                  </div>
                                </a>
                              </div>
                            </div>) : ""
                          }
                          {(item.archive_uploaded_video_url) ? (
                            <div className="block-affiliate-archive-video-wrapper">
                              <div className="block-affiliate-archive-video-image-wrapper">
                                <a className="block-video-link"
                                   href={parse(item.archive_uploaded_video_url)}
                                   title="Watch to the latest video broadcast">
                                  <img
                                    src={parse(item.station_video_image)}
                                    alt="Station Info" width="50" height="50" title="Station Info"/>
                                </a>
                              </div>
                              <div className="block-affiliate-archive-video-content-wrapper">
                                <a className="block-video-link"
                                   href={parse(item.archive_uploaded_video_url)}
                                   title="Watch to the latest video broadcast">
                                  <div className="block-video-broadcast">VIDEO Broadcast</div>
                                  <div className="block-broadcast-date">
                                    {parse(item.broadcast_datetime)}
                                    <i className="fas fa-link"></i>
                                  </div>
                                </a>
                              </div>
                            </div>) : ""
                          }
                        </div>
                        <div className="block-affiliate-talkshow-host-wrapper">
                          {(item.archive_uploaded_video_url) ? (
                            <div className="block-affiliate-talkshow-host-image-wrapper">
                              <img src={parse(item.include_host_picture_url)}
                                   alt={parse(item.include_host_picture_title)} title={parse(item.include_host_picture_title)} />
                            </div>) : ""
                          }

                          {(item.affiliate_stations_info_url) ? (
                            <div className="block-affiliate-stations-info-wrapper">
                              <i className="fas fa-link"></i>
                              <a target="_blank" href={parse(item.affiliate_stations_info_url)} title="Affiliate Stations Info">Stations Info</a>
                            </div>) : ""
                          }
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
          </div>
        </div>
      )}
    </div>
  );
};


export default AffiliateTalkshowLinks;
