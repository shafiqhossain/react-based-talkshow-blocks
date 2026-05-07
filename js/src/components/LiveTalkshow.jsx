import React, { useEffect, useState } from "react";
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
  if (data.data === undefined ||
    data.data === null ||
    data.data.length === 0 ) {
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
const LiveTalkshow = (props) => {
  const [content, updateContent] = useState([]);
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    const csrfUrl = props.siteUrl + `/session/token`;
    const fetchUrl = props.siteUrl + `/api/v1/bbsradio/station/live-talkshow`;

    let op_data = {};
    op_data.station_names = props.stationNames;

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
            var newData = convertObjectToArray(data.data);
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
        <div className="section-live-talkshow">
          <div className="region-live-talkshow">
            {data &&
              data.map((item, index) => (
                <div className={"block-live-talkshow-wrapper live-talkshow-" + item.station_id} key={item.station_id}>
                  <div className="block-live-talkshow-inner">
                    <div className="block--title">
                      <div className="block--title-left">
                        <div className="block--sub-title">Talk Show airing on</div>
                        <div className="block--station-name">{item.station_name}</div>
                      </div>
                      <div className="block--title-right">
                        {(item.field_include_audio_or_video) ? (
                          <div className="block--field-include-audio-or-video">
                            {parse(item.field_include_audio_or_video)}
                          </div>) : ""
                        }
                      </div>
                    </div>
                    <div className="block--inner-content">
                      <div className="block--item">
                        <div className="block--host-info">
                          <div className="block--host-info-left">
                            <div className="block--field-include-show-page">
                              {(item.field_include_show_page) ?
                                (parse(item.field_include_show_page)) : ""
                              }
                              &nbsp;&nbsp;<span>with</span>&nbsp;&nbsp;
                              {(item.field_include_host_name) ?
                                (parse(item.field_include_host_name)) : ""
                              }
                            </div>
                          </div>
                          <div className="block--host-info-right">
                            {(item.field_include_host_picture) ? (
                              <div className="block--field-include-host-picture">
                                {parse(item.field_include_host_picture)}
                              </div>) : ""
                            }
                          </div>
                        </div>
                        {(item.station_description) ? (
                          <div className="block--field-station-description">
                            {parse(item.station_description)}
                          </div>) : ""
                        }
                        {(item.field_include_banner) ? (
                          <div className="block--field-include-banner">
                            {parse(item.field_include_banner)}
                          </div>) : ""
                        }

                        <div className="block--archive-detail-wrapper">
                          {(item.field_talk_show_detail) ? (
                            <div className="block--field-talk-show-detail">
                              <div className="block--label-field-talk-show-detail">Summary:</div>
                              <div className="block--field-content">
                                {parse(item.field_talk_show_detail)}
                              </div>
                            </div>) : ""
                          }
                          {(item.field_include_show_categories) ? (
                            <div className="block--field-include-show-categories">
                              <div className="block--label-field-include-show-categories">Categories:</div>
                              <div className="block--field-content">
                                {parse(item.field_include_show_categories)}
                              </div>
                            </div>) : ""
                          }
                        </div>

                        {(item.field_schedule_weekly_or_biweekl) && (item.field_schedule_broadcast_day) ? (
                          <div className="block--field-schedule-weekly-or-biweekly">
                            <span className="schedule">{item.field_schedule_weekly_or_biweekl}</span> <span
                            className="separator">-</span> <span className="station-name">{item.station_name}</span>
                            <span className="separator">-</span> <span
                            className="schedule-day">{item.field_schedule_broadcast_day}</span>
                          </div>) : ""
                        }

                        <div className="block--archive-links-wrapper">
                          {(item.field_include_program_archives) ? (
                            <div className="block--field-include-program-archives">
                              {parse(item.field_include_program_archives)}
                            </div>) : ""
                          }
                          {(item.field_include_show_feed) ? (
                            <div className="block--field-include-show-feed">
                              {parse(item.field_include_show_feed)}
                            </div>) : ""
                          }
                          {(item.field_include_mrss_feed) ? (
                            <div className="block--field-include-mrss-feed">
                              {parse(item.field_include_mrss_feed)}
                            </div>) : ""
                          }
                          {(item.field_include_coming_up_soon) ? (
                            <div className="block--field-include-coming-up-soon">
                              {parse(item.field_include_coming_up_soon)}
                            </div>) : ""
                          }
                          {(item.field_include_upcoming_show) ? (
                            <div className="block--field-include-upcoming-show">
                              {parse(item.field_include_upcoming_show)}
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


export default LiveTalkshow;
