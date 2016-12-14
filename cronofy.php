<?php

class CronofyException extends Exception
{
    private $error_details;

    public function __construct($message, $code, $error_details = null)
    {
        $this->error_details = $error_details;

        parent::__construct($message, $code, null);
    }

    public function error_details()
    {
        return $this->error_details;
    }
}

class Cronofy
{
    const USERAGENT = 'Cronofy PHP 0.6';
    const API_ROOT_URL = 'https://api.cronofy.com';
    const API_VERSION = 'v1';

    public $client_id;
    public $client_secret;
    public $access_token;
    public $refresh_token;

    public function __construct($client_id = false, $client_secret = false, $access_token = false, $refresh_token = false)
    {
        if (!function_exists('curl_init')) {
            throw new CronofyException("missing cURL extension", 1);
        }

        if (!empty($client_id)) {
            $this->client_id = $client_id;
        }
        if (!empty($client_secret)) {
            $this->client_secret = $client_secret;
        }
        if (!empty($access_token)) {
            $this->access_token = $access_token;
        }
        if (!empty($refresh_token)) {
            $this->refresh_token = $refresh_token;
        }
    }

    private function http_get($path, array $params = array())
    {
        $url = $this->api_url($path);
        $url .= $this->url_params($params);

        if (filter_var($url, FILTER_VALIDATE_URL)===false) {
            throw new CronofyException('invalid URL');
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->get_auth_headers());
        curl_setopt($curl, CURLOPT_USERAGENT, self::USERAGENT);
        $result = curl_exec($curl);
        if (curl_errno($curl) > 0) {
            throw new CronofyException(curl_error($curl), 2);
        }
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $this->handle_response($result, $status_code);
    }

    private function http_post($path, array $params = array())
    {
        $url = $this->api_url($path);

        if (filter_var($url, FILTER_VALIDATE_URL)===false) {
            throw new CronofyException('invalid URL');
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->get_auth_headers(true));
        curl_setopt($curl, CURLOPT_USERAGENT, self::USERAGENT);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($curl, CURLOPT_VERBOSE, true);
        $result = curl_exec($curl);
        if (curl_errno($curl) > 0) {
            throw new CronofyException(curl_error($curl), 3);
        }
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $this->handle_response($result, $status_code);
    }

    private function http_delete($path, array $params = array())
    {
        $url = $this->api_url($path);

        if (filter_var($url, FILTER_VALIDATE_URL)===false) {
            throw new CronofyException('invalid URL');
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->get_auth_headers(true));
        curl_setopt($curl, CURLOPT_USERAGENT, self::USERAGENT);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
        $result = curl_exec($curl);
        if (curl_errno($curl) > 0) {
            throw new CronofyException(curl_error($curl), 4);
        }
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $this->handle_response($result, $status_code);
    }

    public function getAuthorizationURL($params)
    {
        /*
          Array $params : An array of additional paramaters
          redirect_uri : String The HTTP or HTTPS URI you wish the user's authorization request decision to be redirected to. REQUIRED
          scope : An array of scopes to be granted by the access token. Possible scopes detailed in the Cronofy API documentation. REQUIRED
          state : String A value that will be returned to you unaltered along with the user's authorization request decision. OPTIONAL
          avoid_linking : Boolean when true means we will avoid linking calendar accounts together under one set of credentials. OPTIONAL

          Response :
          String $url : The URL to authorize your access to the Cronofy API
         */

        $scope_list = join(" ", $params['scope']);

        $url = "https://app.cronofy.com/oauth/authorize?response_type=code&client_id=" . $this->client_id . "&redirect_uri=" . urlencode($params['redirect_uri']) . "&scope=" . $scope_list;
        if (!empty($params['state'])) {
            $url.="&state=" . $params['state'];
        }
        if (!empty($params['avoid_linking'])) {
            $url.="&avoid_linking=" . $params['avoid_linking'];
        }
        return $url;
    }

    public function getEnterpriseConnectAuthorizationUrl($params)
    {
        /*
          Array $params : An array of additional parameters
          redirect_uri : String. The HTTP or HTTPS URI you wish the user's authorization request decision to be redirected to. REQUIRED
          scope : Array. An array of scopes to be granted by the access token. Possible scopes detailed in the Cronofy API documentation. REQUIRED
          delegated_scope : Array. An array of scopes to be granted that will be allowed to be granted to the account's users. REQUIRED
          state : String. A value that will be returned to you unaltered along with the user's authorization request decsion. OPTIONAL

          Response :
          $url : String. The URL to authorize your enterprise connect access to the Cronofy API
         */

        $scope_list = rawurlencode(join(" ", $params['scope']));
        $delegated_scope_list = rawurlencode(join(" ", $params['delegated_scope']));

        $url = "https://app.cronofy.com/enterprise_connect/oauth/authorize?response_type=code&client_id=" . $this->client_id . "&redirect_uri=" . urlencode($params['redirect_uri']) . "&scope=" . $scope_list . "&delegated_scope=" . $delegated_scope_list;
        if (!empty($params['state'])) {
            $url.="&state=" . rawurlencode($params['state']);
        }
        return $url;
    }

    public function request_token($params)
    {
        /*
          Array $params : An array of additional paramaters
          redirect_uri : String The HTTP or HTTPS URI you wish the user's authorization request decision to be redirected to. REQUIRED
          code: The short-lived, single-use code issued to you when the user authorized your access to their account as part of an Authorization  REQUIRED

          Response :
          true if successful, error string if not
         */
        $postfields = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'authorization_code',
            'code' => $params['code'],
            'redirect_uri' => $params['redirect_uri']
        );

        $tokens = $this->http_post("/oauth/token", $postfields);

        if (!empty($tokens["access_token"])) {
            $this->access_token = $tokens["access_token"];
            $this->refresh_token = $tokens["refresh_token"];
            return true;
        } else {
            return $tokens["error"];
        }
    }

    public function refresh_token()
    {
        /*
          String $refresh_token : The refresh_token issued to you when the user authorized your access to their account. REQUIRED

          Response :
          true if successful, error string if not
         */
        $postfields = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refresh_token
        );

        $tokens = $this->http_post("/oauth/token", $postfields);

        if (!empty($tokens["access_token"])) {
            $this->access_token = $tokens["access_token"];
            $this->refresh_token = $tokens["refresh_token"];
            return true;
        } else {
            return $tokens["error"];
        }
    }

    public function revoke_authorization($token)
    {
        /*
          String token : Either the refresh_token or access_token for the authorization you wish to revoke. REQUIRED

          Response :
          true if successful, error string if not
         */
        $postfields = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'token' => $token
        );

        return $this->http_post("/oauth/token/revoke", $postfields);
    }

    public function get_account()
    {
        /*
          returns $result - info for the user logged in. Details are available in the Cronofy API Documentation
         */
        return $this->http_get("/" . self::API_VERSION . "/account");
    }

    public function get_profiles()
    {
        /*
          returns $result - list of all the authenticated user's calendar profiles. Details are available in the Cronofy API Documentation
         */
        return $this->http_get("/" . self::API_VERSION . "/profiles");
    }

    public function list_calendars()
    {
        /*
          returns $result - Array of calendars. Details are available in the Cronofy API Documentation
         */
        return $this->http_get("/" . self::API_VERSION . "/calendars");
    }

    public function read_events($params)
    {
        /*
          Date from : The minimum date from which to return events. Defaults to 16 days in the past. OPTIONAL
          Date to : The date to return events up until. Defaults to 201 days in the future. OPTIONAL
          String tzid : A string representing a known time zone identifier from the IANA Time Zone Database. REQUIRED
          Boolean include_deleted : Indicates whether to include or exclude events that have been deleted. Defaults to excluding deleted events. OPTIONAL
          Boolean include_moved: Indicates whether events that have ever existed within the given window should be included or excluded from the results. Defaults to only include events currently within the search window. OPTIONAL
          Time last_modified : The Time that events must be modified on or after in order to be returned. Defaults to including all events regardless of when they were last modified. OPTIONAL
          Boolean include_managed : Indiciates whether events that you are managing for the account should be included or excluded from the results. Defaults to include only non-managed events. OPTIONAL
          Boolean only_managed : Indicates whether only events that you are managing for the account should be included in the results. OPTIONAL
          Array calendar_ids : Restricts the returned events to those within the set of specified calendar_ids. Defaults to returning events from all of a user's calendars. OPTIONAL
          Boolean localized_times : Indicates whether the events should have their start and end times returned with any available localization information. Defaults to returning start and end times as simple Time values. OPTIONAL

          returns $result - Array of events
         */
        $url = $this->api_url("/" . self::API_VERSION . "/events");

        return new PagedResultIterator("events", $this->get_auth_headers(), $url, $this->url_params($params));
    }

    public function free_busy($params)
    {
        /*
          Date from : The minimum date from which to return free-busy information. Defaults to 16 days in the past. OPTIONAL
          Date to : The date to return free-busy information up until. Defaults to 201 days in the future. OPTIONAL
          String tzid : A string representing a known time zone identifier from the IANA Time Zone Database. REQUIRED
          Boolean include_managed : Indiciates whether events that you are managing for the account should be included or excluded from the results. Defaults to include only non-managed events. OPTIONAL
          Array calendar_ids : Restricts the returned free-busy information to those within the set of specified calendar_ids. Defaults to returning free-busy information from all of a user's calendars. OPTIONAL
          Boolean localized_times : Indicates whether the free-busy information should have their start and end times returned with any available localization information. Defaults to returning start and end times as simple Time values. OPTIONAL

          returns $result - Array of events
         */
        $url = $this->api_url("/" . self::API_VERSION . "/free_busy");

        return new PagedResultIterator("free_busy", $this->get_auth_headers(), $url, $this->url_params($params));
    }

    public function upsert_event($params)
    {
        /*
          calendar_id : The calendar_id of the calendar you wish the event to be added to. REQUIRED
          String event_id : The String that uniquely identifies the event. REQUIRED
          String summary : The String to use as the summary, sometimes referred to as the name, of the event. REQUIRED
          String description : The String to use as the description, sometimes referred to as the notes, of the event. REQUIRED
          String tzid : A String representing a known time zone identifier from the IANA Time Zone Database. OPTIONAL
          Time start: The start time can be provided as a simple Time string or an object with two attributes, time and tzid. REQUIRED
          Time end: The end time can be provided as a simple Time string or an object with two attributes, time and tzid. REQUIRED
          String location.description : The String describing the event's location. OPTIONAL


          returns true on success, associative array of errors on failure
         */
        $postfields = array(
            'event_id' => $params['event_id'],
            'summary' => $params['summary'],
            'description' => $params['description'],
            'start' => $params['start'],
            'end' => $params['end']
        );

        if (!empty($params['tzid'])) {
            $postfields['tzid'] = $params['tzid'];
        }
        if (!empty($params['location']['description'])) {
            $postfields['location']['description'] = $params['location']['description'];
        }

        return $this->http_post("/" . self::API_VERSION . "/calendars/" . $params['calendar_id'] . "/events", $postfields);
    }

    public function delete_event($params)
    {
        /*
          calendar_id : The calendar_id of the calendar you wish the event to be removed from. REQUIRED
          String event_id : The String that uniquely identifies the event. REQUIRED

          returns true on success, associative array of errors on failure
         */
        $postfields = array('event_id' => $params['event_id']);

        return $this->http_delete("/" . self::API_VERSION . "/calendars/" . $params['calendar_id'] . "/events", $postfields);
    }

    public function delete_all_events()
    {
        /*
          returns true on success, associative array of errors on failure
         */
        $postfields = array('delete_all' => true);

        return $this->http_delete("/" . self::API_VERSION . "/events/", $postfields);
    }

    public function create_channel($params)
    {
        /*
          String callback_url : The URL that is notified whenever a change is made. REQUIRED

          returns $result - Details of new channel. Details are available in the Cronofy API Documentation
        */
        $postfields = array('callback_url' => $params['callback_url']);

        return $this->http_post("/" . self::API_VERSION . "/channels", $postfields);
    }

    public function list_channels()
    {
        /*
          returns $result - Array of channels. Details are available in the Cronofy API Documentation
         */
        return $this->http_get("/" . self::API_VERSION . "/channels");
    }

    public function close_channel($params)
    {
        /*
          channel_id : The ID of the channel to be closed. REQUIRED

          returns $result - Array of channels. Details are available in the Cronofy API Documentation
         */
        return $this->http_delete("/" . self::API_VERSION . "/channels/" . $params['channel_id']);
    }

    public function delete_external_event($params)
    {
        /*
          calendar_id : The calendar_id of the calendar you wish the event to be removed from. REQUIRED
          String event_uid : The String that uniquely identifies the event. REQUIRED

          returns true on success, associative array of errors on failure
         */
        $postfields = array('event_uid' => $params['event_uid']);

        return $this->http_delete("/" . self::API_VERSION . "/calendars/" . $params['calendar_id'] . "/events", $postfields);
    }

    public function authorize_with_service_account($params)
    {
        /*
          email : The email of the user to be authorized. REQUIRED
          scope : The scopes to authorize for the user. REQUIRED
          callback_url : The URL to return to after authorization. REQUIRED
         */
        if (isset($params["scope"]) && gettype($params["scope"]) == "array") {
            $params["scope"] = join(" ", $params["scope"]);
        }

        return $this->http_post("/" . self::API_VERSION . "/service_account_authorizations", $params);
    }

    public function elevated_permissions($params)
    {
        /*
          permissions : The permissions to elevate to. Should be in an array of `array($calendar_id, $permission_level)`. REQUIRED
          redirect_uri : The application's redirect URI. REQUIRED
         */
        return $this->http_post("/" . self::API_VERSION . "/permissions", $params);
    }

    public function create_calendar($params)
    {
        /*
          profile_id : The ID for the profile on which to create the calendar. REQUIRED
          name : The name for the created calendar. REQUIRED
         */
        return $this->http_post("/" . self::API_VERSION . "/calendars", $params);
    }

    private function api_url($path)
    {
        return self::API_ROOT_URL . $path;
    }

    private function url_params($params)
    {
        if (count($params) == 0) {
            return "";
        }
        $str_params = array();

        foreach ($params as $key => $val) {
            if(gettype($val) == "array"){
                for($i = 0; $i < count($val); $i++){
                    array_push($str_params, $key . "[]=" . urlencode($val[$i]));
                }
            } else {
                array_push($str_params, $key . "=" . urlencode($val));
            }
        }

        return "?" . join("&", $str_params);
    }

    private function get_auth_headers($with_content_headers = false)
    {
        $headers = array();

        $headers[] = 'Authorization: Bearer ' . $this->access_token;
        $headers[] = 'Host: api.cronofy.com';

        if ($with_content_headers) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }

        return $headers;
    }

    private function parsed_response($response)
    {
        $json_decoded = json_decode($response, true);

        if (json_last_error() != JSON_ERROR_NONE) {
            return $response;
        }

        return $json_decoded;
    }

    private function handle_response($result, $status_code)
    {
        if ($status_code >= 200 && $status_code < 300) {
            return $this->parsed_response($result);
        }

        throw new CronofyException($this->http_codes[$status_code], $status_code, $this->parsed_response($result));
    }

    private $http_codes = array(
      100 => 'Continue',
      101 => 'Switching Protocols',
      102 => 'Processing',
      200 => 'OK',
      201 => 'Created',
      202 => 'Accepted',
      203 => 'Non-Authoritative Information',
      204 => 'No Content',
      205 => 'Reset Content',
      206 => 'Partial Content',
      207 => 'Multi-Status',
      300 => 'Multiple Choices',
      301 => 'Moved Permanently',
      302 => 'Found',
      303 => 'See Other',
      304 => 'Not Modified',
      305 => 'Use Proxy',
      306 => 'Switch Proxy',
      307 => 'Temporary Redirect',
      400 => 'Bad Request',
      401 => 'Unauthorized',
      402 => 'Payment Required',
      403 => 'Forbidden',
      404 => 'Not Found',
      405 => 'Method Not Allowed',
      406 => 'Not Acceptable',
      407 => 'Proxy Authentication Required',
      408 => 'Request Timeout',
      409 => 'Conflict',
      410 => 'Gone',
      411 => 'Length Required',
      412 => 'Precondition Failed',
      413 => 'Request Entity Too Large',
      414 => 'Request-URI Too Long',
      415 => 'Unsupported Media Type',
      416 => 'Requested Range Not Satisfiable',
      417 => 'Expectation Failed',
      418 => 'I\'m a teapot',
      422 => 'Unprocessable Entity',
      423 => 'Locked',
      424 => 'Failed Dependency',
      425 => 'Unordered Collection',
      426 => 'Upgrade Required',
      449 => 'Retry With',
      450 => 'Blocked by Windows Parental Controls',
      500 => 'Internal Server Error',
      501 => 'Not Implemented',
      502 => 'Bad Gateway',
      503 => 'Service Unavailable',
      504 => 'Gateway Timeout',
      505 => 'HTTP Version Not Supported',
      506 => 'Variant Also Negotiates',
      507 => 'Insufficient Storage',
      509 => 'Bandwidth Limit Exceeded',
      510 => 'Not Extended'
    );
}

class PagedResultIterator
{
  private $items_key;
  private $auth_headers;
  private $url;
  private $url_params;

  public function __construct($items_key, $auth_headers, $url, $url_params){
    $this->items_key = $items_key;
    $this->auth_headers = $auth_headers;
    $this->url = $url;
    $this->url_params = $url_params;
    $this->first_page = $this->get_page($url, $url_params);
  }

  public function each(){
    $page = $this->first_page;

    for($i = 0; $i < count($page[$this->items_key]); $i++){
      yield $page[$this->items_key][$i];
    }

    while(isset($page["pages"]["next_page"])){
      $page = $this->get_page($page["pages"]["next_page"]);

      for($i = 0; $i < count($page[$this->items_key]); $i++){
        yield $page[$this->items_key][$i];
      }
    }
  }

  private function get_page($url, $url_params=""){
    $curl = curl_init();

    curl_setopt($curl, CURLOPT_URL, $url.$url_params);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $this->auth_headers);
    curl_setopt($curl, CURLOPT_USERAGENT, Cronofy::USERAGENT);
    $result = curl_exec($curl);
    if (curl_errno($curl) > 0) {
      throw new CronofyException(curl_error($curl), 2);
    }

    return json_decode($result, true);
  }
}
